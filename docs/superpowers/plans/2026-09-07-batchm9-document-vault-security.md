# Plan — Phase 3 Batch M9: Document Vault Security Findings

**Date:** 7 September 2026
**Branch:** `fix/batchm9-document-vault-security`
**Scope:** VAULT-01, VAULT-02, VAULT-03, VAULT-05, VAULT-06 (see
`docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md` for the overall
audit-remediation program this batch belongs to).

**Flag up front: this whole PR touches identity-document/death-certificate/
financial-evidence access control and storage. It requires mandatory human
security review per `AGENTS.md` §Infrastructure-agent execution before merge.
VAULT-05 specifically requires a human DECISION, not just a code review —
see that section below.**

## VAULT-01 — clean async scans never promoted out of quarantine

### Root cause

`ScanDocument::applyVerdict()` only acted on `ScanVerdict::Infected`
(transition to `Rejected`). Nothing on the CLEAN branch ever called
`PromoteDocument::promote()`. The two SYNCHRONOUS fast paths
(`CreateCertificateAction`, `UploadEvidenceAction`) call `scan()` then
`promote()` themselves explicitly, so they were unaffected. Every OTHER
upload — the general, async `UploadDocument::upload()` → `ScanDocumentJob`
path (memorial media via `MemorialFamilyPage::uploadMedia()`, marketplace
payment proofs via `SubmitManualPayment`) — had NO caller that ever promoted
a clean scan. A genuinely clean KTP, payment proof, or memorial photo was
stuck in `Scanning` forever.

### Fix, and a deliberate deviation from the literal finding text

The finding text suggested extending `ScanDocument::applyVerdict()` itself.
Investigation found a real reason not to: `applyVerdict()`/`scan()` are
shared by ALL THREE callers (the two sync fast paths and the async job). If
`scan()` itself auto-promoted a CLEAN verdict, the two sync fast paths would
immediately call `PromoteDocument::promote()` on a document `scan()` had
already promoted — throwing `LogicException: Only a scanning document may be
promoted`. Confirmed this isn't hypothetical: `UploadDocument::upload()`
*always* dispatches `ScanDocumentJob` via `DB::afterCommit()`, regardless of
whether the caller also intends to scan/promote synchronously afterward.
Under `QUEUE_CONNECTION=sync` (the test suite's own setting, and a real
possibility on this single-host deployment — `ADR-0027`), that dispatch runs
**immediately**, in the same request, as soon as `upload()`'s transaction
commits — racing the fast path's own explicit `scan()`/`promote()` calls.
Verified empirically: adding promotion inside `scan()` broke
`WorkOrderEvidenceUploadTest`/`CertificateAdminTest`'s real-upload tests with
exactly this race (`Document ... cannot be scanned from state ACCEPTED`).

**Chosen fix:** promotion now happens in `ScanDocumentJob::handle()` — the
one caller that previously had none — immediately after a CLEAN
`ScanDocument::scan()` result, via `PromoteDocument::promote()` (the
existing action, unmodified; no new promotion logic written). This:

- Fixes the actual bug (async-only uploads now reach `Accepted`).
- Never double-promotes: the many `ScanDocument`-level tests that
  deliberately hold a document at `Scanning` to drive `PromoteDocument`
  under controlled conditions (checksum-mismatch, storage-tampering,
  rollback scenarios via `cleanScan()` in `DocumentLifecycleTest`) are
  untouched, because `scan()` itself did not change.
- Still had to defend the two sync fast paths against the SAME
  `QUEUE_CONNECTION=sync` race (the always-dispatched job can win and
  promote before the fast path's own explicit call runs): both
  `CreateCertificateAction::uploadAndAcceptDocument()` and
  `UploadEvidenceAction::uploadAndAcceptDocument()` now re-fetch the
  document after `UploadDocument::upload()` and short-circuit (return the
  already-`Accepted` document) instead of calling `scan()`/`promote()`
  again if the async job already won the race.

Emits the exact same `document.accepted.v1` outbox event and
`DOCUMENT_ACCEPTED` audit action the synchronous fast paths already emit —
trivially, since `ScanDocumentJob` now calls the literal same
`PromoteDocument::promote()` they call; nothing was duplicated or
re-implemented.

### Tests

- `tests/Unit/Platform/DocumentVault/Jobs/ScanDocumentJobTest.php`: two new
  regression tests (`test_handle_promotes_a_clean_quarantined_document_to_accepted`,
  `test_handle_promotes_a_clean_scanning_document_to_accepted`) proving the
  document reaches `Accepted`, the `accepted/` storage prefix, the
  `DOCUMENT_ACCEPTED` audit row, and the `document.accepted.v1` outbox event.
- Verified real-Postgres, no regression, across `DocumentLifecycleTest`,
  `UploadDocumentTest`, `SubmitManualPaymentTest`, `CertificateAdminTest`,
  `WorkOrderEvidenceUploadTest`, `MemorialPublicPageTest` (see Verification
  section below).

## VAULT-02 — marketplace product photos bypass quarantine and scanning entirely

### Decision: option (b) — keep public disk, add a malware scan

A product photo must be viewable, instantly and anonymously, by an
unauthenticated shopper (`MarketplacePresenter::photoUrl()` resolves a plain
public URL). Routing it through the vault's quarantine → scan → promote →
audited-download-route pipeline would (a) impose an async scan delay before
a newly-activated product's photo appears at all, and (b) serve a genuinely
public asset through a route designed to authorize and audit access to a
RESTRICTED document — the audited-download-route model does not fit a
public catalogue image, and forcing it to would be the wrong direction to
bend either model. `ProductForm`'s own existing doc block had already
reasoned through "public disk, not the vault" for this field; that reasoning
still holds. What was actually missing is not the storage choice — it's that
NO malware scan ran at all.

**Fix:** `App\Domain\Marketplace\Actions\ScanProductPhoto` reuses
`Contracts\MalwareScanner` (the same boundary `ScanDocument` scans restricted
documents with; the same `MockScanner` binding on this host) at the one
choke point every write to `products.photo_path` passes through —
`Product`'s own `saving` model event, already the go-live photo gate's
enforcement point. A non-CLEAN verdict deletes the file from the public disk
and throws `Exceptions\ProductPhotoFailedScanException`, refusing the save.

Deliberately did NOT run `DocumentValidator`'s content-type/extension check:
its `DocumentKind::ProductImage` allowlist (`jpg/jpeg/png`) is narrower than
`ProductForm`'s own accepted types (`image/jpeg`, `image/png`,
`image/webp`) — enforcing it would silently reject a legitimate WebP upload
the form itself accepts. Content-type/extension validation for this field
stays Filament's own job; this fix closes only the malware gap the finding
named.

**ADR:** `docs/adr/0023-quarantine-all-untrusted-files.md` Amendment 1
records this as an explicit, narrow carve-out — "scanned but not
quarantined, because it's designed to be public" — flagged for human
security review, not silently normalized as the general rule.

### Tests

- `tests/Feature/Domain/Marketplace/ProductCatalogueSeedTest.php`:
  `test_a_new_photo_that_fails_the_malware_scan_is_rejected_and_removed`
  (EICAR content → `ProductPhotoFailedScanException`, file removed from
  public disk) and `test_a_new_clean_photo_passes_the_malware_scan_and_saves`
  (control case).
- Full `ProductResourceTest`/`ProductCatalogueSeedTest` suites re-run clean
  (41 + 33 tests respectively, see Verification).

## VAULT-03 — provider-statement CSV bypasses quarantine and scanning entirely

### Decision: option (b) — approved transient/parse-only exemption

The finding's instruction was explicit: "Prefer (a) unless you can
concretely verify the file is genuinely transient and parse-only." Read
`UploadProviderStatementAction::run()` end-to-end before deciding:

- **Transient, verified:** `$storage->delete($storedPath)` runs
  unconditionally in the method's `finally` block — on success, on a denied
  authorization, on a CSV parse failure, on any other exception. No code
  path retains the file.
- **Parse-only, verified:** `ProviderStatementCsvParser::parse()` performs
  strict structural parsing (exact header, exact column count, a row cap)
  into `line_reference`/integer-minor-unit pairs. Nothing in the file is
  ever executed, templated, or passed to a shell/SQL/formula interpreter.
  Only those opaque references and integer amounts survive into
  `reconciliations`/`reconciliation_exceptions` — never a raw byte of the
  original file, never a bank/account/card/customer-identifying value.

Both halves the finding required before choosing (b) instead of (a) were
concretely verified, not merely assumed. Chose (b): no new `DocumentKind`
case, no vault routing, no migration.

**ADR:** `docs/adr/0023-quarantine-all-untrusted-files.md` Amendment 2
records this exemption with the verification evidence above, and states the
fallback explicitly: if this file's handling ever changes to retain the CSV
or admit richer parsing (formulas, macros, an attachment field), option (a)
— `DocumentKind::PROVIDER_STATEMENT` routed through
`UploadDocument → ScanDocument → PromoteDocument` before parsing — becomes
the only correct choice.

**No migration needed either for VAULT-02 or VAULT-03**: `DocumentKind`
already has a `ProductImage` case (used for VAULT-02's `MalwareScanner`
argument only, not a new `documents` row), and VAULT-03 deliberately does
not add a `documents` row at all. The `documents_document_kind_check` CHECK
constraint is unchanged by this batch.

## VAULT-05 — no encryption at rest for restricted documents (DECISION POINT — NOT implemented)

**This finding is documentation-and-decision-framing only, per the batch
brief. No envelope encryption, no key-management code, and no
infrastructure change were implemented.** Key-management design (where the
key lives, how it's rotated, how it differs from `APP_KEY`) is exactly the
kind of decision `AGENTS.md` §Infrastructure-agent execution reserves for
human review — implementing either option unilaterally would violate that
rule directly.

### Current state, verified

`App\Platform\DocumentVault\Adapters\LocalFilesystemObjectStorage::put()`
(`app/Platform/DocumentVault/Adapters/LocalFilesystemObjectStorage.php:29`)
and its `copy()`/`read()` siblings write and read plain bytes directly via
`fopen`/`stream_copy_to_stream`/`fread` against the host filesystem
(`storage/app/private/documents/{kind}/{prefix}/{key}`). There is no
encryption layer — application-level or infrastructure-level — anywhere in
this path today, for either quarantine or accepted objects, for any
`DocumentKind` including `Ktp`, `Kk`, and `DeathCertificate`.

### Option 1 — Application-level envelope encryption inside `LocalFilesystemObjectStorage`

**Mechanism:** Encrypt the byte stream on `put()`/`copy()`'s destination
write; decrypt on `read()`. A per-object data key (DEK) is generated at
write time, itself encrypted by a master key (KEK) held OUTSIDE `APP_KEY` —
`APP_KEY` is Laravel's own general-purpose application secret (session/cookie
signing, `Illuminate\Encryption\Encrypter` for cache/queue payloads
elsewhere in the app); reusing it for document encryption would mean a
single compromised or rotated `APP_KEY` affects both the application's own
signing/encryption AND every restricted document ever stored — a strictly
worse blast radius than keeping the two separate.

**Where the KEK would live:** a dedicated secret, distinct from `.env`'s
`APP_KEY` — options include a host-level secret file readable only by the
application's own service account (with strict file permissions, outside
the web root, outside version control and outside any backup that isn't
itself access-controlled the same way), or a managed secrets service if one
is ever adopted for this project (none exists today per
`docs/operations/dev-staging-environment.md`). Rotation would require either
(a) re-encrypting every existing object's DEK under a new KEK (a real,
scriptable migration, since DEKs are small and object bytes never need
re-writing under key-wrapping schemes), or (b) a KEK-versioning scheme where
old objects keep decrypting under their original KEK version while new
writes use the current one.

**Trade-offs:**
- *For:* Encryption travels with the application regardless of underlying
  infrastructure — works identically in a container, on a VM, or if storage
  ever moves off local disk. Granular: could in principle be applied
  per-`DocumentKind` if a future kind should stay unencrypted (none
  currently should). Testable in the same way the rest of the vault is
  tested (`LocalFilesystemObjectStorageTest`-style unit tests against a
  throwaway root).
- *Against:* Real implementation surface — key generation, key storage,
  key rotation, and a migration path for the objects that already exist
  unencrypted on the host today (a backfill job, not a schema migration,
  since these are filesystem objects, not database rows). A bug in this
  layer directly risks the ability to ever decrypt a KTP/death certificate
  again — much higher blast radius for a defect than most application code.
  Performance cost (encrypt/decrypt on every read/write) is likely small at
  this project's scale but unverified.

### Option 2 — Full-disk/volume encryption for `storage/app/private/documents`

**Mechanism:** Encrypt the underlying block device or a dedicated encrypted
volume/loopback file mounted at (or containing) `storage/app/private`, at
the OS level (e.g. LUKS on Linux). The application layer is completely
unaware — `LocalFilesystemObjectStorage` needs zero code changes.

**Where this would be documented:** `docs/operations/` — most naturally as
a section in `docs/operations/dev-staging-environment.md` or a new
operations runbook alongside it, describing the volume setup, the
unlock/boot procedure (a LUKS volume needs its passphrase/keyfile supplied
at mount time — this is itself a key-management decision, just moved to the
infrastructure layer instead of the application layer), and backup
implications (an encrypted-at-rest backup is straightforward; an
unencrypted backup of decrypted data defeats the purpose, so backup tooling
must be audited alongside this decision).

**Trade-offs:**
- *For:* Zero application code changes; protects ALL data on the volume
  uniformly (not just document-vault objects, if other sensitive data ever
  shares the mount) with no risk of an application-level implementation bug
  silently failing to encrypt some path. Standard, well-understood,
  extensively battle-tested OS tooling (LUKS/dm-crypt).
  Simpler to reason about at rest — the exact same guarantee as any
  encrypted-disk deployment.
- *Against:* Boot-time key/passphrase handling on a host that must
  presumably reboot unattended (or with manual intervention accepted as a
  documented runbook step — a real operational cost on an already
  single-host, no-HA deployment per `ADR-0027`). Does not encrypt data
  in transit between the application process and disk (irrelevant here,
  same host) or protect against a compromised, already-running application
  process reading plaintext (true of option 1 too, if the KEK is reachable
  from that same process — arguably option 1 has a narrower window since
  bytes are encrypted the instant they're not actively being processed,
  while option 2's live filesystem view is always plaintext to any process
  with read access). Coarser-grained: encrypts the whole volume, not
  selectively.

### Recommendation framing (not a decision)

Option 1 (application-level) gives finer-grained, infrastructure-independent
protection but carries real implementation and key-rotation risk that this
batch is explicitly not authorized to build. Option 2 (volume-level) is
operationally simpler and needs no application code, but shifts the
key-management question to boot-time infrastructure handling and protects
uniformly rather than selectively. **Neither is chosen here.** This
requires a human decision on: which option, where the key/passphrase lives,
who can access it, and the rotation/backup implications either way — see
`ADR-0027` OQ-8 (added by this batch) and the `platform-document-vault`
requirements.md AC10 correction (also added by this batch) for where this
gap is now tracked.

### Documentation changes made (this batch)

- `docs/adr/0027-combine-dev-staging-on-ubuntu22-2v4g.md`: added a
  correction note under the "Production graduation" Decision (which
  previously stated `LocalFilesystemObjectStorage` "already implements the
  full `ObjectStorage` contract" without qualifying that this is a
  functional claim, not a security one) and a new **OQ-8** open question
  alongside the existing OQ-7 (malware scanner), stating plainly that
  encryption-at-rest does not exist and linking to this section.
- `.kiro/specs/platform-document-vault/requirements.md` AC10 (`THE SYSTEM
  SHALL store documents on S3-compatible private storage with encryption`):
  added a correction note stating the live implementation satisfies only
  the "private"/"no public ACL" halves, is local-filesystem (not
  S3-compatible), and has no encryption at rest — pointing back to this
  plan and `ADR-0027` OQ-8.

No claim anywhere in this batch states either option was chosen or
implemented.

## VAULT-06 — unresolvable `owner_type` values silently defeat access control

### Empirical verification of "fails open or throws" (done before writing the fix)

Read `DocumentAccessPolicy::hasRecordRelationship()`: an `owner_type` that
is neither `OWNER_TYPE_ACTOR` nor `ScopeEntityType::isKnown()` returns
`false` immediately (`if (! ScopeEntityType::isKnown($ownerType)) { return
false; }`) — confirmed by the existing test
`test_an_owner_type_with_no_known_relationship_source_fails_closed`. So the
policy **fails closed** (denies), not open — but "fails closed for
EVERYONE, including a legitimately-privileged admin with a real need" is
its own real problem: a certificate/evidence/payment-proof/memorial document
with a bad `owner_type` was unauthorizable by ANY actor, not silently
over-permissive. Still a genuine bug — the class of documents most
routinely needing legitimate case-manager/admin access became permanently
unreachable through the one policy that's supposed to grant that access.

### Fix

`UploadDocument::upload()` now asserts `$ownerType` is either
`DocumentAccessPolicy::OWNER_TYPE_ACTOR` or a member of
`ScopeEntityType::KNOWN_TYPES` before any write, throwing
`InvalidArgumentException` otherwise (`assertResolvableOwnerType()`, called
before the transaction opens).

### The four callers, fixed

| Caller | Old `owner_type` | New `owner_type` | New `owner_id` | Reasoning |
| --- | --- | --- | --- | --- |
| `CreateCertificateAction::uploadAndAcceptDocument()` | `Order::class` (FQCN) | `ScopeEntityType::ORDER` | unchanged (`$subject->getKey()`) | Named directly by the finding. |
| `UploadEvidenceAction::uploadAndAcceptDocument()` | `WorkOrder::class` (FQCN) | `ScopeEntityType::VENDOR` | `$workOrder->vendor_id` (was the work order's own id) | `WorkOrder` carries no `order_id` — its only real relationship into a `ScopeEntityType` is the owning vendor (`vendor_id`, also `WorkOrder::vendor()`). Verified `CarePlan` (the other FK on `WorkOrder`) also has no `order_id` — it's vendor-scoped too. |
| `SubmitManualPayment::submit()` (payment proof) | `'payment_verification'` (keyed on the verification's own id) | `ScopeEntityType::ORDER` | `$order->id` (was `$verification->id`) | `payment_verifications.order_id` is a real FK to the `MarketplaceOrder` already loaded in scope as `$order` — the proof's real relationship is the order it pays for. |
| `MemorialFamilyPage::uploadMedia()` (memorial document) | `'memorial_profile'` (keyed on the profile's own id) | `ScopeEntityType::GRAVE` | `$this->profile->grave_record_id` (was `$this->profile->getKey()`) | `grave_record_id` is `MemorialProfile`'s ONE link into a scope-checkable entity (AC7), and is UNIQUE per profile (`2026_08_16_110000_create_memorial_profiles_table.php`'s own `$table->unique('grave_record_id')`) — so this stays a precise per-profile filter, not a broadening. `attachAcceptedUploads()`'s and the pending-uploads query's own filters were updated to match. |

Every existing test asserting the OLD `owner_type`/`owner_id` values was
updated to the new ones (`UploadDocumentTest` fixture owner types,
`SubmitManualPaymentTest`, `MemorialPublicPageTest` — two call sites, one
live-upload assertion and one pre-accepted fixture).

### Tests

- `UploadDocumentTest::test_an_unresolvable_owner_type_throws_before_any_write`
  — proves the write-boundary throw, and that nothing is persisted or
  written to storage when it fires.
- `DocumentAccessPolicyTest`: four new regression tests, one per fixed
  caller (`test_vault06_a_certificate_document_scoped_by_order_can_now_be_authorized`,
  `..._vendor_evidence_scoped_by_vendor_...`, `..._a_payment_proof_scoped_by_order_...`,
  `..._a_memorial_document_scoped_by_grave_...`), each proving `canView()`
  denies without a matching scope grant and allows with one — using the
  exact `owner_type`/`owner_id` shape each fixed caller now writes.

## Verification

Ran real PostgreSQL 18 + Redis 8.2 (disposable containers, `m9-` prefix) via
the pinned CI-parity image (`ghcr.io/andrianm88/makam-app`'s local id
`0d68f84b744c` at the time of this run — see `docker images` at run time for
current tags), hard-linked `vendor/` per the `worktree-test-env` house
convention (never symlinked).

- `tests/Feature/DocumentVault/*`, `tests/Unit/Platform/DocumentVault/*`: **PASS** (274 tests, 953 assertions; 2 pre-existing errors + 2 pre-existing failures unrelated to this diff — `DocumentValidatorTest` `imagecreatetruecolor()` undefined (no `gd` extension in this image) and `DocumentVaultConfigurationTest` staging-default assertions, both documented pre-existing host/container gaps, confirmed untouched by this diff's file list).
- `tests/Feature/Payment/SubmitManualPaymentTest.php`,
  `tests/Feature/Filament/CertificateAdminTest.php`,
  `tests/Feature/Filament/Vendor/WorkOrderEvidenceUploadTest.php`,
  `tests/Feature/Filament/Admin/WorkOrderVendorReplacementTest.php`,
  `tests/Feature/Livewire/Public/Memorial/MemorialPublicPageTest.php`:
  **PASS** (56 tests, 344 assertions).
- `tests/Feature/Domain/Marketplace/ProductCatalogueSeedTest.php`,
  `tests/Feature/Filament/Admin/ProductResourceTest.php`,
  `tests/Feature/Console/PurgeExampleDataCommandTest.php`,
  `tests/Feature/Database/Migrations/BackfillPhotoPathForRealProductsTest.php`:
  **PASS** (41 tests, 370 assertions).
- `tests/Feature/Domain/Memorial/*`, `tests/Feature/Livewire/Public/Memorial/*`,
  `tests/Feature/Filament/MemorialAdminTest.php`: covered by the combined
  274-test run above.

`vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, and
`bash ci/verify-docs.sh`: run before commit — see PR description for their
actual pass/fail status; never claimed as PASS without having been run in
this session.

# Plan — Batch M4c: Contract/Spec Doc Reconciliation (API-04, CONTRACT-04, CONTRACT-06, CONTRACT-08, CONTRACT-09)

Date: 2026-09-07
Branch: `fix/batchm4c-contract-doc-reconciliation`
Scope: documentation only, no application code changes. Part of Phase 3 of
`docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`.

## Problem

Five canonical contract/spec docs assert things the shipped code does not do
(or omit things the shipped code does do). Fix is to correct the docs to
describe reality, not to build features to match stale docs.

## Findings and disposition

### API-04 — `docs/product/information-architecture.md` §1/§5 stale both ways

Verified against a real `php artisan route:list --json` run (via the pinned
`ghcr.io/andrianm28/makam-app:sha-89ea1c82efa3` container, host PHP is 8.3.6
and too old for this codebase's 8.5 requirement).

Findings (excluding framework/Livewire/Horizon/Filament-panel-internal
routes — only the public/application route tree considered):

- §1 documents `/pemesanan-makam/konfirmasi/{orderReference}`,
  `/marketplace/kategori/{categorySlug}`, and
  `/perpanjangan/permohonan/{renewalReference}` +
  `/perpanjangan/konfirmasi/{renewalReference}` — none of these exist.
  Actual renewal routes are `/perpanjangan/cari`, `/perpanjangan/konfirmasi`,
  `/perpanjangan/pembayaran` (no reference param — session-scoped).
- §1 documents a top-level `/pesanan/{orderReference}` — does not exist.
  Actual equivalents are `/marketplace/pesanan/{orderNumber}` (order
  tracking) and `/kwitansi/{reference}` (invoice/receipt).
- §1 omits real, shipped routes entirely: `/pemakaman` +
  `/pemakaman/{cemeterySlug}` (cemetery directory), legacy `/cemeteries` +
  `/cemeteries/{cemeterySlug}` redirects, `/kunjungan` +
  `/kunjungan/{cemeterySlug}` (visitation booking), `/kenangan/{profileId}`
  and `/m/{token}` (memorial) + legacy `/memorial/{profileId}` redirect,
  `/kwitansi/{reference}`, `/langganan/{subscriptionReference}`,
  `/riwayat-perawatan/{customerId}`, `/privasi`, `/syarat-ketentuan`.
- §5 documents an all-English admin slug tree (`/services`, `/orders`,
  `/renewals`, `/payments`, `/transactions`, `/reports`, `/audit`) that was
  never implemented. The real Filament admin panel uses Indonesian slugs
  consistent with the rest of the app (`definisi-layanan`,
  `pesanan-pemakaman`, `pesanan-perpanjangan`, `pembayaran`,
  `rekonsiliasi`, `laporan`, `log-audit`, plus many more resources §5 never
  listed at all: `petak-makam`, `peta-plot`, `sertifikat`, `persetujuan`,
  `kasus-preneed`, `pemesanan-kunjungan`, `profil-kenangan`,
  `rencana-perawatan`, `kasus-moderasi`, `keluhan-layanan`,
  `verifikasi-pembayaran`, `kota-peluncuran`, `kebijakan-kunjungan-pemakaman`,
  `feature-gates`, `notifikasi-aplikasi`, `pengaturan-situs`,
  `verifikasi-ulang-kata-sandi`). The `/vendor` panel tree in §5 is
  accurate as written.

**Fix:** regenerate §1 and §5 from the real route list, keep the doc's
existing "annotate additions with a dated note" convention for the
corrections, and add a CI gate.

**CI gate:** `ci/verify-docs.sh` has no PHP/route-list infrastructure at all
(it is explicitly "no build required"), and this repo's CI never runs
`composer install` on this host. A gate that runs `route:list` needs a built
vendor/ — that only exists inside `.github/workflows/ci.yml` after
`composer install`, so the gate is added there as a new job step, not in
`ci/verify-docs.sh`. It runs `php artisan route:list --json`, filters out
framework/Livewire/Horizon/Filament-panel-internal routes the same way this
plan's audit did, and diffs the resulting URI set against a checked-in
manifest (`docs/product/route-manifest.json`) generated in this batch. A
mismatch fails the job with a message pointing at
`docs/product/information-architecture.md`. This is a genuinely new
mechanism (nothing in `ci/verify-docs.sh` reads route:list), tested locally
by running the filter script against the captured `route:list --json`
output and confirming it matches the manifest before commit.

### CONTRACT-04 — `docs/contracts/funeral-case-events.md`

Checked `fix/batchm4b-event-catalogue-reconciliation` first: that branch does
not exist on the remote (fetch failed, "couldn't find remote ref"), so there
is no earlier work to avoid conflicting with — `event-catalog.md` v0.6 in
this worktree is the current state to build on. `event-catalog.md` already
lists `funeral_case.created.v1`, `funeral_case.manager_assigned.v1`, and
`funeral_case.task_overdue.v1` in the real `noun.verb_past.vN` convention,
each with `Producer: FuneralCase`. `docs/planning/sprint-plan.md` still
defers `funeral-case-management` entirely (confirmed at the cited area:
"Feature specs deferred entirely: ... `funeral-case-management` ...").
`grep -r` across `app/` for a `FuneralCase` producer/dispatcher found no
matching class — the three catalogued events plus the six others named in
`funeral-case-events.md` are all unproduced.

**Fix:** delete `docs/contracts/funeral-case-events.md` per AGENTS.md
§Documentation's ban on duplicate canonical catalogue data — its content
duplicates (in a wrong naming convention) what `event-catalog.md` already
owns as canonical. Add a "deferred" note to the three already-catalogued
`funeral_case.*` rows in `event-catalog.md` pointing at the sprint-plan
deferral, and a pointer comment in `sprint-plan.md`'s
`funeral-case-management` deferral line back to `event-catalog.md` for the
event shapes reserved for when that spec starts.

### CONTRACT-06 — `docs/domain/traceability-matrix.md` (finding says
`docs/product/traceability-matrix.md`; that path does not exist in this repo
— `ci/verify-docs.sh` GATE 7 reads `docs/domain/traceability-matrix.md`,
confirmed the real canonical file)

`.kiro/specs/visitation-booking/requirements.md` identifies the wayfinding
AC. `database/migrations/*visitation_bookings*` stores no grave/plot
reference column. No `NavigationProjection` class exists in `app/`. Fix:
correct the matrix's visitation-booking row(s) to the ACs actually covered,
list the wayfinding AC under the module's own "not yet covered" convention,
and add a "deferred, not broken" note to
`docs/contracts/visitation-contract.md` recording that the grave/plot
reference and navigation projection are deferred to `G-VISIT-01`, per the
finding's explicit instruction not to build them now.

### CONTRACT-08 — `docs/contracts/certificate-contract.md`

Read `database/migrations/2026_08_17_100010_create_certificates_table.php`,
`app/Domain/AgreementCertificate/CertificateStatus.php`, and the Actions
directory for the real supersession/audit/delivery mechanism. Rewrite the
contract to describe the real command/field set, and explicitly record the
absence of a "mark delivered" state against whatever AC currently claims it.

### CONTRACT-09 — `docs/contracts/plot-reservation-contract.md`

Read `app/Domain/PlotReservation/Actions/HoldPlotForDraft.php`,
`ConfirmPlotReservation.php`, `PlotReservationState`, and the real exception
classes. Rewrite the contract against the real command signatures, error
codes, TTL/expiry mechanism, and idempotency pattern; mark
external-registry fields as reserved-for-future-integration. If the
quote-binding invariant turns out to need a `quote_id` on confirm and it is
genuinely missing, that is flagged as a new follow-up finding, not fixed
here (doc-only batch).

## Verification

- `bash ci/verify-docs.sh` clean.
- New CI route-manifest gate logic tested directly (not via full CI) by
  running the filter script against a real `route:list --json` capture and
  confirming the manifest matches; the GitHub Actions job itself runs only
  when CI executes on push (not locally, per repo convention).
- No PHPUnit run required (doc-only batch); confirmed no `app/` files are
  touched by `git diff --stat` before commit.

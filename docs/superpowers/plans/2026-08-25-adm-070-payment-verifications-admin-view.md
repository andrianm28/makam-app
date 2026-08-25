# ADM-070 — Payment Verifications Admin View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the real admin surface `docs/product/screen-inventory.md` names as ADM-070 ("Payment/transaction/manual verification") — a read-only Filament Resource listing every `payment_verifications` row, closing the traceability gap `docs/domain/traceability-matrix.md` and `docs/testing/release-gates.md` §A both currently record as genuinely unbuilt.

**Architecture:** One new read-only Filament Admin Resource (`PaymentVerificationsResource`, index + view pages only — no create/edit/delete), gated by the SAME `App\Platform\Payment\Contracts\PaymentActionAuthorizer` binding that already gates the manual-payment-verification write action, reused directly rather than duplicated into a new contract (see Global Constraints — there is no scoping dimension on this table to justify a separate read-scope object, and the write authorizer's role policy — finance/restricted_admin — is exactly the actor population that should be able to browse what it can decide). No new business logic, no new writes, no invented "linked order" resolution (the `reference` column is explicitly flagged in its own migration as free-text, not a foreign key, with real resolution deliberately left to a future task once `app/Domain/OrderWorkflow/` exists — this plan does not attempt that).

**Tech Stack:** Laravel 13, Filament 5, PostgreSQL 18, PHPUnit.

**Spec:** None — approved via a short bounded-design confirmation in chat (25 Aug 2026), not a written spec doc, matching this repo's brainstorming-skill "Bounded" path (existing flow in an existing codebase, short design description, explicit user approval before implementation — no spec file needed for this path).

## Global Constraints

- Every new/modified PHP file needs `declare(strict_types=1);`.
- **Read-only, no new writes.** `getPages()` registers ONLY `index` and `view` — never `create`/`edit`. Manual payment verification's actual decision stays exactly where it already lives (`VerifyManualPaymentController` / `App\Platform\Payment\VerifyManualPayment`) — this plan does not touch that write path at all.
- **Reuse `App\Platform\Payment\Contracts\PaymentActionAuthorizer` directly — do not create a new authorizer contract.** Confirmed via direct code read: `payment_verifications` has no scopeable column (`FinanceOrRestrictedAdminPaymentAuthorizer`'s own doc block: "nothing in either is in the `scope_assignments.entity_id` value space"), so a dedicated read-scope object (the pattern `LedgerReadAuthorizer`/`AuditReadAuthorizer` use) would carry no real narrowing value here — it would be pure ceremony. `PaymentActionAuthorizer::authorize(ActorContext $actor): string` already returns the approved role on success and throws `PaymentActionNotAuthorisedException` on refusal; that is sufficient for both `canAccess()`/`getAuthorizationResponse()` (mount/action gates) and an unfiltered `getEloquentQuery()` (no scope to apply).
- **No "linked order" column.** The `payment_verifications.reference` column is caller-supplied free text, explicitly NOT a foreign key (`2026_08_11_100000_create_payment_verifications_table.php`'s own doc block flags real FK resolution as a deliberate future task). Display `reference` as a plain string. Do not attempt to resolve it against `Order`/`MarketplaceOrder`/any other table — that would be inventing a resolution heuristic this codebase has explicitly deferred.
- **No new StatusIntent family.** `App\Support\Design\StatusIntent` requires status VALUES to be canonical in `docs/domain/order-lifecycle.md`/`docs/product/marketplace-catalog.md` (`AGENTS.md` §Documentation), and `PaymentVerificationStatus`'s three values (`SUBMITTED`/`VERIFIED`/`REJECTED`) are a deliberately separate, uncoupled state machine with no such canonical entry (confirmed via direct code read of `PaymentVerificationStatus`'s own doc block). Render status as plain text in the table/infolist, not a colored badge — adding a new StatusIntent family/updating canonical docs is out of scope for this plan.
- Indonesian URL slug, matching PR #160's already-merged URL-Indonesianization convention: `verifikasi-pembayaran` (distinct from the existing plain write-route path `pembayaran/verifikasi-manual/{id}/verifikasi`, which is a different, non-Filament route — no collision, but pick a visibly different slug to avoid confusing the two in navigation).
- Follow the file/naming structure `app/Filament/Admin/Resources/AuditEvents/` already establishes (`AuditEventsResource.php`, `Pages/ListAuditEvents.php`, `Pages/ViewAuditEvent.php`, `Tables/AuditEventsTable.php`, `Schemas/AuditEventInfolist.php`) — read that resource's real files first and match its structure exactly, adapted to `PaymentVerification`.
- Follow the test file/naming structure `tests/Feature/Filament/Admin/AuditEvents/` already establishes (`AuditEventsResourceAccessTest.php`, `AuditEventsTableTest.php`).
- No AWS, no changes to production-affecting/security/authorization/financial/DNS/firewall config beyond what's named above (registering a read-only Resource against an existing authorization binding is not a new authorization decision — it reuses one already reviewed and shipped).
- Composer/npm builds do not run on this host — CI only.
- Real Docker test-execution recipe (established this session): `docker run --network host --user 1000:1000 -e DB_CONNECTION=pgsql ... <pinned-image-digest> php -d memory_limit=512M vendor/bin/phpunit <paths>` against fresh disposable `postgres:18`/`redis:8.2-alpine` containers. Verify the pinned image digest is current before using it (check for a newer "Build and push image" CI run on `docs/design-system-and-planning` since this plan's base commit).
- `phpunit.xml` already sets `CACHE_STORE=array`/`SESSION_DRIVER=array` as test defaults — never override these to `redis` when running `vendor/bin/phpunit` directly (root-caused earlier this session: it leaks rate-limiter state across tests in one process).

---

### Task 1: `PaymentVerificationsResource` — read-only Filament admin surface

**Files:**
- Create: `app/Filament/Admin/Resources/PaymentVerifications/PaymentVerificationsResource.php`
- Create: `app/Filament/Admin/Resources/PaymentVerifications/Pages/ListPaymentVerifications.php`
- Create: `app/Filament/Admin/Resources/PaymentVerifications/Pages/ViewPaymentVerification.php`
- Create: `app/Filament/Admin/Resources/PaymentVerifications/Tables/PaymentVerificationsTable.php`
- Create: `app/Filament/Admin/Resources/PaymentVerifications/Schemas/PaymentVerificationInfolist.php`
- Test: `tests/Feature/Filament/Admin/PaymentVerifications/PaymentVerificationsResourceAccessTest.php`
- Test: `tests/Feature/Filament/Admin/PaymentVerifications/PaymentVerificationsTableTest.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php` ONLY if resources are registered explicitly there — check first; if this panel auto-discovers resources under `app/Filament/Admin/Resources/` (confirm by reading how `AuditEventsResource` gets registered — likely Filament's own directory-based auto-discovery, in which case no change is needed here at all).

**Interfaces:**
- Consumes: `App\Platform\Payment\Contracts\PaymentActionAuthorizer` (existing binding, `PaymentServiceProvider`), `App\Platform\Payment\Models\PaymentVerification` (existing model), `App\Platform\Payment\PaymentVerificationStatus` (existing enum), `App\Platform\IdentityAccess\ActorContext` (existing).
- Produces: nothing consumed by later tasks in this plan (Task 2 is documentation-only, reading this task's real, committed evidence, not its code).

- [ ] **Step 1: Read the real precedent files first**

Read these 5 files in full before writing anything: `app/Filament/Admin/Resources/AuditEvents/AuditEventsResource.php`, `.../Pages/ListAuditEvents.php`, `.../Pages/ViewAuditEvent.php`, `.../Tables/AuditEventsTable.php`, `.../Schemas/AuditEventInfolist.php`. This is the exact structural and authorization pattern to adapt — do not invent a different shape.

- [ ] **Step 2: `PaymentVerificationsResource.php`**

Adapt `AuditEventsResource`'s shape:
- `protected static ?string $model = PaymentVerification::class;`
- `protected static ?string $slug = 'verifikasi-pembayaran';` (see Global Constraints — Filament derives the admin route NAME from the slug too, per this session's own established Filament-slug-to-route-name finding; there is no existing hardcoded `route('filament.admin.resources.payment-verifications...')` call anywhere yet since this resource doesn't exist, so no rename-safety concern here, but confirm via `grep -rn "PaymentVerificationsResource" app/ tests/` before finishing that nothing else references a route name for this resource under a different assumption).
- `canAccess()`: call `app(PaymentActionAuthorizer::class)->authorize(app(ActorContext::class))` inside a try/catch on `PaymentActionNotAuthorisedException`, return `true`/`false` — same shape as `AuditEventsResource::canAccess()`.
- `getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response`: same try/catch shape, `Response::allow()` on success, `Response::deny('Anda tidak berwenang meninjau verifikasi pembayaran.')` on refusal.
- `getEloquentQuery(): Builder`: call the authorizer the same way; on refusal, `abort(403)` (matching `AuditEventsResource`'s fail-closed shape). On success, `PaymentVerification::query()->latest('submitted_at')` — no scope filter needed (see Global Constraints; there is nothing to filter on).
- `table()`/`infolist()`: delegate to `PaymentVerificationsTable::configure($table)` / `PaymentVerificationInfolist::configure($schema)`.
- `getPages()`: `'index' => ListPaymentVerifications::route('/'), 'view' => ViewPaymentVerification::route('/{record}'),` — no `create`/`edit`.
- `getModelLabel()`: `'verifikasi pembayaran'`; `getPluralModelLabel()`/`getNavigationLabel()`: `'Verifikasi Pembayaran'`.
- Pick a navigation icon distinct from other payment-adjacent resources already registered (check what icons `CertificatesResource`/`ReconciliationsResource` etc. use, avoid an exact duplicate — e.g. `Heroicon::OutlinedBanknotes` or `Heroicon::OutlinedReceiptPercent`, confirm the icon exists in the installed Heroicon enum before using it).

- [ ] **Step 3: `Pages/ListPaymentVerifications.php` and `Pages/ViewPaymentVerification.php`**

Mirror `ListAuditEvents.php`/`ViewAuditEvent.php` exactly — these are typically thin (`extends ListRecords`/`ViewRecord`, `protected static string $resource = PaymentVerificationsResource::class;`, no header actions since this is read-only).

- [ ] **Step 4: `Tables/PaymentVerificationsTable.php`**

Columns (plain `TextColumn`, no `->badge()`/`->color()` per Global Constraints — status renders as plain text):
- `reference` — searchable, sortable.
- `payment_method`.
- `payment_reference` — searchable.
- `status` — plain text (the enum's raw string value, e.g. via `->formatStateUsing(fn (string $state): string => PaymentVerificationStatus::from($state)->name)` or similar if a nicer label than the raw uppercase constant is wanted — keep it simple, raw value is acceptable).
- `submitted_at` — sortable, `->dateTime()`.
- `decided_at` — sortable, `->dateTime()`, nullable-safe (show "—" or similar when null, check how `AuditEventsTable` or a similar nullable-timestamp column elsewhere in this codebase handles this).
- Default sort: newest `submitted_at` first (matches `getEloquentQuery()`'s `latest('submitted_at')`).
- No bulk actions, no row actions (read-only).

- [ ] **Step 5: `Schemas/PaymentVerificationInfolist.php`**

Mirror `AuditEventInfolist.php`'s shape — a `Schema` with `TextEntry`/similar components for every real column: `reference`, `payment_method`, `payment_reference`, `instructions`, `status`, `submitted_at`, `decided_at`, `decided_reason`, `decided_by_actor_ref`, plus `proof_document_id` if it's reasonable to show as a plain reference (do NOT build a document preview/download link here — that's a different feature with its own authorization surface; just show the raw ID or omit it if showing a bare document ID without a safe way to view it would be misleading — use your judgment and note the choice in your report).

- [ ] **Step 6: Confirm resource registration**

Check whether `app/Providers/Filament/AdminPanelProvider.php` explicitly lists resources or uses `->discoverResources(...)` (directory-based auto-discovery). If auto-discovery, no change needed — the new Resource is picked up automatically, same as every other Resource under `app/Filament/Admin/Resources/`. If explicit registration, add this resource to that list following the existing convention.

- [ ] **Step 7: Write tests**

`PaymentVerificationsResourceAccessTest.php` (mirror `AuditEventsResourceAccessTest.php`'s structure):
- An actor with `finance` role can reach the list page (200) and see a real seeded row.
- An actor with `restricted_admin` role can reach the list page (200).
- An actor with neither role (e.g. plain `admin`, matching `FinanceOrRestrictedAdminPaymentAuthorizer`'s own documented `admin`-exclusion ruling) gets 403, not a redirect or a silently-empty list.
- A guest (unauthenticated) is redirected to login, not shown any data.
- No `create`/`edit` route exists for this resource — assert this directly (e.g. `route('filament.admin.resources.payment-verifications.create')` throws a `RouteNotFoundException`, or equivalent check for whatever the real generated route names turn out to be — confirm the real route names via `php artisan route:list` or Filament's own route-name convention before asserting).

`PaymentVerificationsTableTest.php` (mirror `AuditEventsTableTest.php`'s structure):
- Seed 2-3 real `PaymentVerification` rows (via `PaymentVerification::createSubmitted(...)`, the real factory method — do not bypass it with raw `create()`, which the model's own `saving` guard would refuse anyway) with different statuses, at least one `decide()`d.
- Assert the list page renders all seeded rows' real field values.
- Assert sort order is newest-`submitted_at`-first.
- Assert the view page for one record shows its real field values.

- [ ] **Step 8: Run tests against real Postgres, run doc/lint gates, commit**

```bash
vendor/bin/pint --test
vendor/bin/phpstan analyse
bash ci/verify-docs.sh
```

Run the new test files against real Postgres 18 using this session's established Docker recipe (see Global Constraints). Confirm `OK (N tests, M assertions)` with no failures, no stray warnings.

```bash
git add app/Filament/Admin/Resources/PaymentVerifications tests/Feature/Filament/Admin/PaymentVerifications
git commit -m "feat(admin): read-only Payment Verifications admin view (ADM-070)"
```

---

### Task 2: Documentation — traceability, screen inventory, release gates

**Files:**
- Modify: `docs/domain/traceability-matrix.md` (ADM-070's Evidence column + a dated changelog entry under section D, following the same convention `docs/superpowers/plans/2026-08-24-release-gates-batch3-closeout.md`'s Task 3 already established for this file)
- Modify: `docs/product/screen-inventory.md` (ADM-070's row — mark shipped, matching ADM-010 through ADM-060's "— **shipped** [date]" convention)
- Modify: `docs/testing/release-gates.md` (§A's traceability box — narrow further now that ADM-070 has real evidence; it likely still stays unchecked because CARE-SUB-02/CARE-SUB-06's "partial evidence" gaps from the prior pass are untouched by this plan — state this precisely, don't overclaim a full close)

**Interfaces:**
- Consumes: Task 1's real, committed test file names and commit SHA (read `git log`/the test files directly — do not guess at what Task 1 built).
- Produces: nothing consumed elsewhere.

- [ ] **Step 1: Confirm Task 1's real committed state**

Read Task 1's actual committed files (`git log --oneline`, `git show` on the commit) — cite the REAL file paths, class names, and test method names Task 1 produced, not what this plan predicted Task 1 would produce (Task 1's implementer may have made reasonable adaptations during real implementation).

- [ ] **Step 2: Update `docs/domain/traceability-matrix.md`**

Update ADM-070's row: Evidence column cites the real new test file(s)/method(s) from Task 1. Status changes from whatever it currently is to `Covered` (confirm the row's exact current wording first — read it directly). Add a dated section-D entry (`### ADM-070 raised — 25 Aug 2026`, following the exact format the batch-3 plan's Task 3 entry already established — read that entry as your template) describing what was built and why it satisfies ADM-070's own requirement text.

- [ ] **Step 3: Update `docs/product/screen-inventory.md`**

Update ADM-070's row (`| ADM-070 | Payment/transaction/manual verification |`) to match ADM-010 through ADM-060's shipped-row format: `| ADM-070 | Payment/transaction/manual verification — **shipped** 25 Aug 2026 (`PaymentVerificationsResource`, read-only list + view; manual verification's actual decision stays at its existing route, this adds visibility only) |` (adapt exact wording to what Task 1 really built).

- [ ] **Step 4: Update `docs/testing/release-gates.md` §A**

Read the current box text directly (it was last updated by the batch-3 plan's Task 3 to name only `ADM-070`/`CARE-SUB-02`/`CARE-SUB-06` as the remaining named gaps). Remove `ADM-070` from that named-gaps list, citing the real new evidence, following this file's established "narrow the compound claim precisely" convention — state clearly that `CARE-SUB-02`/`CARE-SUB-06`'s partial-evidence gaps are UNTOUCHED by this plan (do not imply they were also addressed). The box likely stays unchecked for that reason — state the real reason precisely, don't overclaim a full close.

- [ ] **Step 5: Run doc gates, commit**

```bash
bash ci/verify-docs.sh
git add docs/domain/traceability-matrix.md docs/product/screen-inventory.md docs/testing/release-gates.md
git commit -m "docs: record ADM-070 as shipped (Payment Verifications admin view)"
```

---

## Verification

Task 1: `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`, `bash ci/verify-docs.sh`, and the new Feature test files run for real against the pinned container image + Postgres 18 (matching this session's established "no unexecuted PASS" discipline). Task 2: `bash ci/verify-docs.sh` (GATE 7 mechanically checks that every `Covered` row's cited evidence path exists on disk — this will catch a wrong citation).

## Execution

This plan will be executed via superpowers:subagent-driven-development immediately after being written and saved, matching this session's established pattern for every workstream so far (fresh implementer per task, task-scoped review, final whole-branch review before PR).

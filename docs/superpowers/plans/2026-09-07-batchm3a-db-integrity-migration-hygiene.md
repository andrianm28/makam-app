# Batch M3a — DB-01, DB-03, DB-05 (migration hygiene + referential integrity)

Phase 3 of `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`. Branch
`fix/batchm3a-db-integrity-migration-hygiene`, worktree-isolated.

## DB-01 — DestructiveMigrationScanner blind spots

**Bug 1 (whole-file blind spot).** `scan()` slices `up()`'s body as
"from `up()`'s start to `down()`'s start" (or EOF if `down()` isn't found
after `up()`). A private helper method declared textually AFTER `down()` —
called FROM `up()` — is never scanned, because it falls inside neither the
`up..down` slice nor is `down()` itself. Fix: scan the WHOLE file text minus
`down()`'s own brace-matched body, regardless of which method is declared
first in the file. This requires an actual brace matcher (a regex offset for
`down(`'s start plus a naive "next `}`" is wrong — `down()` bodies contain
their own nested braces, e.g. `Schema::table(fn ($t) => ...)`).

**Bug 2 (missing patterns).** Add to `DESTRUCTIVE_METHOD_PATTERNS`:
`->change(`, `renameColumn`, `dropPrimary`. Add to `DESTRUCTIVE_SQL_PATTERNS`:
`DROP CONSTRAINT`, `ALTER COLUMN`+`TYPE` co-occurrence (a single new SQL
pattern check, since a bare `ALTER COLUMN` also appears in safe `SET NOT
NULL`/`DROP DEFAULT` forms — only flag when the same line also mentions
`TYPE`, case-insensitively, matching this scanner's existing per-line
matching granularity).

**Verification obligation.** 8 migrations use `DROP CONSTRAINT` today, 2 use
`->change(`. All 10 must be re-scanned after the fix; each is either a
legitimate additive/widening change (gets a `// contract-approved: <ref>`
comment, matching the scanner's existing override convention) or is a real
finding to flag for human review (none expected — these are already-shipped,
already-reviewed migrations, but confirm rather than assume).

**CI comment.** `.github/workflows/ci.yml`'s `verify-migrations` job comment
currently claims this gate is "the automated replacement for the manual
'read every migration before running it' step". Soften: it narrows, it does
not replace, human review — `AGENTS.md` §Infrastructure-agent execution's
mandatory human review for destructive-migration-adjacent changes stands in
front of it regardless of what this scanner catches.

## DB-03 — Missing FK constraints, VendorFulfillment/CareSubscription

New expand-only migration (existing migration files are not edited — they
may already be applied in deployed environments). Full inventory of
unconstrained `foreignUuid()`/`uuid()` FK-shaped columns in this family,
confirmed by grep across every `2026_08_17_*` CareSubscription/
VendorFulfillment migration plus the two later fix-up migrations
(`2026_08_22_100000`, `2026_09_04_100000` already fixed `customer_id` ×3 and
`uploaded_by`, and `make_good_order_id` respectively — excluded below):

| Table.column | References | On delete |
| --- | --- | --- |
| `work_orders.care_plan_id` | `care_plans.id` | restrict |
| `work_orders.subscription_cycle_id` (nullable) | `subscription_cycles.id` | restrict |
| `work_orders.vendor_id` (nullable) | `vendors.id` | restrict |
| `work_orders.assigned_to` (nullable) | `vendors.id` | restrict — confirmed via `AssignWorkOrder`: this column holds a vendor id, not a user id |
| `work_order_tasks.work_order_id` | `work_orders.id` | cascade |
| `work_evidence.work_order_id` | `work_orders.id` | cascade |
| `work_evidence.document_id` | `documents.id` | restrict |
| `service_acceptances.work_order_id` | `work_orders.id` | cascade |
| `service_complaints.work_order_id` | `work_orders.id` | cascade |
| `make_good_orders.original_work_order_id` | `work_orders.id` | restrict |
| `make_good_orders.replacement_work_order_id` (nullable) | `work_orders.id` | restrict |
| `make_good_orders.original_cycle_id` | `subscription_cycles.id` | restrict |
| `care_plans.vendor_id` (nullable) | `vendors.id` | restrict |
| `subscription_cycles.work_order_id` (nullable) | `work_orders.id` | restrict |
| `subscription_cycles.invoice_id` (nullable) | `subscription_invoices.id` | restrict |

Cascade only for tables that exist solely as a work order's own children
(tasks/evidence/acceptance/complaint records have no meaning once the work
order is gone); everything else restricts, matching this repo's established
convention of never letting an FK silently cascade-delete history.

`pre_need_consultation_requests.pre_need_interest_id` already has
`->constrained()->nullOnDelete()` — out of scope (different domain, and
already constrained).

**Deliberately excluded: `subscriptions.grave_id` -> `grave_records.id`.**
Genuinely an unconstrained FK-shaped column in this family, but adding it
would make `Schema::dropIfExists('grave_records')` fail with Postgres
2BP01 in four existing, carefully reverse-dependency-ordered degradation
tests that don't drop `subscriptions` first (`LaunchCityTest`,
`BookingWizardRouteTest`, `RenewalStartTest`,
`CemeteryDirectoryIndexRouteTest`) — none of which this task named. Left
as a follow-up rather than a drive-by fix that breaks four unrelated
tests to add one FK this task didn't explicitly ask for.

**Orphan check, not silent cleanup.** Per `AGENTS.md` §Database and
§Infrastructure-agent execution, this migration does NOT run a DELETE
against orphaned rows automatically — an automated, unreviewed DELETE inside
a migration that runs unattended on `beta`/production is itself the kind of
destructive-migration-adjacent change that needs a human's eyes first, not
less. Instead:
- Verified zero orphans on a disposable Postgres 18 test DB (fresh schema,
  no rows written to this family yet in that DB).
- `ALTER TABLE ... ADD CONSTRAINT` fails loudly (transaction rolls back) if
  real orphans exist in any environment — a safe failure mode that blocks
  the deploy rather than silently destroying data.
- The exact orphan-check SELECTs are documented in the migration's own doc
  block for a human operator to run against beta/production BEFORE this
  migration is allowed to run there.

**DemoDataPurgeCommand.** `make_good_orders` is missing from
`deleteCareSubscriptionScopedTables()` entirely today (confirmed by
reading the method — no reference to the table). Once
`make_good_orders.original_work_order_id`/`replacement_work_order_id`
restrict-delete on `work_orders`, the existing `work_orders` delete step in
that method will start failing on any batch that also exercises make-good.
Add a `make_good_orders` delete (keyed off `$workOrderIds`, covering both
FK columns) BEFORE the existing `work_orders` delete in the same method.

## DB-05 — Unguarded fabricated-data migrations run on every deploy

Six migrations write clearly-fictional vendor/pricing/cemetery fixture data
with **no environment guard at all** — they run unconditionally on every
`php artisan migrate`, including a real production deploy:
`2026_07_26_190300`, `2026_07_26_200100`, `2026_07_26_210000`,
`2026_08_08_100010`, `2026_08_14_100000`, `2026_08_14_100010`.

`2026_08_25_140000_seed_realistic_marketplace_pricing_fixtures.php` already
does this correctly for a SEPARATE, opt-in supplementary fixture: a
default-`false` `config('example_data.*')` flag (env-driven) plus an
independent `app()->isProduction()` guard as defence in depth.

**Deviation from a literal copy of that shape, documented here rather than
silently applied:** the six migrations above are not opt-in extras — their
own doc blocks and this repo's existing test suite
(`VendorListingBootstrapTest`, `CemeterySeedTest`, `GraveRecordSeedTest`,
`ProductDetailRouteTest`, etc.) depend on them running **unconditionally in
dev, staging, and CI** ("every fresh database ships five example vendors...
so the marketplace journey is operable end to end from seed alone"). Gating
all six behind a default-`false` flag, exactly as written, would silently
break that existing, currently-passing contract in every environment
including CI, the moment nobody remembers to flip an env var — a bug at
least as bad as the one being fixed, and one `verification-before-completion`
would catch as newly-red tests.

Applied instead, per migration:
1. A mandatory `if (app()->isProduction()) { return; }` guard — this alone
   closes the actual reported hole (fabricated data landing in a production
   deploy), with zero behavior change for dev/staging/CI.
2. A `config('example_data.*')` flag, defaulting **true** (not false) via
   `env(..., true)`, giving an operator an explicit kill switch without
   changing today's default behavior anywhere it currently runs. This still
   matches the *shape* (independent config flag + isProduction guard as
   defence in depth) `2026_08_25_140000` established; the default direction
   differs because these six are baseline fixtures, not opt-in extras.

Where a fixture value must persist regardless of environment,
`2026_07_26_210000`'s existing convention is followed: mark it in the
stored value itself (`price_source = 'Estimasi internal (data contoh)'`) —
no change needed there, already correct; the fix is only the missing
production guard.

A human operator must run `PurgeExampleDataCommand` against beta after this
merges, to remove any fixture rows this class of migration already wrote
there before this fix existed. This PR does not run it.

## Verification plan

- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse --no-progress` (bump memory limit if needed)
- `bash ci/verify-docs.sh`
- New/updated PHPUnit coverage:
  - `DestructiveMigrationScannerTest`: helper-method-after-`down()` blind
    spot (both declaration orders — `up()` before `down()` and `down()`
    before `up()`), `->change(`, `renameColumn`, `dropPrimary`,
    `DROP CONSTRAINT`, `ALTER COLUMN ... TYPE`.
  - A new Feature test for the FK-constraints migration against real
    Postgres (RefreshDatabase + assert `information_schema` FK rows exist;
    assert a delete of a parent with a child row is rejected/cascaded per
    the table above).
  - `DemoDataPurgeCommandTest`: extend to cover a make-good order surviving
    (or not) a purge without a FK violation.
  - Guard tests for the six DB-05 migrations: `app()->isProduction()` skips
    seeding; non-production still seeds (regression guard for the
    intentional default-true deviation above).
- Real PostgreSQL 18 in a disposable `m3a-`-prefixed container, not SQLite
  (`project_worktree_test_env` / `feedback_verify_against_real_db_not_sqlite`
  memory notes).

This PR touches migration-safety tooling and production data hygiene —
flagged explicitly for mandatory human review per `AGENTS.md`
§Infrastructure-agent execution.

## Real regressions found and fixed while verifying DB-03 against Postgres

The new FK constraints, run against real seeded data, caught genuine
pre-existing bugs no test had surfaced before (exactly what DB-03 exists
to prevent going forward):

- `tests/Feature/Livewire/Public/CareSubscription/CareHistoryPageRouteTest.php`
  and `CareHistoryPageTest.php` each wrote a `work_orders.care_plan_id`
  value that was actually a `subscription_id` (one) or
  `$cycle->subscription_id` (the other) — silently accepted before because
  the column had no FK; now fixed to the real `care_plans.id`.
- `tests/Feature/Domain/VendorFulfillment/ComplaintResolutionFlowTest.php::
  test_resolve_with_make_good_rolls_back_entirely_when_the_work_order_lookup_fails`
  deliberately writes a nonexistent `work_order_id` to prove
  `ResolveComplaint`'s defensive `WorkOrder::firstOrFail()` rolls back
  cleanly — the DB now rightly refuses that write. Fixed by disabling
  `service_complaints`' triggers (Postgres implements FK enforcement as
  triggers) for that one write, inside `RefreshDatabase`'s per-test
  transaction, so the otherwise-impossible state can still be constructed
  to test the defensive code without weakening the constraint.
- `subscription_cycles.invoice_id`/`work_order_id` are `SET NULL`, not
  `RESTRICT` like everything else here — a real circular-FK deadlock with
  `subscription_invoices.subscription_cycle_id`'s existing RESTRICT was
  caught by actually running `demo-data:seed` + `demo-data:purge --force`
  against real Postgres (see the migration's own doc block).
- `DemoDataPurgeCommand::deleteCareSubscriptionScopedTables()` now deletes
  `make_good_orders` before `work_orders`, or the new
  `make_good_orders_original_work_order_id_fk` RESTRICT would break any
  purge of a batch that exercised make-good.

## Pre-existing issues found, NOT caused by and NOT fixed by this PR

Discovered incidentally while verifying against real Postgres; confirmed
by reproducing identically with this branch's changes fully reverted
(`git stash`) against the same trunk commit:

- **`demo-data:seed` + `demo-data:purge --force` end-to-end against real
  Postgres fails** with `SQLSTATE[23503]: Vendor payable ... does not
  exist`, raised by `assert_vendor_payable_payout_pair()` — the deferred
  constraint trigger from `2026_08_10_120300_enforce_vendor_payable_
  payout_consistency.php` re-queries a `vendor_payables` row that was
  legitimately just deleted (by `DemoDataPurgeCommand`'s own courtesy
  cleanup) and raises "does not exist" instead of recognizing a DELETE.
  Breaks `DemoDataPurgeCommandTest::
  test_seed_then_purge_returns_the_database_to_its_pre_seed_state` and
  `test_purge_never_deletes_a_real_pre_existing_visitation_policy` whenever
  actually run against real Postgres with vendor-payable rows present.
  Worth its own audit finding; out of scope here (financial-domain trigger
  logic, unrelated to migration hygiene or VendorFulfillment/
  CareSubscription referential integrity).
- **A large cascade of Feature-test failures** (roughly a third of the
  ~3150-test Feature suite, starting mid-run) reproduces identically on
  unmodified trunk in this same Docker/Postgres 18 test environment —
  confirmed by running the full suite with this branch's changes stashed
  out. Not investigated further (out of scope for DB-01/DB-03/DB-05); flagged
  here so it is not mistakenly attributed to this PR.
- **The CI-parity Docker image has no `git` binary**, which fails
  `VerifyNoDestructiveMigrationsCommandTest` locally (it shells out to
  `git diff`) — matches the existing `project_worktree_test_env` memory
  note. Not a real failure; `git` is present in actual GitHub Actions runners.
- **`ci/verify-docs.sh` GATE 1 (WCAG contrast) needs `python3`**, absent
  from this image — same class of pre-existing local-environment gap, not
  a real gate failure. GATES 2–13 all pass locally.
- Two pre-existing `tests/Unit/Platform/DocumentVault/*` failures
  (`imagecreatetruecolor()` undefined — no `ext-gd` in this image;
  `DocumentVaultConfigurationTest` staging-default assertions) are
  environment/config gaps unrelated to this batch.

# Batch M7a — Query performance fixes (PERF-01, PERF-03, PERF-05, PERF-10, PERF-11, PERF-13)

Phase 3 of the audit remediation program (`docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`).
Six Medium findings, all query-performance bugs: an unbounded scan, two
unbounded eager-load queries, two N+1s, an index-defeating filter shape, and
an index-defeating fuzzy-search query shape. Branch:
`fix/batchm7a-query-performance-fixes`.

## PERF-01 — `ReconcileDocumentStorageCleanupJob` unbounded orphan scan

**File**: `app/Platform/DocumentVault/Jobs/ReconcileDocumentStorageCleanupJob.php`

**Problem**: the orphan-document half of `handle()` scanned every document
in a non-`accepted` storage prefix and a terminal state
(`QUARANTINED`/`SCANNING`/`REJECTED`/`EXPIRED`/`DELETED`) with `->cursor()->each()`
and no bound. Terminal states never leave that predicate — a rejected
document stays rejected forever — so the candidate set only grows across the
app's lifetime, and this job runs every 5 minutes (`bootstrap/app.php`).

**Fix**: bounded, resumable cursor.
- `->orderBy('updated_at')->orderBy('id')->limit(orphanScanLimit())`, limit
  from `config('document-vault.reconcile_orphan_scan_limit')` (default 500,
  overridable in tests).
- The `(updated_at, id)` of the last row processed is persisted as a
  watermark in cache (`document-vault:reconcile-storage-cleanup:watermark`,
  1-day TTL) and used to resume the NEXT run from where this one stopped.
- When a run returns fewer rows than the limit, the pass is complete — the
  watermark resets so the scan cycles back to the beginning and keeps
  self-healing (a document that becomes newly-terminal after the watermark
  passed it is picked up on the next full cycle).
- The handler this calls
  (`CleanupPromotedDocumentStorageJob::reconcileAcceptedCopy`) is already
  idempotent (`ObjectStorage::deleteAcceptedIfExists`), so re-visiting an
  already-clean document is a safe no-op — the watermark reset is not a
  correctness risk.

**Test**: `tests/Unit/Platform/DocumentVault/Jobs/ReconcileDocumentStorageCleanupJobBoundedScanTest.php`
— with the limit configured to 3 and 5 eligible documents seeded (real
Postgres), one run processes exactly 3 (watermark stops at the 3rd
document's id) and a second run finishes the remaining 2 and resets the
watermark. **Mutation-verified**: reverting the `limit()` call makes the
first assertion fail (watermark lands on the wrong row) — confirmed by
temporarily reverting the fix inside the Docker verification container and
re-running the test (see Verification section).

## PERF-03 — Booking plot picker / floor map unbounded eager load

**Files**: `app/Livewire/Public/Booking/BookingWizard.php` (`pickerBlocks()`),
`app/Filament/Shared/PlotFloorMap/BasePlotFloorMapPage.php` (`blocks()`)

**Problem**: both methods ran `CemeteryBlock::query()->where('cemetery_id', ...)
->with(['plots' => fn ($q) => $q->orderBy('slot')])->orderBy('code')->get()`
with no limit on either the blocks query or the nested `plots` eager load.
`pickerBlocks()` re-runs on every `wire:poll.5s` tick while the picker is
open (`resources/views/livewire/public/booking/wizard.blade.php:431`).

**Fix**: mirrored `PlotAvailabilityPreview`'s already-correct bounding
pattern (`app/Livewire/Public/Home/PlotAvailabilityPreview.php`, its own
`MAX_BLOCKS_PER_CEMETERY`/`MAX_PLOTS_PER_BLOCK` constants) — added
`->limit()` on both the outer blocks query and the nested `plots` eager
load, driven by new config keys:
- `config('booking.plot_picker_max_blocks')` (`BOOKING_PLOT_PICKER_MAX_BLOCKS`, default 200)
- `config('booking.plot_picker_max_plots_per_block')` (`BOOKING_PLOT_PICKER_MAX_PLOTS_PER_BLOCK`, default 500)

Defaults are generous (real cemeteries need their full inventory rendered
for the floor-map admin/vendor pages), unlike `PlotAvailabilityPreview`'s
much smaller showcase-specific limits — this bounds the WORST case rather
than truncating ordinary usage.

The view (`wizard.blade.php:431`) only ever iterates the returned
collection and its `plots` relation — no code depends on an exact count —
so bounding does not change rendering shape.

**Test**: `tests/Feature/Livewire/Public/Booking/BookingWizardPlotPickerTest.php`
(`test_picker_blocks_are_bounded_by_config`,
`test_picker_plots_per_block_are_bounded_by_config`) and
`tests/Feature/Filament/PlotFloorMapPageTest.php`
(`test_blocks_are_bounded_by_config`) — seed more blocks/plots than a small
configured limit, assert the returned collection is capped at the limit.
**Mutation-verified**: removing the `->limit()` call on `pickerBlocks()`
makes `test_picker_blocks_are_bounded_by_config` fail (actual 4, expected 2)
— confirmed in the Docker verification container.

## PERF-05 — Cemetery capability profile / package N+1

**Files**:
- New: `ResolveCemeteryCapabilityProfile::forMany()` (`app/Domain/CemeteryCapability/Actions/ResolveCemeteryCapabilityProfile.php`)
- New: `PublicCapabilityProjection::forMany()` (`app/Livewire/Public/Directory/Support/PublicCapabilityProjection.php`)
- New: `CemeteryPublicQuery::activePackagesForMany()` (`app/Domain/CemeteryDirectory/CemeteryPublicQuery.php`)
- Call sites updated: `CemeteryDirectoryIndex::render()`, `BookingWizard::render()`

**Problem**: `CemeteryDirectoryIndex::render()` resolved each card's
capability profile with `$cemeteries->map(fn ($c) => PublicCapabilityProjection::forCemetery($c))`
— one query per cemetery. `BookingWizard::render()` did the same for both
capability profiles AND active packages
(`CemeteryPublicQuery::activePackages($cemetery)` per cemetery). Neither
page paginates, so query count scaled linearly with the directory/step's
cemetery count.

**Fix**: batch resolvers, each in ONE query:
- `ResolveCemeteryCapabilityProfile::forMany(Collection $cemeteries)` —
  `CemeteryCapabilityProfile::whereIn('cemetery_id', $ids)->current()->get()`,
  grouped by `cemetery_id` and reduced to the highest `version_number` in
  PHP (mirrors `__invoke()`'s per-cemetery tiebreak exactly, so switching a
  caller from N calls of `__invoke()` to one call of `forMany()` returns
  identical results, not an approximation). Missing cemeteries fall back to
  the same safe-default construction `__invoke()` uses.
- `PublicCapabilityProjection::forMany()` — thin wrapper projecting the
  above through the same four-key public allowlist `forCemetery()`/`from()`
  already apply.
- `CemeteryPublicQuery::activePackagesForMany(Collection $cemeteries)` —
  `CemeteryPackage::whereIn('cemetery_id', $publishedIds)->active()->get()`,
  grouped back out per cemetery.

**Behavioural note, stated explicitly**: the previous per-cemetery
`try/catch` in `CemeteryDirectoryIndex::render()` degraded ONLY the failing
cemetery's card to safe defaults on a resolution failure. Because capability
resolution is now one query for the whole page, a failure is necessarily
whole-batch: `capabilitiesDegraded` now applies safe defaults to every card
at once rather than just the one that failed. This is the same AC4 fallback
("missing profile -> safe defaults"), applied at a coarser grain — flagged
rather than silently changed. `BookingWizard::render()`'s equivalent
try/catch is updated the same way.

**Test**: `tests/Feature/Domain/CemeteryCapability/CemeteryCapabilityBatchResolutionQueryCountTest.php`
— asserts exactly ONE query against `cemetery_capability_profiles` (and
`cemetery_packages`) for 5 cemeteries, with a mix of "has a real profile
row" and "falls back to safe defaults" cemeteries so both branches are
exercised in the same call, not just the trivial all-fallback case.
**Mutation-verified**: temporarily making `forMany()` loop and call
`__invoke()` per cemetery (simulating the pre-fix N+1) makes the query-count
assertion fail (5 queries, not 1) — confirmed in the Docker verification
container, then reverted.

All existing directory/booking-wizard rendering tests
(`tests/Feature/Livewire/Public/Directory/*`, `tests/Feature/Livewire/Public/Booking/*`)
pass unchanged — 236 tests, no behavioural regression in what is rendered.

## PERF-10 — Audit events table index-defeating filters

**File**: `app/Filament/Admin/Resources/AuditEvents/Tables/AuditEventsTable.php`

**Problem**:
1. The `occurred_at` date-range filter used `whereDate('occurred_at', ...)`
   — `DATE(occurred_at) >= ?` — which wraps the INDEXED column
   (`audit_events_occurred_at_index`) in a function, defeating a plain
   b-tree index scan.
2. The `action` filter used a leading-wildcard LIKE (`'%'.$value.'%'`),
   which cannot use `action`'s own b-tree index either (no seek point when
   the match can start anywhere in the string).

**Fix**:
1. Replaced `whereDate()` with a half-open range on the raw timestamp:
   `occurred_at >= start-of-day` AND `occurred_at < start-of-NEXT-day`.
   Selects the exact same calendar-day rows, index-usable.
2. Replaced the `action` filter's leading wildcard with a prefix match
   (`$value.'%'`) — `action` values are dot-namespaced
   (`booking.rescheduled`, `cemetery.capability_changed`, ...), so a prefix
   search still covers the real "events under this namespace" use case
   while restoring index usability.

**Deliberately NOT changed**: the `actor_ref` filter. Read the existing
test (`AuditEventsTableTest::test_filtering_by_actor_narrows_the_table`)
carefully first, per the task brief's own instruction — it searches
`'alpha'` and expects it to match `'actor-alpha'`, a SUBSTRING match, not a
prefix. `actor_ref` values are opaque identity references (numeric user
ids, or strings like `'actor-alpha'`) with no shared namespace vocabulary
the way `action` has one. Switching it to a prefix match would silently
stop finding that legitimate case and break both real operator search
intent and the existing passing test. This filter still forces a scan on a
non-empty search — that trade-off is accepted rather than changing what an
operator's search finds. Documented inline in the fixed file.

**Test**: `tests/Feature/Filament/Admin/AuditEvents/AuditEventsTableIndexUsageTest.php`
— asserts the generated SQL no longer contains `DATE(...)`, and (Postgres
only) that `EXPLAIN` with `enable_seqscan = off` shows an index scan is
available for the half-open range, not forced back to a sequential scan.
Existing `AuditEventsTableTest` (all 7 cases, including the actor substring
case) passes unchanged.

## PERF-11 — Six Filament tables with no eager load on relation columns

**Files**:
- `app/Filament/Admin/Resources/WorkOrders/Tables/WorkOrdersTable.php` (`carePlan.name`, `vendor.name`)
- `app/Filament/Admin/Resources/CarePlans/CarePlansResource.php` (`vendor.name`)
- `app/Filament/Admin/Resources/Subscriptions/SubscriptionsResource.php` (`grave.slot`, `carePlan.name`)
- `app/Filament/Admin/Resources/ServiceComplaints/ServiceComplaintsResource.php` (`workOrder.reference`) — had NO `getEloquentQuery()` override at all
- `app/Filament/Vendor/Resources/WorkOrders/WorkOrdersResource.php` (`carePlan.name`)
- `app/Filament/Vendor/Resources/VendorListings/VendorListingResource.php` (`product.name`, `product.category`)

**Fix**: added `modifyQueryUsing()` (table-level, matching
`BookingOrdersTable`'s pattern) or a `getEloquentQuery()` override with
`->with([...])` (matching `GravePlotsResource`'s pattern) naming exactly the
relations each table's columns read. The two vendor-panel resources chain
onto `ScopesToCurrentVendor::getEloquentQuery()`'s scoped base
(`static::applyVendorScope(parent::getEloquentQuery())->with([...])`) so the
vendor-scoping guarantee that trait's own doc block describes is preserved.

**IMPORTANT — an honest finding from verification, not from reading code**:
mutation-testing each fix (reverting the explicit eager load, re-running
its query-count test) showed the test STILL PASSES without the fix. Reason:
this installed Filament version (5.7.3) already auto-eager-loads every
VISIBLE dot-notation relationship column —
`Filament\Tables\Concerns\HasRecords::filterTableQuery()` calls
`$column->applyEagerLoading($query)` for each of `getVisibleColumns()`
(`vendor/filament/tables/src/Concerns/HasRecords.php:51`), and
`applyEagerLoading()` (`vendor/filament/support/src/Concerns/HasCellState.php`)
resolves the dotted column name to a relationship and adds it to `with()`
itself if not already eager-loaded. So the runtime N+1 PERF-11 describes
does **not** actually reproduce today for any of these six tables — this was
verified directly, not assumed.

The six fixes are kept anyway (harmless — Eloquent's `with()` de-duplicates,
`array_key_exists($relationshipName, $query->getEagerLoads())` in Filament's
own code confirms this is checked), for three reasons stated honestly:
1. Consistency with this codebase's established convention
   (`BookingOrdersTable`/`GravePlotsResource` already carry the identical
   explicit `with([...])`, predating this batch).
2. Framework-independence: Filament's automatic eager-loading stops
   applying the moment a column is `toggleable(isToggledHiddenByDefault: true)`,
   or the query is reached through a path that does not go through
   `filterTableQuery()` (a bulk export action, a relation manager, a report
   query).
3. Documentation for future readers who should not have to know Filament's
   internal column-eager-loading mechanism exists to reason about a
   Resource's query shape.

**Test**: `tests/Feature/Filament/Perf11EagerLoadingQueryCountTest.php` — six
query-count tests (real Postgres, `DB::listen`), one per table, asserting
the query count against the related table is bounded for 3 seeded records.
These pass both with and without the explicit fix (see above) — the test
file's own doc block states this plainly rather than claiming the tests
prove a behavioural fix they do not prove.

## PERF-13 — Grave fuzzy search cannot use its own trigram index

**Files**:
- `app/Domain/GraveRegistry/GraveRegistryPublicQuery.php` (`buildQuery()`)
- New migration: `database/migrations/2026_09_07_100100_add_grave_records_name_trgm_gist_index.php`
- `app/Console/Commands/BenchGraveSearchCommand.php` (`--explain` option)

**Problem**: the fuzzy-match branch was
`WHERE (deceased_name_normalized LIKE ? OR similarity(deceased_name_normalized, ?) >= threshold)
ORDER BY similarity(deceased_name_normalized, ?) DESC`. `similarity()`
called as a bare function is not one of the operators the existing GIN
index (`grave_records_name_trgm_idx`, `gin_trgm_ops`) indexes (`%`, `<%`,
`%>`, LIKE/ILIKE, `~`/`~*`) — see that migration's own corrected doc block.
A disjunction is only index-usable if every branch is, so the non-indexable
`similarity()` branch defeated the LIKE branch's indexability too. `ORDER BY
similarity(...)` cannot use a GIN index at all regardless (GIN has no
ordering support) — it needs a GiST index and the KNN distance operator
(`<->`) for that.

**Fix**:
1. Rewrote the WHERE clause's similarity branch to the OPERATOR form:
   `deceased_name_normalized % ?`, with `pg_trgm.similarity_threshold` set
   to `GraveRegistryPublicQuery::SIMILARITY_THRESHOLD` via `SET` before the
   query runs (not `SET LOCAL` — this call is not inside a transaction; the
   value is a fixed class constant, never user input). `column % ?` is
   documented by `pg_trgm` as equivalent to
   `similarity(column, ?) >= current_setting('pg_trgm.similarity_threshold')`
   once that GUC is set — the explicit `SET` keeps the exact same match set
   as before rather than silently relying on `pg_trgm`'s own default
   happening to also be 0.3.
2. Rewrote `ORDER BY similarity(...) DESC` to `ORDER BY deceased_name_normalized <-> ?`
   (KNN distance — ascending distance is descending similarity, same rank
   order).
3. Added a new migration creating a GiST trigram index
   (`grave_records_name_trgm_gist_idx`, `gist_trgm_ops`) alongside the
   existing GIN index — additive, not a replacement. The real `EXPLAIN`
   captured below shows the planner actually choosing the GiST index for
   BOTH the `%` and the `LIKE` branches of the WHERE clause via a
   `BitmapOr`, not the GIN index — `gist_trgm_ops` supports the same
   operators GIN does, and the planner preferred one index family for this
   dataset/query shape. The GIN index is kept regardless: it is unused
   removal capacity, not a regression, and this repository's convention
   (`AGENTS.md` §Database) is not to drop a working index without a
   measured reason to. What matters for PERF-13 is that an index scan is
   available at all, which the OLD `similarity()`/`ORDER BY similarity()`
   shape never had regardless of which index existed. Non-destructive
   (an index add against an already-deployed table), so no human
   sign-off gate applies (`AGENTS.md` §Infrastructure-agent execution).
4. Extended `BenchGraveSearchCommand` with a `--explain` option that prints
   `EXPLAIN (ANALYZE, BUFFERS)` for the exact rewritten query shape — real
   evidence the rewrite is index-usable, not just an assertion.

**Test**: `tests/Feature/Domain/GraveRegistry/GraveRecordTrigramSearchTest.php`
gains:
- `test_the_gist_trigram_index_exists_on_the_normalized_name_column`
- `test_explain_shows_index_scans_for_the_rewritten_fuzzy_match_query` —
  real Postgres `EXPLAIN` with `enable_seqscan = off` (`SET LOCAL`, reset
  automatically at end of test via `RefreshDatabase`'s transaction wrap),
  proving the rewritten WHERE/ORDER BY shape has an index-scan plan
  available — the old shape had none, at any table size.
- `test_the_rewritten_operator_form_still_finds_a_misspelled_name` —
  behavioural equivalence: the rewrite must change HOW Postgres finds the
  match, never WHAT matches.

All 4 pre-existing tests in that file, plus `GraveRegistryPublicQueryTest`
(18 cases) and `GraveRegistryPublicQueryResolveOpenRecordAtTest` (4 cases),
pass unchanged.

**Benchmark evidence — real, captured against `postgres:18` (container
`m7a-postgres`), 20 synthetic cemeteries / 20,000 grave records
(`php artisan bench:generate-grave-dataset --cemeteries=20 --records=20000`),
largest single cemetery 1,000 records (this is a 2 vCPU dev host — NOT the
production 100,000-record AC4 certification scale;
`docs/testing/release-gates.md` §H is the one that governs that, and
`docs/operations/release-gates.md` §I forbids citing this host as
production performance evidence)**:

```
php artisan bench:grave-search --iterations=100 --explain

Benchmarking against cemetery e7840ef1-8c52-483f-98df-1027f3d33c1f (1000 records), search term "Hend", 100 iterations...

EXPLAIN (ANALYZE, BUFFERS) for the fuzzy-match query shape:
Limit  (cost=30.13..30.13 rows=1 width=1894) (actual time=4.743..4.753 rows=50.00 loops=1)
  Buffers: shared hit=274
  ->  Sort  (cost=30.13..30.13 rows=1 width=1894) (actual time=4.741..4.746 rows=50.00 loops=1)
        Sort Key: (((deceased_name_normalized)::text <-> 'hend'::text)), deceased_name_normalized
        Sort Method: quicksort  Memory: 40kB
        Buffers: shared hit=274
        ->  Bitmap Heap Scan on grave_records  (cost=26.10..30.12 rows=1 width=1894) (actual time=4.378..4.646 rows=61.00 loops=1)
              Recheck Cond: ((cemetery_id = 'e7840ef1-8c52-483f-98df-1027f3d33c1f'::uuid) AND (((deceased_name_normalized)::text ~~ '%hend%'::text) OR ((deceased_name_normalized)::text % 'hend'::text)))
              Heap Blocks: exact=61
              Buffers: shared hit=274
              ->  BitmapAnd  (cost=26.10..26.10 rows=1 width=0) (actual time=4.336..4.338 rows=0.00 loops=1)
                    Buffers: shared hit=213
                    ->  Bitmap Index Scan on grave_records_cemetery_due_idx  (cost=0.00..4.51 rows=30 width=0) (actual time=0.115..0.115 rows=1000.00 loops=1)
                          Index Cond: (cemetery_id = 'e7840ef1-8c52-483f-98df-1027f3d33c1f'::uuid)
                    ->  BitmapOr  (cost=21.34..21.34 rows=107 width=0) (actual time=4.171..4.172 rows=0.00 loops=1)
                          Buffers: shared hit=211
                          ->  Bitmap Index Scan on grave_records_name_trgm_gist_idx  (cost=0.00..8.62 rows=47 width=0) (actual time=1.226..1.226 rows=1261.00 loops=1)
                                Index Cond: ((deceased_name_normalized)::text ~~ '%hend%'::text)
                          ->  Bitmap Index Scan on grave_records_name_trgm_gist_idx  (cost=0.00..12.72 rows=59 width=0) (actual time=2.944..2.944 rows=0.00 loops=1)
                                Index Cond: ((deceased_name_normalized)::text % 'hend'::text)
Planning Time: 0.183 ms
Execution Time: 4.850 ms

+---------------------------------+------------+
| Metric                          | Value (ms) |
+---------------------------------+------------+
| p50                             | 21.51      |
| p95                             | 32.11      |
| p99                             | 36.83      |
| record count (largest cemetery) | 1000       |
| iterations                      | 100        |
+---------------------------------+------------+
AC4 PASSED: p95 (32.11ms) is within the 500ms target.
```

Both the `~~ '%hend%'` (LIKE) and the `% 'hend'` (operator) branches of the
WHERE clause resolve to a `Bitmap Index Scan on grave_records_name_trgm_gist_idx`
— confirming the rewrite is genuinely index-usable, which the OLD
`similarity()`-function-call shape never was regardless of which trigram
index existed. p95 (32.11ms) is well within the 500ms AC4 target at this
scale; this is NOT a substitute for the deferred 100,000-record production
certification (`docs/planning/sprint-plan.md` §9).

## Verification

Real Postgres 18 + Redis 8.2, disposable containers prefixed `m7a-`
(`m7a-postgres`, `m7a-redis`, `m7a-app` running the pinned CI-parity image
`0d68f84b744c`, `vendor/` hard-linked from the trunk checkout per this
repo's worktree-test-env convention).

- `vendor/bin/pint --test` — PASS (0 issues after one `pint` auto-fix pass
  on 6 new/changed test files for import ordering).
- `php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress` — PASS,
  no errors.
- `bash ci/verify-docs.sh` (run on the host, not in the container — no
  Python/GD gap there) — PASS, all 13 gates.
- Full impacted test suites — PASS (see PR description for the exact
  command list and counts). Two PRE-EXISTING failures
  (`DocumentValidatorTest`'s `imagecreatetruecolor()` — GD extension not
  guaranteed in this specific ad hoc container build — and
  `DocumentVaultConfigurationTest`'s env-manipulation cases, which need a
  real `.env` file this ad hoc container setup does not have) were
  confirmed pre-existing by stashing this batch's changes and re-running
  the same tests against the identical container — NOT introduced by this
  batch.
- Every new test in this batch was mutation-verified (fix reverted inside
  the Docker container, test re-run, confirmed it fails; fix restored,
  confirmed it passes again) — see each finding's own "Test" section above
  for what specifically was mutated and the failure observed.

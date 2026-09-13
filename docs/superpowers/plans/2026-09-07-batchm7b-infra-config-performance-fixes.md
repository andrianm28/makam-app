# Batch M7b — infra/config performance fixes (PERF-06, 07, 09, 14, 15, 16)

Phase 3 audit remediation, per `docs/superpowers/plans/2026-09-06-remediasi-audit-makam.md`.
Branch: `fix/batchm7b-infra-config-performance-fixes`.

## PERF-06 — homepage menu-impression writes on every request

**Finding**: `HomePage::mount()` fires four synchronous
`MenuInteractionRecorder::impression()` calls (four separate `INSERT`s) on
every homepage view. The click side of the same feature (AC9) was never
wired up, so today the table is write-only with no reader anywhere in the
codebase (confirmed: no controller, report, Filament resource, or query
class reads `menu_interaction_events`/`MenuInteractionEvent` — grep turns up
only the writer, the model, the migration, and tests asserting the writer's
own behaviour).

**Decision: (b), not (a).** Despite having no reader today, this is a
recently-shipped, spec-cited feature (`.kiro/specs/public-home-and-navigation/
requirements.md` AC9), pinned by two existing feature tests
(`HomePageRouteTest::test_viewing_the_homepage_records_menu_impressions_
without_sensitive_data`, `ReportContentSecurityPolicyTest`'s own doc block).
Deleting the write path outright would silently regress a named acceptance
criterion and require deleting/rewriting passing tests just to make the
audit fix look clean — a bigger, riskier change than moving the write off
the request path. AC9 is satisfied either way; only the transport changes.

**Fix**: batch the four writes into ONE dispatched job
(`App\Jobs\RecordMenuImpressions`, `ShouldQueue`, explicit `$queue =
'default'`) carrying all four `(menuKey, route)` pairs. The job performs a
SINGLE batched `MenuInteractionEvent::insert($rows)` (one INSERT statement,
four rows) instead of four separate `Eloquent::create()` round trips.
`HomePage::mount()` now dispatches the job instead of calling
`MenuInteractionRecorder::impression()` directly; `MenuInteractionRecorder`
gains `impressions(array $menus)` for the batched insert, used only by the
job. `MenuInteractionRecorder::impression()`/`click()` stay in place
(single-row helpers) since `click()` remains a real, intentional gap per its
own doc block, and both are covered by direct unit-style tests.

In `config/queue.php`'s default (`database`) connection, dispatching still
does one synchronous write (to `jobs`), but that is one row, not four, and
it moves the actual event write to whatever consumes the queue — `sync` in
tests (so existing tests still see the rows appear immediately, no test
rewrite needed) and a real worker in dev/stg/prod.

**Retention**: `MenuInteractionEvent` gains `Illuminate\Database\Eloquent\
Prunable` (`prunable()`: `occurred_at < now()->subDays(180)` — six months is
long enough for the anonymous-count use case this table is scoped for, per
its own migration doc block, while guaranteeing the table stops growing
unbounded). `routes/console.php` gains `Schedule::command('model:prune',
['--model' => [MenuInteractionEvent::class]])->daily()` alongside the
existing scheduled jobs there.

## PERF-07 — cache/session default to `database`

**Finding**: `config/cache.php:18` (`CACHE_STORE`) and `config/session.php:21`
(`SESSION_DRIVER`) both default to `database`, so absent an explicit env
override the rate limiter (and every other cache/session read) serializes on
a single Postgres table row under load — real contention, and inconsistent
with `docs/operations/redis-hardening.md` §4.1–4.2, which already documents
Redis (with `REDIS_PREFIX`/`CACHE_PREFIX` isolation) as the intended
production setup.

**Fix**: change the shipped DEFAULTS only —
`env('CACHE_STORE', 'redis')` and `env('SESSION_DRIVER', 'redis')`. Both
configs already read `REDIS_PREFIX`/`CACHE_PREFIX`/connection settings from
env with no code change needed elsewhere; `config/database.php`'s existing
`redis` connection block (referenced by redis-hardening.md §4.1) is the one
these will use unmodified.

**Explicitly NOT done, and NOT this agent's to do**: the live `.env.beta`/
`.env.stg` files on the `makam-nonprod` host are not touched by this batch.
Per `AGENTS.md` §Infrastructure-agent execution, changing a live,
production-affecting environment file requires human review/execution — a
human operator must set `CACHE_STORE=redis` and `SESSION_DRIVER=redis` (and
confirm `REDIS_PREFIX`/`CACHE_PREFIX` per redis-hardening.md §4.1–4.2) on
the deployed host directly. This PR only changes the checked-in config
defaults that apply when no env override is present (local/CI/any future
environment that hasn't set these vars yet); it does not change what is
currently running on `dev`/`stg`, whose containers already carry their own
`.env.*` files with (per `ci/verify-infra.sh` and the redis-hardening doc)
their own explicit values.

`AppServiceProvider.php:106`'s `RateLimiter::for('public-guest', ...)`
reads/writes through the default cache store implicitly via Laravel's rate
limiter facade — no code change needed there; it inherits whatever
`CACHE_STORE` resolves to.

## PERF-09 — `supervisor-normal` runs 4 processes instead of the documented 2

**Finding**: `config/horizon.php`'s `staging` environment sets
`supervisor-normal` to `['minProcesses' => 1, 'maxProcesses' => 2]`, but the
supervisor's `defaults` entry (line ~281) also carries `'balance' =>
'auto'` over 4 queues. Traced through
`vendor/laravel/horizon/src/Supervisor.php::createProcessPools()` and
`AutoScaler::numberOfWorkersPerQueue()`: `balance = 'auto'` makes
`createProcessPools()` build ONE PROCESS POOL PER QUEUE
(`createProcessPoolPerQueue()`), and when all 4 queues are idle
(`timeToClearAll == 0`), the autoscaler assigns `minProcesses` — 1 —
INDEPENDENTLY to each of the 4 pools, ignoring `maxProcesses` for that idle
branch entirely. Net effect: 4 resident processes at idle, not 2 — exactly
ADR-0027 condition 4's "staging runs a maximum of two normal Horizon worker
processes" violated in practice, even though `maxProcesses => 2` reads as
if it caps the total.

**Decision: keep one supervisor, set `'balance' => 'off'`** (not the
literal `'false'` string/boolean the finding brief suggested — verified
against `Laravel\Horizon\SupervisorOptions::balancing()`, which only treats
`'simple'` and `'auto'` as balancing; `'off'` is the actual sentinel that
means "not balancing" and is also the class's own documented default).
Rejected the per-queue-supervisors-summing-to-2 alternative: it would need
each of the 4 queues (`critical`, `urgent`, `notifications`, `default`) to
get its own supervisor with a fractional share of 2 processes, which is not
integer-representable across 4 queues without starving at least 2 of them
to `maxProcesses => 0` — defeating the purpose of running all 4 queues on
staging at all. `balance => 'off'` makes
`Supervisor::createProcessPools()` build a SINGLE pool over the
comma-joined queue string (confirmed in `createSingleProcessPool()`), so
`AutoScaler`'s non-balancing branch (`min($maxProcesses, max($minProcesses,
$queueSize))`) applies ONE cap to the whole supervisor — literally "a pool
capped at two processes" for the combined queue list, which is what the
finding and ADR-0027 condition 4 actually intend.

**Fix**: `config/horizon.php` — `supervisor-normal`'s `defaults` entry:
`'balance' => 'off'` (was `'auto'`); its `autoScalingStrategy` key is now a
no-op under `'off'` and is removed accordingly (keeping a stale key that
does nothing under the new balance mode would be misleading, not
harmless). `minProcesses` is added to the `defaults` entry too (`1`, so the
supervisor never runs at `0` before an environment override applies) since
`defaults` block previously relied on `autoScaling`'s idle behaviour instead
of an explicit floor. The `staging` environment override
(`['minProcesses' => 1, 'maxProcesses' => 2]`) is unchanged and now behaves
as documented: 1–2 total resident processes, shared across all four
staging queues by wait time (`FIFO` order within the single pool, standard
Horizon queue-array behaviour), not 4.

## PERF-14 — row-level financial report data in public Livewire state

**Finding**: `ReceiptsReportPanel`/`OutgoingPaymentsReportPanel` hold
`public array $reportRows` and `public int $totalMinor` — full row-level
financial data in component state that round-trips through Livewire's
client-visible snapshot on every request. The CSV export also builds the
entire `$lines` array in memory before streaming.

**Fix**:
- `$reportRows` is removed as a public property entirely. Row data (capped
  at `MAX_DISPLAY_ROWS = 500` per panel) is now computed inside `render()`
  and passed straight to the Blade view via `view(...)->with([...])` —
  never stored on the component, never serialized into the Livewire
  snapshot.
- `$totalMinor` and `$generatedAt` are likewise computed in `render()` and
  passed via the view, not stored as public properties — they are
  DERIVED from the same query as the rows and have no reason to survive
  as component state between requests either.
- `$error` remains a public property (it interacts with Livewire's
  validation/error-bag machinery, which expects component state), but is
  now marked `#[Locked]` — a client update attempt to it is rejected by
  Livewire itself. `$period` and `$entityRef` stay public and UNLOCKED
  because they are genuine two-way-bound form inputs the operator is
  meant to change.
- `loadReport()` is kept as the `wire:click` target for the "Tampilkan"
  button (submitting the bound `$period`/`$entityRef` and forcing a
  re-render), but no longer stores results on `$this` — `render()` now
  owns the query + error-handling logic previously duplicated between
  `loadReport()` and `exportCsv()`.
- A NEW, separate `cursor()`-based path is added for CSV export:
  `CashReceiptsReport::cursor()` / `PayoutSummaryReport::cursor()` return a
  `LazyCollection` (`DB::table(...)->orderBy(...)->cursor()` /
  `Payout::query()->orderBy(...)->cursor()`) instead of `->get()`. The
  panels' `exportCsv()` methods stream each row through
  `response()->streamDownload()` as it is pulled off the cursor, so the
  full result set is never materialized in PHP memory during export — only
  the currently-open DB cursor plus one row buffer. NOTE: the cursor path
  intentionally does NOT reproduce `summary()`'s PHP-side
  `sortRowsDeterministically()` — that method exists purely to normalize
  cross-database (SQLite-in-CI vs Postgres-in-prod) string-collation
  differences for BYTE-EXACT test assertions on the on-screen JSON-shaped
  result; the CSV cursor instead sorts at the database level
  (`ORDER BY occurred_at, business_key`/`id`), which is deterministic
  enough for a downloaded file and does not require materializing the full
  set to sort in PHP. This is a deliberate, documented behavior difference
  between the on-screen (paginated, capped, app-sorted) and CSV
  (unbounded, cursor-streamed, DB-sorted) paths — both existed as separate
  code paths before this fix already (`summary()` vs the old in-memory
  `exportCsv()` loop); only the CSV path's data-acquisition strategy
  changes.

Existing tests (`ReceiptsReportPanelTest`, `OutgoingPaymentsReportPanelTest`)
assert against `reportRows`/`totalMinor` as Livewire component properties
via `assertCount()`/`assertSet()`. Since those properties no longer exist,
these tests are rewritten to assert against rendered HTML content
(`assertSee()` on formatted amounts/row values, `assertDontSee` for the
empty state) instead — same behavioural coverage, no longer coupled to
component internals that are now deliberately private.

## PERF-15 — no gzip, no cache headers in the nginx stack

**Fix**: `docker/nginx.conf`'s `http {}` block gains:
```
gzip on;
gzip_vary on;
gzip_min_length 1024;
gzip_types text/plain text/css application/javascript application/json image/svg+xml application/xml;
```
and the `server {}` block gains a `location ^~ /build/ { expires 1y;
add_header Cache-Control "public, immutable"; }` block ahead of the general
`location /` — `/build/` is confirmed (via `vite.config.js`, which does not
override Laravel/Vite's default `publicDirectory`/`buildDirectory`) as the
real Vite output path (content-hashed filenames under
`public/build/assets/...`), safe to cache aggressively and immutably.

The two documented reverse-proxy examples
(`docs/operations/examples/nginx/dev.makam.co.id.conf`,
`stg.makam.co.id.conf`) get the matching `gzip` directives (server-context,
since these files are bare `server {}` blocks with no enclosing `http {}`
of their own — `gzip` is valid at `http`, `server`, or `location` context)
plus a `location ^~ /build/` block that proxies to the same upstream as
`location /` but adds the same `Cache-Control`/`expires` headers, so a
build asset served through the front reverse-proxy on dev/stg gets the same
caching treatment the app container's own nginx already gives it directly.

## PERF-16 — unbounded cemetery block capacity

**Fix**: `CreateCemeteryBlock::__invoke()` gains an explicit upper bound
next to the existing `< 1` guard: `$capacity > self::MAX_CAPACITY` throws
the same `InvalidArgumentException` family. `MAX_CAPACITY = 10_000`.
**Reasoning for 10,000**: no cemetery block in this codebase's seed data,
tests, or product docs approaches four digits of plots — Indonesian
cemetery blocks are physically bounded by land area, and a single "blok"
in the existing `docs/product` examples and seed fixtures is described in
the tens to low hundreds. 10,000 is generous enough that no legitimate
operator input is ever rejected, while still bounding a single request's
plot-row materialization to a fixed, small multiple of what any real block
will ever need — and 10,000 rows × 6 columns (60,000 bind params) would
already have been comfortably within a single Postgres statement's bind
limit (65,535) even before chunking, so the chunking fix (next) is the
actual OOM/bind-limit guard, and the capacity cap is a sanity/abuse
guard on top of it, not a substitute for it.

Plot-row insertion is changed from one `GravePlot::query()->insert($plotRows)`
call over the whole array to `array_chunk($plotRows, 1000)` looped inserts
inside the same `Audit::wrap()` mutation closure (same transaction, so the
whole block/plot creation is still atomic — a failure partway through any
chunk rolls back everything, matching current all-or-nothing behavior).

The Filament form field (`BlocksRelationManager::form()`,
`TextInput::make('capacity')`) gains `->maxValue(CreateCemeteryBlock::MAX_CAPACITY)`
alongside its existing `->minValue(1)`, so the UI rejects an over-limit
value before it ever reaches the action (the action's own guard is the
authoritative floor for any non-Filament caller, e.g. a future API/import
path).

## Verification plan

- `vendor/bin/pint --test`
- `vendor/bin/phpstan analyse --no-progress` (memory limit bumped)
- `bash ci/verify-docs.sh`
- Targeted `vendor/bin/phpunit` runs against real Postgres/Redis (disposable
  `m7b-pg`/`m7b-redis` containers) for every touched test file:
  `HomePageRouteTest`, `ReportContentSecurityPolicyTest`,
  `ReceiptsReportPanelTest`, `OutgoingPaymentsReportPanelTest`,
  `CreateCemeteryBlockTest`, plus any new tests for
  `RecordMenuImpressions`/`MenuInteractionEvent` pruning.
- `php artisan config:show horizon` is NOT run against a live Horizon
  process (no live staging Horizon accessible from this worktree) — the
  `Supervisor`/`AutoScaler` source-reading above is the verification for
  PERF-09; flagged as design-verified, not runtime-verified, in the final
  report.

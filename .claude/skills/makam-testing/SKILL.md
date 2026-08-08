---
name: makam-testing
description: How tests are actually written in this repository — no factories for domain data, seeded tables that are never pristine, feature gates that seed closed, withoutVite for real HTTP, the SQLite-local vs Postgres-CI split, and the adversarial/negative genres. Use before adding or changing any test, when a test passes locally but fails in CI, when a test needs domain rows, or when writing the regression test a bug fix requires.
---

# Testing

`docs/testing/test-strategy.md` is the canonical strategy — risk layers (§1), the required per-domain tests (§3), test-data rules (§5), traceability (§6). It governs *what* to test. This skill is the *how*, which exists only as tacit convention across the suite. `makam-verify` owns proof and honesty; do not duplicate it here.

**Verified 8 Aug 2026**: 90 `tests/**/*Test.php` files, 503 test methods.

## Shape

Every test class is `final class` with `declare(strict_types=1)` — the sole exception is `tests/TestCase.php`, a bare abstract scaffold with no body. Methods are `public function test_snake_case_full_sentence(): void`; all 503 follow it. **There is not one `#[Test]` attribute and not one camelCase test method in the suite** — do not introduce either. `#[DataProvider]` (attribute form, PHPUnit 10+) is used for table-driven closed-list cases in 7 files, e.g. `tests/Unit/Platform/IdentityAccess/Mfa/Totp/TotpRfc6238VectorsTest.php` and `tests/Feature/Domain/CemeteryCapability/CemeteryCapabilityModeClosedListTest.php`.

`RefreshDatabase` is used by 62 of the 90. The 28 without it are value-object, enum, and RFC-vector tests (`Base32Test`, `HotpRfc4226VectorsTest`, `StatusIntentTest`, the five `Modes/*Test`), plus a few container-binding and Blade-render tests under `tests/Feature/` that need the app but no rows.

## Data comes from seed migrations and real Actions — never factories

`database/factories/` holds exactly one factory, `UserFactory`, and its only job is standing up an actor for `actingAs()` (86 call sites, all `User::factory()`). **Never write `Model::factory()` for domain data.** There are no domain factories and adding one is a change of convention, not a convenience.

Domain rows come from two places:
- The seed migrations in `database/migrations/*seed*.php`, which `RefreshDatabase` runs before every test method.
- The real domain Action, called directly: `(new CreateFaqArticleDraft)(…)`, `(new RecordServiceDefinitionPriceVersion)(…)`, `(new PublishServicePackageVersion)(…)`. Prefer this over a bare `Model::create()` — it exercises the invariant you are relying on.

## The table is never pristine

Because migrations seed, a test must never assume an empty or single-page table. Two idioms:

**Exact known seed totals** — `$this->assertDatabaseCount('cemeteries', 10)`, `$this->assertDatabaseCount('faq_articles', 23)`. Correct only when the seed count is itself the thing under test.

**Additive counting or searching down to your own rows.** `tests/Feature/Livewire/Public/Faq/FaqIndexRouteTest.php` takes `$countBefore = FaqArticle::published()->count()` and asserts against that. `tests/Feature/Domain/ServiceCatalog/PriceVersioningTest.php` clears a service's pre-existing seeded price so "first version" still means first. And `tests/Feature/Filament/Admin/Faq/FaqArticleListPageTest.php` uses `->searchTable('Artikel')` before asserting visibility, for a reason worth quoting because a real CI failure produced it:

> The real seed migration already populates 23 articles across every category, so with the table's default page size these two freshly-created (highest-id) records are not guaranteed to land on the first page. Search down to just these two … rather than assuming a pristine, single-page table.

## Assertion idioms

`assertSame` throughout — 466 uses against 2 `assertEquals`, and the one exception documents itself (`tests/Feature/FeatureGate/GateActivationRecorderTest.php`: `outbox_events.payload` is Postgres `jsonb`, which does not preserve object key order, and PHP array `===` is key-order sensitive; first real Postgres CI run caught it). Also standard: `assertDatabaseHas`/`Missing`/`Count`, `->sole()` for "exactly one row must match" (36 uses), `expectException` (56 uses).

**The footgun**, recorded verbatim in `tests/Feature/Domain/CemeteryDirectory/CemeterySeedTest.php`:

> `assertDatabaseCount()`'s third parameter is a connection name, not a where-conditions array (`Illuminate\Foundation\Testing\Concerns\InteractsWithDatabase::assertDatabaseCount()`) — a plain count query is the correct way to assert a filtered count.

So a filtered count is `$this->assertSame(9, Cemetery::query()->where(...)->count())`, never a third argument.

## Flipping a feature gate

`2026_07_26_120400_seed_feature_gate_registry.php` inserts **every** gate with `'state' => 'closed'`, and the column defaults to `closed`. A test that never flips a gate is testing only the closed branch — say so or flip it. The idiom is one line:

```php
FeatureGate::query()->where('gate_id', 'G-XXX-01')->update(['state' => 'open']);
```

Real examples: `tests/Feature/Livewire/Public/HomePageRouteTest.php` (`G-OPS-01`, then asserts the urgent banner disappears) and `tests/Feature/FeatureGate/FeatureGateResolverTest.php` (`G-PAY-01`, for snapshot-caching semantics).

## Livewire and real HTTP

Real HTTP (`$this->get('/path')`) for route, status, and rendered-HTML assertions. `Livewire::test(…)->set(…)->call(…)` for internal state, validation, and idempotency. See `makam-livewire-page` for the component side.

```php
protected function setUp(): void
{
    parent::setUp();
    $this->withoutVite();
}
```

**Required for any test making a real HTTP request that renders a layout containing `@vite(...)`** — CI's `php` job has no frontend build, and without this you get "Vite manifest not found". This was a real CI failure, fixed by discovering `withoutVite()`, not by pre-building assets. 12 files carry it — every `Livewire/Public/**` route test, every `Filament/Admin/**` page test, and `AdminPanelHttpAccessTest`. `Livewire::test()`-only tests do not need it.

## The biggest trap: SQLite locally, Postgres in CI

`phpunit.xml` sets `DB_CONNECTION=sqlite` and `DB_DATABASE=:memory:`. `.github/workflows/ci.yml` runs `php artisan test` with `DB_CONNECTION: pgsql` against a `postgres:18` service. Behaviour that exists only on Postgres is guarded:

```php
if (DB::connection()->getDriverName() !== 'pgsql') {
    $this->markTestSkipped('…');
}
```

Four files do this: `tests/Feature/Audit/AuditRecordTest.php` (the `audit_events_outcome_check` `CHECK` constraint — SQLite's `ALTER TABLE` cannot add one at all) and the three Outbox tests `OutboxPublisherClaimTest`, `OutboxRecoveryTest`, `OutboxQueueRoutingTest` (`SELECT … FOR UPDATE SKIP LOCKED`).

The consequence: **a test can pass locally by silently skipping.** A green local run is not evidence those paths work. CI is the oracle — `makam-verify`.

## Adversarial and negative tests are first-class here

Three shapes, each with a reference file:

- **Cross-scope denial** — `tests/Feature/IdentityAccess/Scopes/ScopeAssignmentGlobalScopeTest.php`. Same actor, same request, only the identifier changes: `test_actor_cannot_reach_an_ungranted_row_by_changing_the_identifier`, plus closed-by-default for a zero-grant actor, revoked grants, and grants that must not leak across actors or entity types.
- **Tamper resistance** — `tests/Feature/FeatureGate/ClientSideTamperingCannotOpenAGateTest.php`. Behavioural: a maximally hostile `Request` (query string, header, *and* cookie all claiming the gate is open) proven to have zero effect. Structural: a `ReflectionClass` walk of the constructor proving the resolver has no request-shaped dependency at all, so no such code path could exist.
- **Append-only bypass** — `tests/Feature/Audit/AuditEventAppendOnlyTest.php`.

## Tests may assert that a known gap still exists

This is the convention that makes the suite unusual, and it is deliberate. `AuditEventAppendOnlyTest::test_query_builder_mass_update_bypasses_the_model_level_guard_this_is_the_documented_ac1_gap` asserts the bypass **succeeds** — `assertSame(1, $affected)`. Its doc block:

> This test currently asserts the bypass SUCCEEDS. That is not this test endorsing the gap — it is this test refusing to let the gap go unnoticed.

Same instinct applied to the harness itself: `tests/Feature/Outbox/OutboxPublisherClaimTest.php` documents at length why genuine cross-session `SKIP LOCKED` contention **cannot** be proven here. `RefreshDatabase` wraps each method in an outer transaction on one connection that never commits, so a second raw `DB::connection()` sees an empty table under Postgres MVCC — it would prove nothing about AC5. The file states exactly what it does prove (WHERE/lock semantics, stale-claim reclaim, no double-claim across two *sequential* runs) and what it does not. Write the limitation down; do not fake the coverage.

## `tests/Fixtures/`

`ScopedTestModel`, `OutboxFixtureAggregate`, `CorrelatedTestJob` and their two `Creates*Table` traits exist because `app/Domain/**` was largely empty when the cross-cutting mechanisms (scopes, outbox, correlation) were built. Their tables are created ad hoc in `setUp()` via a `Schema::hasTable()`-guarded `Schema::create()` — **not** a shipped migration — because the single persistent `:memory:` connection means the table is created once per run, not once per method.

A fixture is the right call when you must prove a *mechanism* works against an arbitrary model and no real domain model exists yet. It is the wrong call the moment a real domain model does exist — use it. And never fabricate the concept into `app/`: a fake domain model there is autoloaded in production and becomes something a later spec has to reconcile with. `tests/Fixtures/` is `autoload-dev` only, so nothing ships.

## Regression tests

`AGENTS.md` §Testing: *"Every bug fix requires regression test."* Not optional, and the test must fail against the unfixed code.

<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CareSubscription;

use App\Domain\CareSubscription\Actions\GenerateCycle;
use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\CareSubscription\SubscriptionCycleStatus;
use App\Domain\CareSubscription\SubscriptionStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ARCH-04 remediation
 * (`docs/superpowers/plans/2026-09-07-batchm5a-domain-action-extraction.md`)
 * — the real PostgreSQL race.
 *
 * `GenerateCycle`'s `UniqueConstraintViolationException` recovery path used
 * to sit INSIDE the `DB::transaction` `Audit::wrap()` opens, with no
 * savepoint. On PostgreSQL a unique violation (23505) aborts the whole
 * enclosing transaction, so the recovery `SELECT` that ran while still
 * inside it raised 25P02 ("current transaction is aborted") instead of
 * finding the incumbent row — the recovery path was completely
 * unreachable. This test proves it now works, by forcing a REAL
 * PostgreSQL 23505 to fire during `GenerateCycle`'s own `INSERT` and
 * asserting the method returns the incumbent row instead of raising 25P02.
 *
 * ---------------------------------------------------------------------------
 * Why this class does NOT use `RefreshDatabase`
 * ---------------------------------------------------------------------------
 * Same reasoning as `OrderWorkflow\RecordOrderStatusChangeTwoConnectionTest`
 * (read that class's doc block first): `RefreshDatabase` wraps the test body
 * in an uncommitted transaction on the default connection, so a fixture
 * created there (the `Subscription`/`CarePlan` rows, and the competing
 * `subscription_cycles` row this test inserts on a SECOND connection) would
 * be invisible cross-connection, and the competing row's `subscription_id`
 * foreign key could never be satisfied. This class owns its own schema
 * lifecycle instead: no wrapping transaction, `migrate:fresh` after the
 * body to wipe the real committed rows before any later `RefreshDatabase`
 * class in the same process runs (mirrors the precedent class exactly).
 *
 * ---------------------------------------------------------------------------
 * How the race is forced deterministically, without true concurrency
 * ---------------------------------------------------------------------------
 * A `SubscriptionCycle::creating()` hook fires exactly once, at the moment
 * `GenerateCycle`'s own `SubscriptionCycle::query()->create(...)` is about
 * to issue its `INSERT` — strictly AFTER `GenerateCycle`'s idempotency
 * pre-check has already run and found nothing. From inside that hook, a
 * SECOND, independent PostgreSQL connection inserts and immediately commits
 * (autocommit, no wrapping transaction) a competing row with the SAME
 * `(subscription_id, cycle_start, cycle_end)` key — a real "another writer
 * already committed this cycle a moment ago" fact, on a connection
 * independent of `GenerateCycle`'s own transaction so it SURVIVES that
 * transaction's rollback. `GenerateCycle`'s own `INSERT`, issued immediately
 * afterwards on the default connection, then collides against that
 * already-committed row with a genuine 23505 — exactly the pathology the
 * bug report describes — and the recovery `SELECT` in the fixed code must
 * find that surviving row.
 */
final class GenerateCycleRaceTest extends TestCase
{
    public function test_the_recovery_path_returns_the_incumbent_cycle_after_a_real_unique_violation(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'The 23505-aborts-the-transaction / 25P02 pathology this test proves is fixed only reproduces on PostgreSQL.'
            );
        }

        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);

        try {
            $subscription = $this->createSubscription();
            $cycleStart = CarbonImmutable::parse('2026-09-01');
            $cycleEnd = CarbonImmutable::parse('2026-09-30');
            $incumbentId = (string) Str::uuid();

            SubscriptionCycle::creating(function () use ($subscription, $cycleStart, $cycleEnd, $incumbentId): void {
                DB::connection('pgsql_race')->table('subscription_cycles')->insert([
                    'id' => $incumbentId,
                    'subscription_id' => $subscription->getKey(),
                    'cycle_start' => $cycleStart->toDateString(),
                    'cycle_end' => $cycleEnd->toDateString(),
                    'status' => SubscriptionCycleStatus::Scheduled->value,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                // Fire once only — the ONLY `SubscriptionCycle` create in
                // this test is `GenerateCycle`'s own.
                SubscriptionCycle::flushEventListeners();
            });

            $cycle = app(GenerateCycle::class)($subscription, $cycleStart, $cycleEnd);

            self::assertSame($incumbentId, $cycle->getKey());
            self::assertSame(
                1,
                SubscriptionCycle::query()
                    ->where('subscription_id', $subscription->getKey())
                    ->where('cycle_start', $cycleStart->toDateString())
                    ->where('cycle_end', $cycleEnd->toDateString())
                    ->count(),
                "Exactly one cycle must exist — GenerateCycle's own aborted attempt must not have left a second row."
            );
        } finally {
            SubscriptionCycle::flushEventListeners();
            DB::purge('pgsql_race');

            // Wipe the committed rows so later `RefreshDatabase` test
            // classes in this same process start from an empty, migrated
            // schema (they begin a transaction, they do not re-migrate —
            // see the class doc block).
            Artisan::call('migrate:fresh');
        }
    }

    private function createSubscription(): Subscription
    {
        $carePlan = CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Perawatan Bulanan Standar',
            'product_code' => 'GRAVE_CARE_MONTHLY',
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'checklist_template' => ['membersihkan makam'],
            'status' => 'active',
        ]);

        return Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => User::factory()->create()->id,
            'status' => SubscriptionStatus::Active->value,
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => $carePlan->price_minor,
            'currency' => 'IDR',
            'current_cycle_number' => 0,
            'started_at' => now()->subMonths(2),
        ]);
    }
}

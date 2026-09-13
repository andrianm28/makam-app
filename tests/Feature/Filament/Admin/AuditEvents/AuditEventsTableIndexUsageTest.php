<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\AuditEvents;

use App\Filament\Admin\Resources\AuditEvents\Pages\ListAuditEvents;
use App\Models\User;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * PERF-10 (Phase 3 Batch M7a). `AuditEventsTable`'s `occurred_at` date-range
 * filter used to wrap the indexed column in `whereDate('occurred_at', ...)`
 * — `DATE(occurred_at) >= ?` — which cannot use a plain b-tree index on
 * `occurred_at` (a function applied to the indexed column defeats an
 * ordinary index scan). This proves the generated SQL no longer wraps the
 * column, and that a real Postgres `EXPLAIN` on the equivalent query
 * chooses an index scan on `audit_events_occurred_at_index` rather than a
 * sequential scan.
 *
 * `AuditEventsTableTest` already covers that filtering STILL RETURNS the
 * right rows — this file exists because that alone proves nothing about
 * the query SHAPE, which is what PERF-10 actually is.
 *
 * BACK-DATING CONVENTION (OBS-01, Phase 3 Batch M8a). Both tests below need
 * `audit_events` rows with an `occurred_at` in the past. They used to insert
 * a row and then `UPDATE` its `occurred_at`. That is no longer possible, and
 * deliberately so: the `audit_events_append_only` trigger added by
 * `2026_09_07_100000_enforce_audit_events_append_only.php` raises SQLSTATE
 * 42501 on ANY `UPDATE` or `DELETE` against this table, including one issued
 * by a test. Rewriting audit history is exactly the thing that migration
 * exists to prevent, so the fixture does what production code would have to
 * do — record the event at the time it is supposed to have happened, via
 * `travelTo()` around `Audit::record()`. Do not reintroduce an `UPDATE` here
 * or add a trigger bypass for tests; that would make the append-only control
 * untrue for the one suite that would notice it breaking.
 */
final class AuditEventsTableIndexUsageTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function admin(): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        return $user;
    }

    public function test_the_occurred_at_filter_no_longer_wraps_the_column_in_a_date_function(): void
    {
        $inRange = Audit::record(
            action: 'booking.updated',
            subject: new AuditSubject(type: 'booking', id: 1),
            outcome: AuditOutcome::Allowed,
            actorRef: 1,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        // Recorded AS OF 30 days ago rather than recorded now and then
        // back-dated with an UPDATE: `Audit::record()` stamps `occurred_at`
        // from `CarbonImmutable::now()`, so travelling makes the row
        // genuinely old at INSERT time. See the class doc block for why an
        // UPDATE is no longer an option here.
        $outOfRange = $this->travelTo(
            CarbonImmutable::now()->subDays(30),
            static fn (): AuditEvent => Audit::record(
                action: 'booking.updated',
                subject: new AuditSubject(type: 'booking', id: 2),
                outcome: AuditOutcome::Allowed,
                actorRef: 1,
                actorRole: 'admin',
                source: AuditSource::Panel,
            ),
        );

        $this->admin();

        $queries = [];
        DB::listen(function ($query) use (&$queries): void {
            $queries[] = $query;
        });

        Livewire::test(ListAuditEvents::class)
            ->filterTable('occurred_at', ['occurred_from' => CarbonImmutable::now()->subDay()->toDateString()])
            ->assertCanSeeTableRecords([$inRange])
            ->assertCanNotSeeTableRecords([$outOfRange]);

        $occurredAtQueries = array_filter(
            $queries,
            static fn ($query): bool => str_contains($query->sql, 'occurred_at')
                && str_contains($query->sql, 'audit_events'),
        );

        $this->assertNotEmpty($occurredAtQueries, 'Expected at least one query filtering on occurred_at.');

        foreach ($occurredAtQueries as $query) {
            $this->assertStringNotContainsStringIgnoringCase(
                'date(',
                $query->sql,
                "Expected no DATE(...) wrapping around occurred_at, got: {$query->sql}",
            );
        }
    }

    /**
     * Real Postgres EXPLAIN evidence, not just "the SQL looks right": the
     * half-open range this fix produces (`occurred_at >= ? AND occurred_at
     * < ?`) must let the planner choose an index scan on
     * `audit_events_occurred_at_index` for a date-scoped query, where the
     * OLD `whereDate()` shape forced a sequential scan regardless of how
     * many rows existed. Skipped outside Postgres — `EXPLAIN`'s plan
     * vocabulary ("Index Scan", "Seq Scan") is driver-specific and this
     * repository's default local test driver is SQLite.
     */
    public function test_explain_shows_an_index_scan_on_occurred_at_not_a_sequential_scan(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('EXPLAIN plan shape assertions require PostgreSQL.');
        }

        for ($i = 0; $i < 50; $i++) {
            // Same reason as the first test: each row is recorded AS OF its
            // own day rather than inserted now and back-dated afterwards.
            $this->travelTo(
                CarbonImmutable::now()->subDays($i),
                static function () use ($i): void {
                    Audit::record(
                        action: 'booking.updated',
                        subject: new AuditSubject(type: 'booking', id: $i),
                        outcome: AuditOutcome::Allowed,
                        actorRef: 1,
                        actorRole: 'admin',
                        source: AuditSource::Panel,
                    );
                },
            );
        }

        // A generous ANALYZE so the planner's row-count estimate reflects
        // the 50 rows just inserted rather than stale (possibly zero)
        // statistics, which would bias it toward a sequential scan for a
        // reason unrelated to what this test is proving.
        DB::statement('ANALYZE audit_events');

        // `SET LOCAL` (not `SET`): RefreshDatabase wraps each test in a DB
        // transaction, so this resets automatically at the end of the test
        // rather than leaking into other tests sharing the connection.
        // Disabling sequential scans is what actually proves the predicate
        // is INDEXABLE: on a 50-row table the cost-based planner would
        // often prefer a sequential scan regardless of what index exists
        // (a real table is 100,000+ rows — `docs/testing/release-gates.md`
        // §H — where the planner's own choice would settle this without
        // help). Forcing the choice here is what makes this assertion
        // meaningful at a 50-row test-database scale: if the old
        // `whereDate()` shape were still in place, Postgres would have NO
        // indexable plan available and would be forced back to a
        // sequential scan even with `enable_seqscan = off`.
        DB::statement('SET LOCAL enable_seqscan = off');

        $from = CarbonImmutable::now()->subDays(10)->startOfDay();
        $until = CarbonImmutable::now()->subDays(5)->addDay()->startOfDay();

        $plan = collect(DB::select(
            'EXPLAIN SELECT * FROM audit_events WHERE occurred_at >= ? AND occurred_at < ?',
            [$from, $until]
        ))->map(fn ($row) => $row->{'QUERY PLAN'})->implode("\n");

        $this->assertStringContainsString(
            'Index',
            $plan,
            "Expected an index scan to be available in the plan, got:\n{$plan}",
        );
        $this->assertStringNotContainsString(
            'Seq Scan on audit_events',
            $plan,
            "Expected no sequential scan on audit_events once seqscan is disabled, got:\n{$plan}",
        );
    }
}

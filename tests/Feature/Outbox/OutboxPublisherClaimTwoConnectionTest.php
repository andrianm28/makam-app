<?php

declare(strict_types=1);

namespace Tests\Feature\Outbox;

use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use App\Platform\Outbox\OutboxPublisher;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The genuine cross-session `SELECT ... FOR UPDATE SKIP LOCKED` proof
 * `OutboxPublisherClaimTest.php`'s own doc block names as missing:
 * "Building genuine cross-session proof would need a non-transactional
 * test harness... out of scope for this 'minimum outbox' batch." This is
 * that harness.
 *
 * `RefreshDatabase` cannot be used, for the same reason that class's doc
 * block explains: a second real connection cannot see an outer test
 * transaction's uncommitted rows under Postgres MVCC. This test commits
 * real fixture rows, then a load-bearing trailing `Artisan::call
 * ('migrate:fresh')` wipes them — matching every other `*TwoConnection*`
 * test in this codebase.
 *
 * Unlike `ReservePlotTwoConnectionTest.php`'s sequential commit-then-
 * observe pattern, THIS test genuinely overlaps two transactions: the
 * first connection opens a transaction and runs `OutboxPublisher::
 * CLAIM_QUERY` directly (the exact SQL `OutboxPublisher::claim()` uses,
 * exposed as a public constant specifically so a test can run it without
 * duplicating it — see that class's own doc block), WITHOUT committing —
 * so the claimed row(s) stay locked. Only THEN does the second connection
 * run the identical query, while the first connection's transaction is
 * still open, proving `SKIP LOCKED` genuinely excludes an in-flight
 * claim, not merely an already-committed one.
 */
final class OutboxPublisherClaimTwoConnectionTest extends TestCase
{
    public function test_a_second_connections_concurrent_claim_never_overlaps_the_firsts_in_flight_claim(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SELECT ... FOR UPDATE SKIP LOCKED is only meaningful on real Postgres.');
        }

        $rowIds = [];

        for ($i = 0; $i < 4; $i++) {
            $event = Outbox::record(
                eventName: 'fixture.race_test.v1',
                eventVersion: 1,
                aggregateType: 'fixture',
                aggregateId: $i,
                data: ['note' => 'outbox-race-test'],
                classification: OutboxClassification::Internal,
            );
            $rowIds[] = $event->getKey();
        }

        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
        $originalDefault = config('database.default');

        try {
            // Connection A: claim 2 of the 4 rows and DELIBERATELY do not
            // commit yet — this is the "in-flight claim" SKIP LOCKED must
            // protect against.
            DB::setDefaultConnection('pgsql');
            DB::beginTransaction();

            $staleBefore = CarbonImmutable::now()->subSeconds(OutboxPublisher::STALE_CLAIM_SECONDS);
            $now = CarbonImmutable::now();

            $connectionAClaimed = DB::select(OutboxPublisher::CLAIM_QUERY, [$staleBefore, $now, 2]);
            $connectionAIds = array_map(static fn (object $row): string => (string) $row->id, $connectionAClaimed);

            $this->assertCount(2, $connectionAIds, 'connection A should claim exactly 2 of the 4 unclaimed rows');

            // Connection B: claim while A's transaction is STILL OPEN.
            // SKIP LOCKED must exclude A's 2 locked-but-uncommitted rows
            // and return only the 2 A did not touch.
            DB::setDefaultConnection('pgsql_race');

            $connectionBClaimed = DB::select(OutboxPublisher::CLAIM_QUERY, [$staleBefore, $now, 4]);
            $connectionBIds = array_map(static fn (object $row): string => (string) $row->id, $connectionBClaimed);

            $this->assertCount(2, $connectionBIds, 'connection B should see only the 2 rows A did not lock');
            $this->assertEmpty(
                array_intersect($connectionAIds, $connectionBIds),
                'the two connections must never claim the same row while A\'s transaction is still open',
            );

            $combinedIds = collect(array_merge($connectionAIds, $connectionBIds))->sort()->values()->all();
            $expectedIds = collect($rowIds)->sort()->values()->all();
            $this->assertSame($expectedIds, $combinedIds, 'together, the two connections should account for all 4 rows');

            // Now commit A — releasing its lock.
            DB::setDefaultConnection('pgsql');
            DB::commit();
        } finally {
            // If an assertion above failed while A's transaction was still
            // open, the happy-path commit never ran — leaving connection A's
            // transaction open for whichever RefreshDatabase test class runs
            // next in this same PHPUnit process, which would then fail with
            // a confusing, unrelated error. Guarded so it's a no-op on the
            // happy path, where the commit above already closed it.
            if (DB::connection('pgsql')->transactionLevel() > 0) {
                DB::connection('pgsql')->rollBack();
            }

            DB::setDefaultConnection($originalDefault);
            DB::purge('pgsql_race');
        }

        // Wipe the committed rows so later RefreshDatabase test classes in
        // this same process start from an empty, migrated schema — see
        // this file's own doc block.
        Artisan::call('migrate:fresh');
    }
}

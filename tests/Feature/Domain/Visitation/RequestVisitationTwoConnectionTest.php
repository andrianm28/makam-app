<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Visitation;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Visitation\Actions\RequestVisitation;
use App\Domain\Visitation\Exceptions\VisitationCapacityExceededException;
use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Domain\Visitation\Models\VisitationBooking;
use App\Domain\Visitation\Models\VisitationDateCapacity;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The two-connection capacity test, OUTSIDE the `RefreshDatabase` outer
 * transaction — the `RecordOrderStatusChangeTwoConnectionTest` pattern
 * (its class doc block explains why `RefreshDatabase` cannot be used
 * here: the fixture must be COMMITTED before the second logical session
 * queries it).
 *
 * This is NOT a concurrency test, and the name says what the body
 * proves: SEQUENTIAL re-read semantics across two independent backend
 * sessions, the same honest framing `ReservePlotTwoConnectionTest` uses.
 * The two calls run one after the other, so the first
 * `Audit::wrap()` transaction runs `BEGIN`→`COMMIT` to completion —
 * creating and committing the date's ledger row with `booked_count = 1`
 * — before the second call starts; the row lock is never contended.
 * What is asserted is that the second session's
 * `lockForUpdate()->firstOrCreate()` FIND-and-lock re-read observes the
 * first session's ALREADY-COMMITTED `booked_count`, so its own
 * locked-path capacity check (`1 + 1 > daily_capacity 1`) refuses with
 * `VisitationCapacityExceededException`, leaving exactly one booking and
 * `booked_count = 1`.
 *
 * The unique-index classifier branch in `RequestVisitation` (the
 * lost-first-insert race, where a `lockForUpdate()` on a MISSING row
 * locks nothing on PostgreSQL and the loser's `firstOrCreate` collides)
 * is deliberately NOT exercised here: it needs a true parallel
 * first-ever-insert race — both sessions passing the pre-insert read
 * before either commits — which sequential sessions cannot produce.
 * That branch is backstopped by the unique constraint and its lock-
 * and-re-check, and is out of scope on the 2/4 host, as recorded in the
 * P3 plan.
 *
 * Both sessions book the SAME date with DIFFERENT idempotency keys, so
 * the idempotency fast-path cannot mask the refusal.
 *
 * The trailing `migrate:fresh` is LOAD-BEARING, not a nicety: the two
 * sessions COMMIT real rows, and without the wipe those rows leak into
 * every later `RefreshDatabase` test class in the same process — the
 * `RecordOrderStatusChangeTwoConnectionTest` doc block records that as
 * a verified in-suite failure.
 *
 * The `pgsql`-only guard skips on the ABSENCE of pgsql and is
 * deliberately placed BEFORE the migration, so the SQLite run costs
 * nothing: on that driver the body (and the `migrate:fresh`) never
 * executes.
 */
final class RequestVisitationTwoConnectionTest extends TestCase
{
    public function test_a_second_booking_after_the_first_commits_is_refused_without_oversell(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Sequential cross-connection re-read is only meaningful on PostgreSQL');
        }

        $cemetery = $this->cemetery();
        $policy = $this->policy($cemetery, ['daily_capacity' => 1]);

        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);

        $originalDefault = config('database.default');
        $outcomes = [];

        try {
            foreach (['pgsql', 'pgsql_race'] as $connectionName) {
                DB::setDefaultConnection($connectionName);

                try {
                    app(RequestVisitation::class)(
                        Cemetery::query()->findOrFail($cemetery->getKey()),
                        '2026-08-19',
                        1,
                        '0812-3456-7890',
                        null,
                        null,
                        [],
                        'req-'.Str::random(8),
                        'actor:customer',
                    );
                    $outcomes[] = 'ok';
                } catch (VisitationCapacityExceededException) {
                    $outcomes[] = 'blocked';
                }
            }
        } finally {
            DB::setDefaultConnection($originalDefault);
            DB::purge('pgsql_race');
        }

        $this->assertSame(['ok', 'blocked'], $outcomes);
        $this->assertSame(1, VisitationBooking::query()->count());
        $this->assertSame(1, VisitationDateCapacity::query()->value('booked_count'));

        // Wipe the committed rows so later `RefreshDatabase` test classes
        // in this same process start from an empty, migrated schema (they
        // begin a transaction, they do not re-migrate — see the class doc
        // block).
        Artisan::call('migrate:fresh');
    }

    private function cemetery(): Cemetery
    {
        return Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function policy(Cemetery $cemetery, array $overrides = []): CemeteryVisitationPolicy
    {
        return CemeteryVisitationPolicy::query()->create(array_merge([
            'cemetery_id' => $cemetery->getKey(),
            'operating_hours' => [
                'mon' => ['open' => '08:00', 'close' => '17:00'],
                'tue' => ['open' => '08:00', 'close' => '17:00'],
                'wed' => ['open' => '08:00', 'close' => '17:00'],
                'thu' => ['open' => '08:00', 'close' => '17:00'],
                'fri' => ['open' => '08:00', 'close' => '17:00'],
                'sat' => ['open' => '08:00', 'close' => '17:00'],
                'sun' => ['open' => '08:00', 'close' => '17:00'],
            ],
            'daily_capacity' => 10,
        ], $overrides));
    }
}

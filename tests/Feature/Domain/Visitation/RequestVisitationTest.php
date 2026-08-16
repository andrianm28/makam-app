<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Visitation;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Visitation\Actions\RequestVisitation;
use App\Domain\Visitation\Exceptions\VisitationBlackoutDateException;
use App\Domain\Visitation\Exceptions\VisitationCapacityExceededException;
use App\Domain\Visitation\Exceptions\VisitationClosedDayException;
use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Domain\Visitation\Models\VisitationBlackoutDate;
use App\Domain\Visitation\Models\VisitationBooking;
use App\Domain\Visitation\Models\VisitationDateCapacity;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Task 1 (Lane 1 — Visitation): `RequestVisitation` — the happy path
 * (booking + ledger increment + reference + audit + outbox), the three
 * refusals (capacity / blackout with reason / closed weekday), the
 * sequential idempotent duplicate, and the no-policy refusal.
 *
 * Dates are fixed calendar dates with known weekdays (2026-08-16 is a
 * Sunday): 2026-08-17 Mon, 2026-08-19 Wed.
 */
final class RequestVisitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_requests_a_visit_and_audits_the_request(): void
    {
        $cemetery = $this->cemetery();
        $policy = $this->policy($cemetery, ['daily_capacity' => 10]);

        $booking = app(RequestVisitation::class)(
            $cemetery,
            '2026-08-19',
            2,
            '0812-3456-7890',
            'family@example.com',
            'Membutuhkan kursi roda',
            ['kursi roda', 'ramah lansia'],
            'req-'.Str::random(8),
            'actor:customer',
        );

        $this->assertSame('requested', $booking->status);
        $this->assertSame(2, $booking->visitor_count);
        $this->assertMatchesRegularExpression('/^VST-\d{4}-[A-Z0-9]{8}$/', $booking->reference);
        $this->assertTrue($booking->visit_date instanceof CarbonImmutable);
        $this->assertSame('2026-08-19', $booking->visit_date->toDateString());
        $this->assertSame(['kursi roda', 'ramah lansia'], $booking->facility_requests);

        // `whereDate` (not raw equality): SQLite stores `date`-cast
        // columns as `'Y-m-d H:i:s'`, so the engine-agnostic date
        // predicate is the one the codebase uses for date columns.
        $this->assertSame(2, VisitationDateCapacity::query()
            ->where('policy_id', $policy->getKey())
            ->whereDate('date', '2026-08-19')
            ->value('booked_count'));

        $this->assertDatabaseHas('audit_events', [
            'action' => 'VISITATION_REQUESTED',
            'actor_ref' => 'actor:customer',
            'actor_role' => 'customer',
            'source' => 'api',
            'outcome' => 'allowed',
            'subject_type' => 'visitation_booking',
            'subject_id' => (string) $booking->getKey(),
        ]);

        $this->assertDatabaseHas('outbox_events', [
            'event_name' => 'visit.booking_requested.v1',
            'aggregate_type' => 'visitation_booking',
            'aggregate_id' => (string) $booking->getKey(),
            'classification' => 'INTERNAL',
            'idempotency_key' => "visitation_booking:{$booking->getKey()}",
        ]);
    }

    public function test_refuses_when_capacity_is_exhausted_and_rolls_back_everything(): void
    {
        $cemetery = $this->cemetery();
        $policy = $this->policy($cemetery, ['daily_capacity' => 1]);

        try {
            app(RequestVisitation::class)(
                $cemetery,
                '2026-08-19',
                2,
                '0812-3456-7890',
                null,
                null,
                [],
                'req-'.Str::random(8),
                'actor:customer',
            );
            $this->fail('An over-capacity request must be refused.');
        } catch (VisitationCapacityExceededException $exception) {
            $this->assertStringContainsString('2026-08-19', $exception->getMessage());
            $this->assertStringContainsString('daily capacity is 1', $exception->getMessage());
        }

        // The whole transaction rolls back: no booking, no ledger row, no
        // audit, no outbox row.
        $this->assertSame(0, VisitationBooking::query()->count());
        $this->assertSame(0, VisitationDateCapacity::query()->count());
        $this->assertSame(0, DB::table('audit_events')->count());
        $this->assertSame(0, DB::table('outbox_events')->count());
    }

    public function test_refuses_a_blackout_date_with_the_surfaced_reason(): void
    {
        $cemetery = $this->cemetery();
        $policy = $this->policy($cemetery);

        VisitationBlackoutDate::query()->create([
            'policy_id' => $policy->getKey(),
            'date' => '2026-08-19',
            'reason' => 'Upacara peringatan hari jadi',
        ]);

        try {
            app(RequestVisitation::class)(
                $cemetery,
                '2026-08-19',
                1,
                '0812-3456-7890',
                null,
                null,
                [],
                'req-'.Str::random(8),
                'actor:customer',
            );
            $this->fail('A blackout date must be refused.');
        } catch (VisitationBlackoutDateException $exception) {
            $this->assertStringContainsString('2026-08-19', $exception->getMessage());
            $this->assertStringContainsString('Upacara peringatan hari jadi', $exception->getMessage());
        }

        $this->assertSame(0, VisitationBooking::query()->count());
        $this->assertSame(0, VisitationDateCapacity::query()->count());
    }

    public function test_refuses_a_date_on_a_closed_weekday(): void
    {
        $cemetery = $this->cemetery();
        $this->policy($cemetery, ['operating_hours' => ['mon' => null]]);

        try {
            app(RequestVisitation::class)(
                $cemetery,
                '2026-08-17',
                1,
                '0812-3456-7890',
                null,
                null,
                [],
                'req-'.Str::random(8),
                'actor:customer',
            );
            $this->fail('A Monday must be refused when the policy closes Mondays.');
        } catch (VisitationClosedDayException $exception) {
            $this->assertStringContainsString('2026-08-17', $exception->getMessage());
        }

        $this->assertSame(0, VisitationBooking::query()->count());
        $this->assertSame(0, VisitationDateCapacity::query()->count());
    }

    public function test_a_repeated_idempotency_key_returns_the_incumbent_without_a_second_row(): void
    {
        $cemetery = $this->cemetery();
        $this->policy($cemetery);
        $idempotencyKey = 'req-'.Str::random(8);

        $first = app(RequestVisitation::class)(
            $cemetery,
            '2026-08-19',
            2,
            '0812-3456-7890',
            null,
            null,
            [],
            $idempotencyKey,
            'actor:customer',
        );

        $second = app(RequestVisitation::class)(
            $cemetery,
            '2026-08-19',
            5,
            '0812-0000-0000',
            null,
            null,
            [],
            $idempotencyKey,
            'actor:customer',
        );

        $this->assertSame($first->getKey(), $second->getKey());
        $this->assertSame(1, VisitationBooking::query()->count());
        // The incumbent's own ledger row is untouched by the duplicate.
        $this->assertSame(2, VisitationDateCapacity::query()->value('booked_count'));
        $this->assertSame(1, DB::table('outbox_events')->count());
    }

    public function test_two_bookings_for_the_same_date_share_one_ledger_row(): void
    {
        // The SQLite find-path pin (the engine CI runs): the second
        // booking's `firstOrCreate` must FIND the first booking's ledger
        // row — the Carbon-valued `date` binding matches the stored
        // `'Y-m-d H:i:s'` value (see `capacityRow()`'s doc block) — so
        // the pair produce ONE ledger row with the summed `booked_count`,
        // never a second-row insert that would collide on the unique
        // `(policy_id, date)` constraint.
        $cemetery = $this->cemetery();
        $policy = $this->policy($cemetery, ['daily_capacity' => 10]);

        $first = app(RequestVisitation::class)(
            $cemetery,
            '2026-08-19',
            2,
            '0812-1111-1111',
            null,
            null,
            [],
            'req-'.Str::random(8),
            'actor:customer',
        );

        $second = app(RequestVisitation::class)(
            $cemetery,
            '2026-08-19',
            3,
            '0812-2222-2222',
            null,
            null,
            [],
            'req-'.Str::random(8),
            'actor:customer',
        );

        $this->assertSame(2, $first->visitor_count);
        $this->assertSame(3, $second->visitor_count);
        $this->assertNotSame($first->getKey(), $second->getKey());
        $this->assertSame(2, VisitationBooking::query()->count());
        $this->assertSame(1, VisitationDateCapacity::query()->count());
        $this->assertSame(5, VisitationDateCapacity::query()->value('booked_count'));
    }

    public function test_refuses_a_cemetery_without_a_policy(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no visitation policy configured');

        app(RequestVisitation::class)(
            $this->cemetery(),
            '2026-08-19',
            1,
            '0812-3456-7890',
            null,
            null,
            [],
            'req-'.Str::random(8),
            'actor:customer',
        );
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

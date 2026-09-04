<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Visitation\Actions\ChangeVisitationBookingStatus;
use App\Domain\Visitation\Actions\RequestVisitation;
use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Domain\Visitation\Models\VisitationBooking;
use App\Support\ExampleData\Concerns\TaggedAsDemoData;

/**
 * `RequestVisitation` throws immediately unless a `CemeteryVisitationPolicy`
 * row already exists for the cemetery, and no dedicated Action creates one
 * anywhere in this codebase (confirmed by reading the model directly — its
 * `$fillable` is exactly `['cemetery_id', 'operating_hours',
 * 'daily_capacity']`, no companion Action file exists). Direct model
 * creation here is the same kind of confirmed exception as
 * `Vendor`/`VendorUser`/`User` in Task 4, not a plan defect.
 *
 * `$cemetery` is expected to be a dedicated demo cemetery this run itself
 * created (`DemoDataSeedCommand::createDemoCemetery()`) — never an
 * arbitrary real one. `firstOrCreate()` below is still guarded with
 * `wasRecentlyCreated` before tagging (rather than trusting the caller's
 * cemetery choice alone) as a second, independent layer: even if a future
 * caller ever passes a real cemetery again, this class must never adopt,
 * tag, or put a real operator's pre-existing `CemeteryVisitationPolicy` at
 * risk of `demo-data:purge` deleting it.
 */
final class VisitationExampleData
{
    private const string ACTOR_REF = 'demo-data-seeder';

    private const string ACTOR_ROLE = 'customer';

    /**
     * @return list<VisitationBooking>
     */
    public static function seed(string $batchId, Cemetery $cemetery): array
    {
        $policy = CemeteryVisitationPolicy::query()->firstOrCreate(
            ['cemetery_id' => $cemetery->id],
            [
                'operating_hours' => [
                    'mon' => ['open' => '08:00', 'close' => '17:00'],
                    'tue' => ['open' => '08:00', 'close' => '17:00'],
                    'wed' => ['open' => '08:00', 'close' => '17:00'],
                    'thu' => ['open' => '08:00', 'close' => '17:00'],
                    'fri' => ['open' => '08:00', 'close' => '17:00'],
                    'sat' => ['open' => '08:00', 'close' => '15:00'],
                    'sun' => ['open' => '08:00', 'close' => '15:00'],
                ],
                'daily_capacity' => 50,
            ],
        );

        // Never tag (and so never let purge delete) a policy that already
        // existed for this cemetery — see this class's own doc block.
        if ($policy->wasRecentlyCreated) {
            TaggedAsDemoData::tag($policy, $batchId);
        }

        $bookings = [];

        foreach (range(0, 2) as $index) {
            $booking = (new RequestVisitation)(
                $cemetery,
                visitDate: now()->addDays($index + 3)->toDateString(),
                visitorCount: 2,
                contactPhone: DemoContactData::phone($index + 200),
                contactEmail: DemoContactData::email($index + 200),
                accessibilityNeeds: null,
                facilityRequests: [],
                idempotencyKey: "demo-visitation-{$batchId}-{$index}",
                actorReference: self::ACTOR_REF,
                actorRole: self::ACTOR_ROLE,
            );
            TaggedAsDemoData::tag($booking, $batchId);
            $bookings[] = $booking;
        }

        (new ChangeVisitationBookingStatus)($bookings[1], 'confirmed', self::ACTOR_REF, 'admin');
        $bookings[1] = $bookings[1]->fresh();

        (new ChangeVisitationBookingStatus)($bookings[2], 'confirmed', self::ACTOR_REF, 'admin');
        (new ChangeVisitationBookingStatus)($bookings[2]->fresh(), 'cancelled', self::ACTOR_REF, self::ACTOR_ROLE, 'Rencana kunjungan demo dibatalkan.');
        $bookings[2] = $bookings[2]->fresh();

        return $bookings;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\FuneralCase\Actions;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\FuneralCase\FuneralCaseStatus;
use App\Domain\FuneralCase\FuneralCaseUrgency;
use App\Domain\FuneralCase\Models\FuneralCase;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;

/**
 * AC5, At-Need arm: opens the operational case an
 * `AT_NEED_SERVICE_ORDER` routes to. The ONLY writer of `funeral_cases`
 * rows in this codebase.
 *
 * Called from inside `App\Domain\OrderWorkflow\Actions\SubmitBookingDraft`'s
 * transaction, and deliberately opens none of its own: the case and the
 * order it belongs to must commit or roll back together, or a crash mid-way
 * leaves a case nobody ordered.
 *
 * The case is created BEFORE the order, because `orders.funeral_case_id` is
 * set at `create()` time — `Order`'s write guard makes every later update
 * throw, so there is no "create the order then link it" option and the
 * dependency direction is fixed. See `SubmitBookingDraft`'s doc block.
 *
 * ---------------------------------------------------------------------------
 * What is deliberately left null
 * ---------------------------------------------------------------------------
 * Case manager and both deadline columns. The reasoning is in
 * `2026_08_12_100016_create_funeral_cases_table.php`'s doc block and is not
 * restated here: assignment is its own catalogued event and its own
 * workflow, and there is no resolved source for a first-response or service
 * deadline anywhere in this repository (open decision #6, Urgent SLA).
 */
final readonly class OpenFuneralCase
{
    public function __invoke(BookingDraft $draft): FuneralCase
    {
        $case = FuneralCase::query()->create([
            'status' => FuneralCaseStatus::NEW->value,
            'urgency' => FuneralCaseUrgency::fromBookingServiceType((string) $draft->service_type)->value,
            'service_area' => $draft->city_code,
            'case_manager_ref' => null,
            'first_response_due_at' => null,
            'service_due_at' => null,
            'booking_draft_id' => $draft->getKey(),
        ]);

        // `docs/contracts/event-catalog.md`: `funeral_case.created.v1`,
        // producer FuneralCase, consumers Operations/notification, note
        // "At-Need accepted". A CATALOGUED name — not invented here (Global
        // Constraint / finding N-12).
        //
        // References only. `urgency` and `service_area` are operational
        // routing labels from closed lists, never customer content; no name,
        // contact detail, or document reference goes anywhere near this
        // payload.
        Outbox::record(
            eventName: 'funeral_case.created.v1',
            eventVersion: 1,
            aggregateType: 'funeral_case',
            aggregateId: $case->getKey(),
            data: [
                'funeral_case_id' => $case->getKey(),
                'urgency' => $case->urgency,
                'service_area' => $case->service_area,
            ],
            classification: OutboxClassification::Internal,
            idempotencyKey: "funeral_case_created:{$case->getKey()}",
        );

        return $case;
    }
}

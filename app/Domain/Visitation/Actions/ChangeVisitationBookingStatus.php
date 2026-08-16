<?php

declare(strict_types=1);

namespace App\Domain\Visitation\Actions;

use App\Domain\Visitation\Models\VisitationBooking;
use App\Domain\Visitation\VisitationAuditActions;
use App\Domain\Visitation\VisitationBookingStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use InvalidArgumentException;

/**
 * The ONLY writer of `visitation_bookings.status` — Task 2 of
 * `docs/superpowers/plans/2026-08-16-p4-memorial-qr-visitation.md`
 * (Lane 2 — Visitation), the operator-side successor to `RequestVisitation`
 * (which creates every booking in `requested` and never transitions it).
 *
 * ---------------------------------------------------------------------------
 * The transition matrix, and why it is enforced HERE
 * ---------------------------------------------------------------------------
 *   requested → confirmed   (outbox `visit.booking_confirmed.v1`)
 *   requested → cancelled
 *   confirmed → cancelled
 *   requested → no_show
 * Anything else is refused with an honest `InvalidArgumentException`
 * naming the current status and the allowed from-states — "late
 * transitions refused honestly", the same discipline `ReservePlot`/the
 * plot state-override table actions apply. The refusal is authoritative
 * at the ACTION layer, not just the UI: `allowedFrom()` is the single
 * source of truth the bookings resource's row-action `visible()`
 * closures AND its run-time re-read share (finding I2), but a wire call
 * that bypasses both (or a future console/API caller) still hits this
 * matrix against the CURRENT database status (`$booking->refresh()` —
 * never the caller's stale instance), so render-time meaning and
 * wire-call enforcement cannot drift and no caller can transition a
 * booking whose real status moved under them.
 *
 * The transition and its `VISITATION_STATUS_CHANGED` audit row commit in
 * one transaction (`Audit::wrap`), with `previous_state`/`new_state` on
 * the audit metadata — the module's own answer to "a transition without
 * its trail is indistinguishable from one nobody authorized".
 * Confirmation additionally writes `visit.booking_confirmed.v1` (the
 * PRE-CATALOGUED event — `docs/contracts/event-catalog.md`) into the
 * transactional outbox inside the same transaction, so a confirmed
 * booking can never exist without its event. Cancellation and no-show
 * emit NO event: the kiro design's "Deliberately not covered" section
 * records that `event-catalog.md` catalogues only `visit.booking_
 * confirmed.v1` and explicitly does not invent cancelled/no-show events.
 *
 * ---------------------------------------------------------------------------
 * Deliberately NOT done: the capacity ledger
 * ---------------------------------------------------------------------------
 * A cancelled visit does NOT decrement `visitation_date_capacities.
 * booked_count`. That would be a real behavioural choice (release the
 * slot, or keep it reserved for the day's plan), and the kiro design
 * leaves post-request semantics intentionally thin — it even has no
 * cancellation event to announce a release with. Decrementing silently
 * would make capacity_left lie *up* without any documented intent;
 * keeping the count as-requested is the honest bound of this task's
 * scope. Flagged here so the next lane that touches cancellation decides
 * deliberately, not by accident.
 */
final readonly class ChangeVisitationBookingStatus
{
    /**
     * The from-status sets for each target — the single matrix `__invoke`
     * enforces and `allowedFrom()` exposes to the bookings resource.
     *
     * @var array<string, list<string>>
     */
    private const array TRANSITIONS = [
        VisitationBookingStatus::CONFIRMED => [VisitationBookingStatus::REQUESTED],
        VisitationBookingStatus::CANCELLED => [VisitationBookingStatus::REQUESTED, VisitationBookingStatus::CONFIRMED],
        VisitationBookingStatus::NO_SHOW => [VisitationBookingStatus::REQUESTED],
    ];

    /**
     * The allowed from-statuses for a target status — public so the admin
     * resource's row actions and their run-time re-read share ONE
     * definition with the enforcement matrix above and cannot drift.
     *
     * @return list<string>
     */
    public static function allowedFrom(string $to): array
    {
        return self::TRANSITIONS[$to] ?? [];
    }

    /**
     * @param  string  $to  One of `VisitationBookingStatus::KNOWN_STATUSES`
     *                      — the brief's `VisitationBookingStatus $to`
     *                      shape: that class is a plain constants class
     *                      with no instances (the `PlotReservationState`
     *                      convention), so the target is the constant
     *                      VALUE, validated here by
     *                      `VisitationBookingStatus::assertKnown()`.
     * @param  string|null  $reason  Not mandatory: `VISITATION_STATUS_CHANGED`
     *                               is deliberately NOT on
     *                               `SensitiveActions::ACTIONS` (routine
     *                               queue work, see `VisitationAuditActions`).
     *
     * @throws InvalidArgumentException when `$to` is not a known status or
     *                                  the booking's CURRENT status does
     *                                  not permit the transition.
     */
    public function __invoke(
        VisitationBooking $booking,
        string $to,
        int|string $actorReference,
        string $actorRole,
        ?string $reason = null,
        AuditSource $auditSource = AuditSource::Panel,
    ): VisitationBooking {
        VisitationBookingStatus::assertKnown($to);

        // The enforcement reads the CURRENT database status — a wire call
        // against a stale instance must not transition a booking whose
        // real status moved under the caller (finding I2).
        $booking->refresh();
        $from = (string) $booking->status;

        if (! in_array($from, self::allowedFrom($to), true)) {
            throw new InvalidArgumentException(
                "Visitation booking [{$booking->reference}] cannot transition from [{$from}] to [{$to}]; "
                .'allowed from-states: '.(self::allowedFrom($to) === []
                    ? 'none (unknown target status)'
                    : implode(', ', self::allowedFrom($to))).'.'
            );
        }

        return Audit::wrap(
            mutation: function () use ($booking, $to): VisitationBooking {
                $booking->update(['status' => $to]);

                if ($to === VisitationBookingStatus::CONFIRMED) {
                    Outbox::record(
                        eventName: 'visit.booking_confirmed.v1',
                        eventVersion: 1,
                        aggregateType: 'visitation_booking',
                        aggregateId: (string) $booking->getKey(),
                        data: [
                            'booking_id' => (string) $booking->getKey(),
                            'cemetery_id' => (string) $booking->cemetery_id,
                            'visit_date' => $booking->visit_date->toDateString(),
                            'visitor_count' => $booking->visitor_count,
                        ],
                        classification: OutboxClassification::Internal,
                        idempotencyKey: "visitation_booking:{$booking->getKey()}:confirmed",
                    );
                }

                return $booking;
            },
            action: VisitationAuditActions::VISITATION_STATUS_CHANGED,
            subject: new AuditSubject('visitation_booking', (string) $booking->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource,
            reason: $reason,
            correlationId: app(CorrelationContext::class)->current()?->value,
            metadata: [
                'previous_state' => $from,
                'new_state' => $to,
            ],
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Visitation\Actions;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Visitation\Exceptions\VisitationBlackoutDateException;
use App\Domain\Visitation\Exceptions\VisitationCapacityExceededException;
use App\Domain\Visitation\Exceptions\VisitationClosedDayException;
use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Domain\Visitation\Models\VisitationBooking;
use App\Domain\Visitation\Models\VisitationDateCapacity;
use App\Domain\Visitation\VisitationAuditActions;
use App\Domain\Visitation\VisitationBookingStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Task 1 of `docs/superpowers/plans/2026-08-16-p4-memorial-qr-
 * visitation.md` (Lane 1 — Visitation) — the ONLY writer of
 * `visitation_bookings`, and the module's no-oversell + idempotency
 * mechanism.
 *
 * ---------------------------------------------------------------------------
 * Sequencing inside the transaction, and why
 * ---------------------------------------------------------------------------
 * 1. Idempotency pre-check OUTSIDE the transaction (the
 *    `SubmitBookingDraft`/`ReservePlot` fast-path shape): a duplicate
 *    submission — the common double-tap — costs one SELECT and returns
 *    the incumbent booking, so the confirmation card re-renders the
 *    same reference instead of a second row. This is a courtesy fast
 *    path, NOT the correctness mechanism: the `idempotency_key` unique
 *    constraint is the database backstop, and the outer `QueryException`
 *    classifier below translates a concurrent collision into an
 *    incumbent return instead of a raw 500 (the `OrderAlreadyPaid`
 *    pattern — including its "deliberately not chained as `$previous`"
 *    rationale: the original message echoes interpolated INSERT
 *    bindings, and this table's bindings include contact phone/email).
 * 2. `Audit::wrap` opens the transaction; inside it:
 *    a. Lock-or-create the date-capacity ledger row
 *       (`lockForUpdate()->firstOrCreate`). This row is the
 *       serialization anchor: every booking for a date reads
 *       `booked_count` under this lock, so two concurrent bookings
 *       serialize and cannot oversell. The row is created lazily on the
 *       date's first booking, so on PostgreSQL a `lockForUpdate()` on a
 *       MISSING row locks nothing (PG has no gap locks) — the unique
 *       `(policy_id, date)` constraint backstops the concurrent
 *       first-ever-insert: the loser's `firstOrCreate` throws, the
 *       inner narrow classifier re-reads the now-committed ledger row,
 *       and the capacity check re-runs against it (booking proceeds
 *       when room remains; `VisitationCapacityExceededException` is
 *       thrown honestly when the winner consumed it — the
 *       `RequestVisitationTwoConnectionTest` proves exactly this
 *       second outcome). On SQLite `lockForUpdate()` is a no-op; the
 *       sequential path is exercised there, the race only on PG (same
 *       reasoning `ReservePlot` documents).
 *    b. Blackout check — `VisitationBlackoutDateException` with the
 *       visitor-visible reason (checked FIRST: a blackout date means
 *       "this date is closed", and that must win over weekday logic).
 *    c. Closed-day check — `VisitationClosedDayException` when the
 *       date's weekday has no operating hours.
 *    d. Capacity check — `booked_count + visitor_count <=
 *       daily_capacity`, read against the LOCKED row, else
 *       `VisitationCapacityExceededException`.
 *    e. Insert the `requested` booking (the model guard generates the
 *       `VST-` reference), increment `booked_count` on the locked row,
 *       and emit `visit.booking_requested.v1` via the transactional
 *       `Outbox` — all three (booking, ledger, outbox) commit together
 *       with the `VISITATION_REQUESTED` audit row (AC4), so a booking
 *       can never exist without its ledger increment, its audit row, or
 *       its event.
 *
 * The validation order is deliberate: the ledger row is locked FIRST so
 * the checks inside the transaction all read one consistent snapshot of
 * the date's state; the domain exceptions are all thrown AFTER the lock
 * is taken, and the transaction rolls back (the lazily created ledger
 * row rolls back with it — a refused request leaves no ledger residue).
 *
 * ---------------------------------------------------------------------------
 * The two classifiers, and why they are separate
 * ---------------------------------------------------------------------------
 * The inner classifier matches only the capacity-ledger constraint
 * (`visitation_date_capacities_policy_id_date_unique` on PG; the
 * qualified `visitation_date_capacities.policy_id` form on SQLite) and
 * re-runs the capacity check. The outer classifier matches only the
 * booking idempotency constraint
 * (`visitation_bookings_idempotency_key_unique` / `visitation_bookings.
 * idempotency_key`) and returns the incumbent. Both match the index
 * name first (PG) and only then the qualified `unique` form (SQLite) —
 * the same deliberate narrowness `RecordOrderStatusChange::
 * isDuplicatePaidEvent()` documents: a bare column-name match would
 * misclassify NOT NULL/length violations as duplicates.
 */
final readonly class RequestVisitation
{
    public function __invoke(
        Cemetery $cemetery,
        string $visitDate,
        int $visitorCount,
        string $contactPhone,
        ?string $contactEmail,
        ?string $accessibilityNeeds,
        array $facilityRequests,
        string $idempotencyKey,
        int|string $actorReference,
        string $actorRole = 'customer',
        AuditSource $auditSource = AuditSource::Api,
    ): VisitationBooking {
        $policy = CemeteryVisitationPolicy::query()->where('cemetery_id', $cemetery->getKey())->first();

        if (! $policy instanceof CemeteryVisitationPolicy) {
            throw new InvalidArgumentException('Cemetery has no visitation policy configured.');
        }

        // Step 1 — outside the transaction: the common sequential
        // duplicate returns the incumbent for one SELECT (see the class
        // doc block). The authoritative re-check is the unique
        // constraint + outer classifier.
        $existing = VisitationBooking::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing instanceof VisitationBooking) {
            return $existing;
        }

        try {
            return Audit::wrap(
                mutation: function () use ($cemetery, $policy, $visitDate, $visitorCount, $contactPhone, $contactEmail, $accessibilityNeeds, $facilityRequests, $idempotencyKey): VisitationBooking {
                    $date = CarbonImmutable::parse($visitDate);

                    try {
                        $capacity = $this->capacityRow($policy, $date);
                    } catch (QueryException $exception) {
                        if (! $this->isDuplicateDateCapacityRow($exception)) {
                            throw $exception;
                        }

                        // The lost `firstOrCreate` race: `lockForUpdate()`
                        // on a missing row locks nothing on PostgreSQL
                        // (no gap locks), so two concurrent first-ever
                        // bookings for a date collide on the unique
                        // (policy_id, date). Re-read the now-committed
                        // ledger row and re-run the capacity check below
                        // against it.
                        $capacity = VisitationDateCapacity::query()
                            ->where('policy_id', $policy->getKey())
                            ->where('date', $date)
                            ->first();

                        if (! $capacity instanceof VisitationDateCapacity) {
                            throw $exception;
                        }
                    }

                    if ($policy->isBlackout($date)) {
                        throw VisitationBlackoutDateException::forDate($date->toDateString(), $policy->blackoutReasonFor($date));
                    }

                    if (! $policy->isVisitingDay($date)) {
                        throw VisitationClosedDayException::forDate($date->toDateString());
                    }

                    if ($capacity->booked_count + $visitorCount > $policy->daily_capacity) {
                        throw VisitationCapacityExceededException::forDate($date->toDateString(), $policy->daily_capacity);
                    }

                    return $this->book($cemetery, $policy, $capacity, $date, $visitorCount, $contactPhone, $contactEmail, $accessibilityNeeds, $facilityRequests, $idempotencyKey);
                },
                action: VisitationAuditActions::VISITATION_REQUESTED,
                subject: fn (VisitationBooking $booking): AuditSubject => new AuditSubject('visitation_booking', (string) $booking->getKey()),
                outcome: AuditOutcome::Allowed,
                actorRef: $actorReference,
                actorRole: $actorRole,
                source: $auditSource,
                correlationId: app(CorrelationContext::class)->current()?->value,
            );
        } catch (QueryException $exception) {
            if (! $this->isDuplicateIdempotencyKey($exception)) {
                throw $exception;
            }

            // Deliberately not chained as `$previous` — the original
            // message echoes the interpolated INSERT bindings, which
            // include contact phone/email (`OrderAlreadyPaidException`'s
            // doc block; `AGENTS.md` §Observability).
            $incumbent = VisitationBooking::query()->where('idempotency_key', $idempotencyKey)->first();

            if (! $incumbent instanceof VisitationBooking) {
                throw $exception;
            }

            return $incumbent;
        }
    }

    /**
     * Lock-or-create the date's ledger row — the serialization anchor of
     * the no-oversell guarantee (see the class doc block).
     *
     * The `date` attribute is the CarbonImmutable itself, not
     * `$date->toDateString()`: on SQLite Eloquent stores `date`-cast
     * columns as `'Y-m-d H:i:s'` and formats DateTime bindings the same
     * way, so a bare `'Y-m-d'` string would never match the stored value
     * and `firstOrCreate` would try to insert a second row for the same
     * date on every subsequent booking; PostgreSQL coerces the same
     * binding to its `date` column type on both the `WHERE` and the
     * `INSERT`.
     */
    private function capacityRow(CemeteryVisitationPolicy $policy, CarbonImmutable $date): VisitationDateCapacity
    {
        return VisitationDateCapacity::query()
            ->lockForUpdate()
            ->firstOrCreate(
                ['policy_id' => $policy->getKey(), 'date' => $date],
                ['booked_count' => 0],
            );
    }

    /**
     * The booking insert, the ledger increment, and the outbox row —
     * the write half of the transaction, shared by the happy path and
     * the classifier re-run path.
     */
    private function book(
        Cemetery $cemetery,
        CemeteryVisitationPolicy $policy,
        VisitationDateCapacity $capacity,
        CarbonImmutable $date,
        int $visitorCount,
        string $contactPhone,
        ?string $contactEmail,
        ?string $accessibilityNeeds,
        array $facilityRequests,
        string $idempotencyKey,
    ): VisitationBooking {
        $booking = VisitationBooking::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'policy_id' => $policy->getKey(),
            'visit_date' => $date->toDateString(),
            'visitor_count' => $visitorCount,
            'contact_phone' => $contactPhone,
            'contact_email' => $contactEmail,
            'accessibility_needs' => $accessibilityNeeds,
            'facility_requests' => $facilityRequests,
            'status' => VisitationBookingStatus::REQUESTED,
            'idempotency_key' => $idempotencyKey,
            'reference' => $this->nextReference(),
        ]);

        $capacity->increment('booked_count', $visitorCount);

        Outbox::record(
            eventName: 'visit.booking_requested.v1',
            eventVersion: 1,
            aggregateType: 'visitation_booking',
            aggregateId: (string) $booking->getKey(),
            data: ['booking_id' => (string) $booking->getKey(), 'cemetery_id' => (string) $cemetery->getKey(), 'visit_date' => $date->toDateString(), 'visitor_count' => $visitorCount],
            classification: OutboxClassification::Internal,
            idempotencyKey: "visitation_booking:{$booking->getKey()}",
        );

        return $booking;
    }

    /**
     * The exact `'VST-<year>-<8 uppercase alphanumerics>'` shape the
     * brief fixes. Delegated here rather than left to the model guard
     * so the Action is explicit that the reference is generated by the
     * writer, not supplied by the caller.
     */
    private function nextReference(): string
    {
        return 'VST-'.CarbonImmutable::now()->format('Y').'-'.Str::upper(Str::random(8));
    }

    private function isDuplicateDateCapacityRow(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'visitation_date_capacities_policy_id_date_unique')) {
            return true;
        }

        return str_contains($message, 'unique')
            && str_contains($message, 'visitation_date_capacities.policy_id');
    }

    private function isDuplicateIdempotencyKey(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'visitation_bookings_idempotency_key_unique')) {
            return true;
        }

        return str_contains($message, 'unique')
            && str_contains($message, 'visitation_bookings.idempotency_key');
    }
}

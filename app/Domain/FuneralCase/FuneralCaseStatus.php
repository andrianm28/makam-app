<?php

declare(strict_types=1);

namespace App\Domain\FuneralCase;

use App\Domain\FuneralCase\Exceptions\IllegalFuneralCaseTransitionException;

/**
 * The OPERATIONAL case state machine — `docs/domain/funeral-case-model.md`
 * §Case statuses:
 *
 *   NEW -> TRIAGED -> COORDINATING -> READY_FOR_SERVICE -> IN_SERVICE -> COMPLETED
 *           -> DECLINED
 *           -> CANCELLED
 *           -> TRANSFERRED
 *
 * ---------------------------------------------------------------------------
 * Never merged with `App\Domain\OrderWorkflow\OrderStatus`
 * ---------------------------------------------------------------------------
 * That separation is a direct instruction of the source documents, not a
 * style preference: `funeral-case-model.md` §Case statuses — "These are
 * operational statuses and do not replace commercial order/payment
 * statuses"; `domain-model.md:165` says the same. So this is a distinct
 * enum on a distinct column, with NO shared base type and NO value in
 * common with `OrderStatus` (asserted in
 * `tests/Feature/OrderWorkflow/SubmitBookingDraftTest.php::
 * test_case_status_and_order_status_are_independently_readable`). A shared
 * base enum or a merged column would make "the order is DIBAYAR" and "the
 * case is IN_SERVICE" two readings of one value, and the two genuinely
 * move independently — a paid order whose service has not started is the
 * normal case, not an anomaly.
 *
 * The transition graph lives ON this enum rather than in a sibling
 * `FuneralCaseTransition` class, unlike `OrderStatus`/`OrderTransition`.
 * `OrderTransition` is separate because several Actions across two modules
 * consult it independently of any status value; this graph has exactly one
 * consumer (`Models\FuneralCase::advanceTo()`), and putting it here keeps
 * the two state machines visibly unrelated instead of giving them
 * parallel, easily-confused shapes.
 */
enum FuneralCaseStatus: string
{
    case NEW = 'NEW';
    case TRIAGED = 'TRIAGED';
    case COORDINATING = 'COORDINATING';
    case READY_FOR_SERVICE = 'READY_FOR_SERVICE';
    case IN_SERVICE = 'IN_SERVICE';
    case COMPLETED = 'COMPLETED';
    case DECLINED = 'DECLINED';
    case CANCELLED = 'CANCELLED';
    case TRANSFERRED = 'TRANSFERRED';

    /**
     * `funeral-case-model.md` draws the three branches off the linear chain
     * without naming their source states individually, so they are allowed
     * from every non-terminal state — a case can be declined, cancelled, or
     * transferred at any point before it completes. Deliberately NOT
     * reachable after `COMPLETED`: a finished service is not cancellable,
     * only correctable by a new record.
     *
     * @return list<self>
     */
    public function allowedNext(): array
    {
        $branches = [self::DECLINED, self::CANCELLED, self::TRANSFERRED];

        return match ($this) {
            self::NEW => [self::TRIAGED, ...$branches],
            self::TRIAGED => [self::COORDINATING, ...$branches],
            self::COORDINATING => [self::READY_FOR_SERVICE, ...$branches],
            self::READY_FOR_SERVICE => [self::IN_SERVICE, ...$branches],
            self::IN_SERVICE => [self::COMPLETED, ...$branches],
            self::COMPLETED,
            self::DECLINED,
            self::CANCELLED,
            self::TRANSFERRED => [],
        };
    }

    public function allows(self $to): bool
    {
        return in_array($to, $this->allowedNext(), true);
    }

    public function assertAllows(self $to): void
    {
        if (! $this->allows($to)) {
            throw IllegalFuneralCaseTransitionException::between($this, $to);
        }
    }
}

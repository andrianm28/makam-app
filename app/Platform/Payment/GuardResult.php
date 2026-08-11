<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use InvalidArgumentException;

/**
 * The result of one `GuardPaymentSession` evaluation.
 *
 * ---------------------------------------------------------------------------
 * There is no PASS variant, and that is the point
 * ---------------------------------------------------------------------------
 * Wave 1b ruling 1b-L3-01: "There must be **no reachable PASS outcome**, and
 * therefore no `payment_sessions` row creatable by any caller." That is
 * enforced HERE, in the type, not only in the Action: the constructor is
 * private and `denied()` is the only factory, so no caller anywhere — a
 * future Action, a test, a controller — can construct an allowed result even
 * deliberately. `isAllowed()` exists and always returns `false` so consuming
 * code can be written against the final shape today and does not have to be
 * rewritten when a pass path eventually lands.
 *
 * The task that lands the missing upstream records (owned by
 * `.kiro/specs/booking-and-order-orchestration/`, scheduled Sprint 7) adds
 * the `allowed()` factory then, deliberately and under review. Until it
 * does, `GuardResult` is structurally a denial.
 *
 * ---------------------------------------------------------------------------
 * Why it carries EVERY failing condition, not just the first
 * ---------------------------------------------------------------------------
 * design.md §Payment guard: "All six must hold." A fail-fast guard that
 * returned at the first failure would make conditions 3-6 unobservable —
 * condition 2 is unconditionally denied today, so nothing downstream of it
 * could ever be reached or tested. Evaluating all six and reporting all
 * failures gives design.md §Observability its "guard denial reasons" for
 * real, and lets each condition's behaviour be asserted independently
 * (`GuardPaymentSessionTest`).
 *
 * `condition()`/`reason()`/`publicMessage()`/`missingUpstream()` report the
 * FIRST failure in design.md's fixed order — the plan's `DENIED(condition,
 * publicMessage)` shape — while `denials()` exposes the full list.
 */
final readonly class GuardResult
{
    /**
     * @param  non-empty-list<ConditionDenial>  $denials
     */
    private function __construct(
        private array $denials,
    ) {}

    /**
     * @param  list<ConditionDenial>  $denials  Every failing condition, in
     *                                          `GuardCondition::ORDER`. The caller is responsible for the
     *                                          ordering; `GuardPaymentSession` iterates
     *                                          `GuardCondition::inEvaluationOrder()` so it cannot get it wrong.
     *
     * @throws InvalidArgumentException when `$denials` is empty — an empty
     *                                  denial list is the shape a PASS would have, and constructing
     *                                  one is exactly what ruling 1b-L3-01 forbids.
     */
    public static function denied(array $denials): self
    {
        if ($denials === []) {
            throw new InvalidArgumentException(
                'A GuardResult must carry at least one failing condition. An empty denial list would be a '
                .'pass in all but name, and Wave 1b ruling 1b-L3-01 permits no reachable pass outcome.'
            );
        }

        return new self(array_values($denials));
    }

    /**
     * Always `false`. See the class-level doc block: no factory on this
     * class can produce an allowed result.
     */
    public function isAllowed(): bool
    {
        return false;
    }

    public function isDenied(): bool
    {
        return ! $this->isAllowed();
    }

    /**
     * The first failing condition in design.md's fixed order.
     */
    public function condition(): GuardCondition
    {
        return $this->primary()->condition;
    }

    public function reason(): GuardDenialReason
    {
        return $this->primary()->reason;
    }

    public function publicMessage(): string
    {
        return $this->primary()->publicMessage;
    }

    public function missingUpstream(): ?string
    {
        return $this->primary()->missingUpstream;
    }

    /**
     * True when the FIRST failing condition could not be evaluated at all
     * because its upstream record does not exist. Distinct from a genuine
     * domain denial — see `GuardDenialReason`.
     */
    public function isUnavailableUpstream(): bool
    {
        return $this->reason() === GuardDenialReason::UnavailableUpstream;
    }

    /**
     * Every failing condition, in evaluation order.
     *
     * @return non-empty-list<ConditionDenial>
     */
    public function denials(): array
    {
        return $this->denials;
    }

    /**
     * The condition values that failed — the shape stored in
     * `payment_intents.denied_conditions`.
     *
     * @return non-empty-list<string>
     */
    public function deniedConditionValues(): array
    {
        return array_map(
            static fn (ConditionDenial $denial): string => $denial->condition->value,
            $this->denials,
        );
    }

    private function primary(): ConditionDenial
    {
        return $this->denials[0];
    }
}

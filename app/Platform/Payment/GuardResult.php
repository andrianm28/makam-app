<?php

declare(strict_types=1);

namespace App\Platform\Payment;

use InvalidArgumentException;

/**
 * The result of one `GuardPaymentSession` evaluation.
 *
 * ---------------------------------------------------------------------------
 * There are now TWO factories: `denied()` and `allowed()`
 * ---------------------------------------------------------------------------
 * Wave 1b ruling 1b-L3-01 shipped this class deny-only: "There must be **no
 * reachable PASS outcome**, and therefore no `payment_sessions` row creatable
 * by any caller." That was enforced HERE, in the type — the constructor is
 * private and `denied()` was the only factory, so no caller could construct
 * an allowed result even deliberately.
 *
 * The online-payment gateway task is the deliberately reviewed change that
 * ends the deny-only era: `GuardPaymentSession` can now genuinely evaluate
 * all six conditions, because the merchant/`badan_usaha` binding became real
 * via config (`config('payment.merchant_ref')`/`badan_usaha_ref`, the
 * FIN-DEC-01 provisioning channel the approved design names). When all six
 * hold, the guard returns `allowed()`. The constructor remains private, so
 * an allowed result is still reachable ONLY from `GuardPaymentSession`'s own
 * evaluation — never from a caller pasting one together.
 *
 * The task that lands the remaining missing upstream records is
 * `.kiro/specs/booking-and-order-orchestration/`; it changes the guard's
 * conditions, never this type's shape.
 *
 * ---------------------------------------------------------------------------
 * Why it carries EVERY failing condition, not just the first
 * ---------------------------------------------------------------------------
 * design.md §Payment guard: "All six must hold." A fail-fast guard that
 * returned at the first failure would make conditions 3-6 unobservable —
 * before the gateway task, condition 2 was unconditionally denied, so
 * nothing downstream of it could ever be reached or tested. Evaluating all
 * six and reporting all failures gives design.md §Observability its "guard
 * denial reasons" for real, and lets each condition's behaviour be asserted
 * independently (`GuardPaymentSessionTest`).
 *
 * `condition()`/`reason()`/`publicMessage()`/`missingUpstream()` report the
 * FIRST failure in design.md's fixed order — the plan's `DENIED(condition,
 * publicMessage)` shape — while `denials()` exposes the full list. All five
 * are denial-scoped: calling them on an `allowed()` result throws, because
 * an allowed result has no failing condition to report.
 */
final readonly class GuardResult
{
    /**
     * @param  list<ConditionDenial>  $denials  The failing conditions, in
     *                                          `GuardCondition::ORDER`. Empty exactly for the allowed
     *                                          result.
     */
    private function __construct(
        private array $denials,
    ) {}

    /**
     * The single allowed result: all six conditions held.
     *
     * Reachable only from `GuardPaymentSession::__invoke()` — the constructor
     * is private, so no other caller can build one. An allowed evaluation
     * writes nothing in the guard: the decision record for an allowed
     * opening is written by `Actions\OpenPaymentSession` atomically with the
     * session it authorizes.
     */
    public static function allowed(): self
    {
        return new self([]);
    }

    /**
     * @param  list<ConditionDenial>  $denials  Every failing condition, in
     *                                          `GuardCondition::ORDER`. The caller is responsible for the
     *                                          ordering; `GuardPaymentSession` iterates
     *                                          `GuardCondition::inEvaluationOrder()` so it cannot get it wrong.
     *
     * @throws InvalidArgumentException when `$denials` is empty — an empty
     *                                  denial list is the shape a PASS would have, and constructing
     *                                  one outside the guard's evaluation is exactly what Wave 1b
     *                                  ruling 1b-L3-01 forbids. The allowed state has its OWN
     *                                  factory, `allowed()`; there is no reason for this one to
     *                                  accept it.
     */
    public static function denied(array $denials): self
    {
        if ($denials === []) {
            throw new InvalidArgumentException(
                'A GuardResult must carry at least one failing condition. An empty denial list would be a '
                .'pass in all but name; the allowed state is built with GuardResult::allowed() — never here.'
            );
        }

        return new self(array_values($denials));
    }

    public function isAllowed(): bool
    {
        return $this->denials === [];
    }

    public function isDenied(): bool
    {
        return ! $this->isAllowed();
    }

    /**
     * The first failing condition in design.md's fixed order.
     *
     * @throws InvalidArgumentException on an allowed result — it has no
     *                                  failing condition to report.
     */
    public function condition(): GuardCondition
    {
        return $this->primary()->condition;
    }

    /**
     * @throws InvalidArgumentException on an allowed result.
     */
    public function reason(): GuardDenialReason
    {
        return $this->primary()->reason;
    }

    /**
     * @throws InvalidArgumentException on an allowed result.
     */
    public function publicMessage(): string
    {
        return $this->primary()->publicMessage;
    }

    /**
     * @throws InvalidArgumentException on an allowed result.
     */
    public function missingUpstream(): ?string
    {
        return $this->primary()->missingUpstream;
    }

    /**
     * True when the FIRST failing condition could not be evaluated at all
     * because its upstream record does not exist. Distinct from a genuine
     * domain denial — see `GuardDenialReason`.
     *
     * @throws InvalidArgumentException on an allowed result.
     */
    public function isUnavailableUpstream(): bool
    {
        return $this->reason() === GuardDenialReason::UnavailableUpstream;
    }

    /**
     * Every failing condition, in evaluation order. Empty exactly for the
     * allowed result.
     *
     * @return list<ConditionDenial>
     */
    public function denials(): array
    {
        return $this->denials;
    }

    /**
     * The condition values that failed — the shape stored in
     * `payment_intents.denied_conditions`. Empty exactly for the allowed
     * result.
     *
     * @return list<string>
     */
    public function deniedConditionValues(): array
    {
        return array_map(
            static fn (ConditionDenial $denial): string => $denial->condition->value,
            $this->denials,
        );
    }

    /**
     * @throws InvalidArgumentException on an allowed result.
     */
    private function primary(): ConditionDenial
    {
        if ($this->denials === []) {
            throw new InvalidArgumentException(
                'An allowed GuardResult carries no failing condition; denial-scoped accessors '
                .'(condition(), reason(), publicMessage(), missingUpstream()) cannot be read from it.'
            );
        }

        return $this->denials[0];
    }
}

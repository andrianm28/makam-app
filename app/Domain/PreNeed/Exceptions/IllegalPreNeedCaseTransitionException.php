<?php

declare(strict_types=1);

namespace App\Domain\PreNeed\Exceptions;

use App\Domain\PreNeed\PreNeedCaseStatus;
use DomainException;

/**
 * The transition guard's exception: an illegal `PreNeedCaseStatus` move,
 * or one of the honest refusals the plan's Task 3 brief names for the
 * paid-flow actions ("null -> honest refusal for the order-dependent
 * actions"). Each factory says exactly what precondition was not met, so
 * the admin surface can surface it without parsing messages.
 */
final class IllegalPreNeedCaseTransitionException extends DomainException
{
    public static function between(PreNeedCaseStatus $from, PreNeedCaseStatus $to): self
    {
        return new self("Pre-Need case transition {$from->value} -> {$to->value} is not allowed.");
    }

    /**
     * The case's submit-time pre-need order cannot be resolved through the
     * interest -> booking_draft -> order chain (`PreNeedCase::order()`
     * returned null) — the action cannot proceed without it.
     */
    public static function missingOrder(string $caseId, string $action): self
    {
        return new self(
            "Pre-Need case [{$caseId}] has no resolvable submit-time order; [{$action}] cannot proceed."
        );
    }

    /**
     * The case's interest no longer resolves its booking draft — the
     * quote-line composition (P0 seam) has no draft to read services from.
     */
    public static function missingDraft(string $caseId, string $action): self
    {
        return new self(
            "Pre-Need case [{$caseId}] has no resolvable booking draft; [{$action}] cannot proceed."
        );
    }

    /**
     * The payment schedule must be denominated in the bound quote's single
     * currency; a case without a bound quote has no honest denomination.
     */
    public static function missingQuote(string $caseId): self
    {
        return new self(
            "Pre-Need case [{$caseId}] has no bound quote; the payment schedule cannot be denominated."
        );
    }

    /**
     * `SettlePreNeed`'s verification gate: the case settles only when its
     * pre-need order is actually `DIBAYAR` — the manual-fallback
     * discipline (`MarkOrderPaid`'s evidence-first rule), asserted via the
     * ORDER's status, never inferred.
     */
    public static function orderNotPaid(string $caseId): self
    {
        return new self(
            "Pre-Need case [{$caseId}] cannot settle before its order is paid (DIBAYAR); ".
            'settlement requires verified payment evidence.'
        );
    }

    /**
     * The case keeps its full history — no deletes (plan Task 3:
     * "the case keeps its full history (no deletes)").
     */
    public static function forDelete(): self
    {
        return new self('A Pre-Need case is never deleted: it keeps its full contract history.');
    }
}

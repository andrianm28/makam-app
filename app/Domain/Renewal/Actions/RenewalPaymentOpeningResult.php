<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Actions;

/**
 * The result of one `GuardRenewalPaymentOpening` evaluation.
 *
 * Mirrors `App\Platform\Payment\GuardResult`'s shape — deny-by-default,
 * evaluate every condition, one audited decision record — but is owned by
 * renewal and produces a result that distinguishes "denied" from "allowed
 * but requires manual coordination".
 *
 * ---------------------------------------------------------------------------
 * Manual coordination is not a denial
 * ---------------------------------------------------------------------------
 * Ruling A (coordinator, 12 Aug 2026): when every condition holds but the
 * online-payment gate (G-PAY-01) is closed, the result is "eligible; online
 * unavailable" — `isAllowed() = true`, `isManualCoordinationRequired() = true`.
 * AC8's wording is "online **or** explicit manual fallback"; both are
 * legitimate pass paths. The online path is BLOCKED upstream
 * (PaymentSession throws), so this lane implements only the manual path.
 *
 * AC8's online half is recorded BLOCKED (upstream deny-only) with these
 * citations — never PASS:
 * - `PaymentSession.php:84-87` throws `PaymentSessionCreationUnavailableException`
 * - `GuardResult.php:49,63,79` — `isAllowed()` hardwired false, no allowed() factory
 * - `GuardPaymentSession.php:140` — the pass path is unreachable in the type
 */
final readonly class RenewalPaymentOpeningResult
{
    private function __construct(
        private bool $allowed,
        private bool $manualCoordinationRequired,
        private ?string $denialReason,
    ) {}

    /**
     * @throws \InvalidArgumentException when `$denialReason` is blank on denial
     */
    public static function allowed(bool $manualCoordinationRequired = false): self
    {
        return new self(
            allowed: true,
            manualCoordinationRequired: $manualCoordinationRequired,
            denialReason: null,
        );
    }

    /**
     * @throws \InvalidArgumentException when `$denialReason` is blank
     */
    public static function denied(string $denialReason): self
    {
        if (trim($denialReason) === '') {
            throw new \InvalidArgumentException('A denial must carry a non-blank reason.');
        }

        return new self(
            allowed: false,
            manualCoordinationRequired: false,
            denialReason: $denialReason,
        );
    }

    public function isAllowed(): bool
    {
        return $this->allowed;
    }

    /**
     * True when `isAllowed()` AND the payment gate is closed — the online
     * path is unavailable but the family is eligible for manual coordination.
     */
    public function isManualCoordinationRequired(): bool
    {
        return $this->manualCoordinationRequired;
    }

    public function denialReason(): ?string
    {
        return $this->denialReason;
    }
}

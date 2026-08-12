<?php

declare(strict_types=1);

namespace App\Domain\Renewal\Actions;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Models\Renewal;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\PaymentMode;
use App\Platform\FinancialLedger\Money;

/**
 * AC8 renewal-shaped payment-opening guard — the only path to evaluating
 * whether a renewal payment can proceed.
 *
 * Evaluates all five conditions without short-circuiting, per the plan's
 * condition table (Ruling A, Option A approved). On any failure, returns a
 * denial. When all hold but G-PAY-01 is closed, returns
 * `allowed(manualCoordinationRequired: true)` — the "eligible; online
 * unavailable" state that renders the manual coordination screen.
 *
 * This guard never calls `PaymentSession::create()` — that path throws by
 * design (Wave 1b ruling 1b-L3-01), and Ruling A explicitly forbids
 * attempting it.
 *
 * @see RenewalPaymentOpeningResult
 */
final readonly class GuardRenewalPaymentOpening
{
    public function __construct(
        private ModeResolver $modes,
    ) {}

    /**
     * @throws \InvalidArgumentException when $amount is not a Money instance
     */
    public function __invoke(Renewal $renewal, Money $amount): RenewalPaymentOpeningResult
    {
        // Condition 1 — G-PAY-01: online or manual coordination
        $paymentMode = $this->modes->paymentMode();
        $gateClosed = $paymentMode === PaymentMode::ManualCoordination;

        // Condition 2 — grave record exists and is published (not closed)
        $grave = $renewal->graveRecord;
        $gravePublished = $grave instanceof GraveRecord && $grave->access_mode !== 'closed';

        // Condition 3 — quote accepted and unexpired
        $quote = $renewal->quotes()->latest()->first();
        $quoteValid = $quote && $quote->isAcceptedAndUnexpired();

        // Condition 4 — authorized opening (L5 seam; always true for public journey)
        // No upstream denial possible for the public journey.
        $authorized = true;

        // Condition 5 — amount equals quote total
        $amountMatches = $quote && $quote->amountAsMoney()->compare($amount) === 0;

        // If any condition fails, deny with the specific reason
        if (! $gravePublished) {
            $reason = ! $grave instanceof GraveRecord
                ? 'Grave record not found.'
                : 'Grave record is not available for online renewal.';

            return RenewalPaymentOpeningResult::denied($reason);
        }

        if (! $quoteValid) {
            $reason = ! $quote
                ? 'No quote is available for this renewal.'
                : 'No accepted and unexpired quote is available for this renewal.';

            return RenewalPaymentOpeningResult::denied($reason);
        }

        if (! $amountMatches) {
            return RenewalPaymentOpeningResult::denied('Payment amount does not match the quoted total.');
        }

        // All conditions hold. If the gate is closed, manual coordination is required.
        return RenewalPaymentOpeningResult::allowed(
            manualCoordinationRequired: $gateClosed,
        );
    }
}

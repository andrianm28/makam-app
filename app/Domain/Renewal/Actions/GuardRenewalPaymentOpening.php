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
 * Evaluates every condition before deciding, without short-circuiting, per
 * Ruling A (Option A approved). On any failure, returns a denial naming the
 * specific reason. When all hold but G-PAY-01 is closed, returns
 * `allowed(manualCoordinationRequired: true)` — the "eligible; online
 * unavailable" state that renders the manual coordination screen.
 *
 * This guard never calls `PaymentSession::create()` — that call now lives in
 * `Actions\OpenPaymentSession::authorizeRenewal()`, which refuses to make it
 * whenever this guard's result is denied OR `manualCoordinationRequired`.
 * The online path is blocked only WHILE `G-PAY-01` is closed, matching the
 * `$gateClosed` condition below exactly: when the gate is open and every
 * other condition holds, `authorizeRenewal()` genuinely reaches
 * `PaymentSession::create()` — this guard still never calls it directly,
 * but it is no longer true that the online path is unreachable overall.
 *
 * ---------------------------------------------------------------------------
 * FOUR real conditions, not five — the plan's condition table was optimistic
 * ---------------------------------------------------------------------------
 * The plan lists five conditions and marks all five "real today". Four of them
 * are: the `G-PAY-01` gate mode, the grave record's existence and publication,
 * the quote's acceptance and freshness, and the amount matching the quoted
 * total.
 *
 * The fifth, "authorized opening", is **not applicable** to this surface and
 * has no honest implementation here. The public renewal journey is anonymous
 * by design — there is no actor to authorize. An earlier revision papered over
 * that with a local `$authorized = true` that no branch ever read, which
 * claimed a fifth condition in the shape of dead code. Deleting it is the
 * honest reading: this guard evaluates four conditions for real, and the PR
 * body's "5 of 6 real" claim is corrected to four.
 *
 * Access to a specific renewal on steps 5 and 6 is bearer-UUID: whoever holds
 * the unguessable `renewals.id` may view it. That is deliberate for an
 * anonymous journey with no accounts (the family never signs in), and it is
 * safe here because neither screen projects a grave record field — see
 * `Livewire\Public\Renewal\RenewalPayment`. It is an access model, not an
 * authorization condition, so it is documented rather than counted.
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

        // Condition 4 — amount equals quote total, in integer minor units
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

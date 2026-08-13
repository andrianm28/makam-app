<?php

declare(strict_types=1);

namespace App\Livewire\Public\Renewal;

use App\Domain\Renewal\Actions\GuardRenewalPaymentOpening;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalJourneyStep;
use App\Platform\FeatureGate\ModeResolver;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Step 5, PUB-033 — the payment screen. AC8.
 *
 * Renders the manual-coordination path when `GuardRenewalPaymentOpening`
 * allows the opening, and a refusal otherwise. The step is never removed when
 * the gate is closed (design-system.md §6.9).
 *
 * ---------------------------------------------------------------------------
 * The guard's denial reason is a server-side diagnostic, never page copy
 * ---------------------------------------------------------------------------
 * `RenewalPaymentOpeningResult::denialReason()` names the specific condition
 * that failed — "Grave record not found", "Grave record is not available for
 * online renewal", "Payment amount does not match the quoted total". An
 * earlier revision printed that string straight onto an anonymous page, which
 * turned the screen into an oracle: anyone iterating UUIDs could tell a
 * renewal that does not exist from one whose grave is access-restricted from
 * one whose quote went stale, all without authenticating.
 *
 * This component therefore reduces the guard's outcome to a state flag and the
 * view prints one fixed Indonesian refusal with the support escape hatch. The
 * specific reason stays inside the guard, where an operator-facing surface can
 * still read it.
 *
 * ---------------------------------------------------------------------------
 * Access model
 * ---------------------------------------------------------------------------
 * Bearer-UUID: whoever holds the unguessable `renewals.id` may view this step.
 * That is deliberate for an anonymous journey with no accounts, and safe here
 * because this screen projects no grave record field at all — it names no
 * deceased, no block, and no dates. See `GuardRenewalPaymentOpening`'s doc
 * block for why this is an access model rather than a guard condition.
 */
final class RenewalPayment extends Component
{
    #[Url(as: 'perpanjangan', history: true)]
    public string $perpanjangan = '';

    public function render(): View
    {
        return view('livewire.public.renewal.payment', [
            ...$this->resolveState(),
            'paymentMode' => app(ModeResolver::class)->paymentMode(),
            'currentStep' => RenewalJourneyStep::PAYMENT,
            'stepLabels' => RenewalJourneyStep::labels(),
        ])->layout('layouts.app', [
            'title' => 'Pembayaran Perpanjangan Makam - Makam.co.id',
            'active' => 'perpanjangan',
        ]);
    }

    /**
     * @return array{errorMessage: string, paymentState: string}
     */
    private function resolveState(): array
    {
        $notFound = [
            'errorMessage' => 'Data perpanjangan tidak ditemukan.',
            'paymentState' => 'none',
        ];

        if ($this->perpanjangan === '') {
            return $notFound;
        }

        $renewal = Renewal::query()->find($this->perpanjangan);

        if (! $renewal instanceof Renewal) {
            return $notFound;
        }

        $quote = $renewal->quotes()->latest()->first();

        if ($quote === null) {
            return ['errorMessage' => '', 'paymentState' => 'denied'];
        }

        $result = app(GuardRenewalPaymentOpening::class)($renewal, $quote->amountAsMoney());

        if (! $result->isAllowed()) {
            return ['errorMessage' => '', 'paymentState' => 'denied'];
        }

        return [
            'errorMessage' => '',
            'paymentState' => $result->isManualCoordinationRequired() ? 'manual' : 'online',
        ];
    }
}

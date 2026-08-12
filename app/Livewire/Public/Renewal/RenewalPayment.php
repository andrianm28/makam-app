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

final class RenewalPayment extends Component
{
    #[Url(as: 'perpanjangan', history: true)]
    public string $perpanjangan = '';

    public ?Renewal $renewal = null;

    public ?object $guardResult = null;

    public string $errorMessage = '';

    public function mount(): void
    {
        if ($this->perpanjangan === '') {
            return;
        }

        $this->renewal = Renewal::query()->find($this->perpanjangan);

        if (! $this->renewal instanceof Renewal) {
            $this->errorMessage = 'Data renewal tidak ditemukan.';

            return;
        }

        $quote = $this->renewal->quotes()->latest()->first();
        if (! $quote) {
            $this->guardResult = (object) [
                'state' => 'denied',
                'reason' => 'Tidak ada devis quirote untuk renewal ini.',
            ];

            return;
        }

        $guard = app(GuardRenewalPaymentOpening::class);
        $result = $guard($this->renewal, $quote->amountAsMoney());

        if ($result->isAllowed()) {
            $this->guardResult = (object) [
                'state' => $result->isManualCoordinationRequired() ? 'manual' : 'online',
            ];
        } else {
            $this->guardResult = (object) [
                'state' => 'denied',
                'reason' => $result->denialReason(),
            ];
        }
    }

    public function render(): View
    {
        return view('livewire.public.renewal.payment', [
            'paymentMode' => app(ModeResolver::class)->paymentMode(),
            'currentStep' => RenewalJourneyStep::PAYMENT,
            'stepLabels' => RenewalJourneyStep::labels(),
        ])->layout('layouts.app', [
            'title' => 'Pembayaran Perpanjangan Makam - Makam.co.id',
            'active' => 'perpanjangan',
        ]);
    }
}

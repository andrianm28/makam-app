<?php

declare(strict_types=1);

namespace App\Livewire\Public\Renewal;

use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalJourneyStep;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class RenewalConfirmation extends Component
{
    #[Url(as: 'perpanjangan', history: true)]
    public string $perpanjangan = '';

    public ?Renewal $renewal = null;

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
    }

    public function render(): View
    {
        return view('livewire.public.renewal.confirmation', [
            'currentStep' => RenewalJourneyStep::CONFIRMATION,
            'stepLabels' => RenewalJourneyStep::labels(),
        ])->layout('layouts.app', [
            'title' => 'Konfirmasi Perpanjangan Makam - Makam.co.id',
            'active' => 'perpanjangan',
        ]);
    }
}

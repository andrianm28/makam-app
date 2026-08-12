<?php

declare(strict_types=1);

namespace App\Livewire\Public\Renewal;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\QuoteRenewal;
use App\Domain\Renewal\RenewalJourneyStep;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\GraveSearchMode;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class RenewalFee extends Component
{
    #[Url(as: 'makam', history: true)]
    public string $makam = '';

    public ?GraveRecord $grave = null;

    public ?object $quote = null;

    public bool $quoteUnavailable = false;

    public string $errorMessage = '';

    public function mount(): void
    {
        if ($this->makam === '') {
            return;
        }

        $graveSearchMode = app(ModeResolver::class)->graveSearchMode();
        if ($graveSearchMode === GraveSearchMode::ManualAssistance) {
            $this->errorMessage = 'Pencarian data makam secara online belum tersedia. Silakan hubungi petugas kami.';

            return;
        }

        $this->grave = GraveRecord::query()->find($this->makam);

        if (! $this->grave instanceof GraveRecord) {
            $this->errorMessage = 'Data makam tidak ditemukan.';

            return;
        }

        try {
            $this->quote = app(QuoteRenewal::class)($this->grave);
        } catch (\InvalidArgumentException) {
            $this->quoteUnavailable = true;
        }
    }

    public function render(): View
    {
        return view('livewire.public.renewal.fee', [
            'graveSearchMode' => app(ModeResolver::class)->graveSearchMode(),
            'currentStep' => RenewalJourneyStep::FEE,
            'stepLabels' => RenewalJourneyStep::labels(),
        ])->layout('layouts.app', [
            'title' => 'Biaya Perpanjangan Makam - Makam.co.id',
            'active' => 'perpanjangan',
        ]);
    }
}

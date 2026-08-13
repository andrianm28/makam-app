<?php

declare(strict_types=1);

namespace App\Livewire\Public\Renewal;

use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalJourneyStep;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Step 6, PUB-034 — the confirmation screen. AC9.
 *
 * Renders the server's recorded state and nothing else. `AGENTS.md` §Domain
 * and financial invariants: "Never mark paid from a browser return URL" — this
 * component performs no write of any kind, so arriving here, reloading, or
 * sharing the link is a navigation event and never a state transition.
 *
 * A missing or unknown `perpanjangan` parameter resolves to the not-found
 * state. An earlier revision returned early on the empty-parameter case while
 * leaving the renewal null, which fell through to the success branch and
 * dereferenced null on the reference field.
 *
 * Access is bearer-UUID, as on step 5, and this screen likewise projects no
 * grave record field — only the renewal's own reference, status and dates.
 */
final class RenewalConfirmation extends Component
{
    #[Url(as: 'perpanjangan', history: true)]
    public string $perpanjangan = '';

    public function render(): View
    {
        $renewal = $this->perpanjangan === ''
            ? null
            : Renewal::query()->find($this->perpanjangan);

        return view('livewire.public.renewal.confirmation', [
            'renewal' => $renewal,
            'errorMessage' => $renewal instanceof Renewal ? '' : 'Data perpanjangan tidak ditemukan.',
            'currentStep' => RenewalJourneyStep::CONFIRMATION,
            'stepLabels' => RenewalJourneyStep::labels(),
        ])->layout('layouts.app', [
            'title' => 'Konfirmasi Perpanjangan Makam - Makam.co.id',
            'active' => 'perpanjangan',
        ]);
    }
}

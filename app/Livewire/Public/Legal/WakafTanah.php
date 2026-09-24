<?php

declare(strict_types=1);

namespace App\Livewire\Public\Legal;

use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/wakaf-tanah` — closes AC12 of `.kiro/specs/public-home-and-navigation`
 * ("THE SYSTEM SHALL provide a static Wakaf Tanah information page:
 * purpose of the endowment, general requirements, the six-step process
 * presented as information, and the help-centre contact channel. THE
 * SYSTEM SHALL NOT present a form, accept an upload, or register interest
 * through this page."), tracked as PUB-072 in `docs/product/
 * screen-inventory.md` ("planned, not built") until this batch. Also
 * resolves the real, named gap `home-page.blade.php`'s own doc comment
 * left on the Wakaf Tanah secondary-CTA link (Stage 3 ticket 03: "Wakaf
 * Tanah has no real route anywhere in this codebase ... real, undecided
 * gap") — that link now points here instead of rendering as a disabled
 * control.
 *
 * Same structural precedent as `PrivacyPolicy`/`TermsOfService`, per the
 * Kiro spec's own instruction ("AC12 and AC13 are static pages on the
 * `/privasi` and `/syarat-ketentuan` pattern"): a plain `Livewire\Component`,
 * no domain logic, `->layout('layouts.app', [...])` attached per-render,
 * read-only.
 *
 * Unlike its two siblings, this is NOT a reviewed-or-pending-legal-review
 * document (no `CompanyInfo`/`LegalReviewStatus` disclaimer) — it is
 * operational/informational content sourced directly from the approved
 * PRD (`docs/product/prd-yiem-2026-09-18.md` §1, MK-09, and its process
 * diagram), not a binding legal instrument.
 */
final class WakafTanah extends Component
{
    public function render(): View
    {
        return view('livewire.public.legal.wakaf-tanah')
            ->layout('layouts.app', [
                'title' => 'Wakaf Tanah - Makam.co.id',
                'active' => null,
            ]);
    }
}

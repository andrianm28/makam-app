<?php

declare(strict_types=1);

namespace App\Livewire\Public\Legal;

use App\Support\CompanyInfo;
use App\Support\LegalReviewStatus;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/privasi` — closes the real gap `layouts/app.blade.php`'s own footer doc
 * comment names ("Two NEW links below, `/privasi` and `/syarat-ketentuan`
 * ... a real `<a href>`, not a fabricated claim that the page exists
 * today"): the footer has linked here since the homepage footer was
 * upgraded (26 Jul 2026), but no route or view ever backed it, so clicking
 * it 404s. This component and its sibling `TermsOfService` are the fix.
 *
 * Same structural precedent as every other simple public page in this repo
 * (`App\Livewire\Public\HomePage`, `App\Livewire\Public\ComingSoon\
 * BookingWizardComingSoon`): a plain `Livewire\Component`, no domain logic,
 * `->layout('layouts.app', [...])` attached per-render (not the `#[Layout]`
 * class attribute), read-only — nothing here mutates state or touches
 * `app/Domain/**`.
 *
 * ---------------------------------------------------------------------------
 * The body copy is placeholder legal content, NOT a reviewed legal document
 * ---------------------------------------------------------------------------
 * `docs/planning/sprint-plan.md` §10's delegation table lists "legal/privacy
 * decisions (G7)" as explicitly **"Not delegable to an agent at all"** — no
 * agent, including this one, can make a real legal/privacy decision for this
 * business. This page exists because the footer already links here publicly
 * and 404s without it (a worse, less honest state than a clearly-labelled
 * draft) — the user explicitly authorised placeholder/dummy legal content
 * for full public display on the dev environment, on the express condition
 * that the page says plainly it is a draft pending real legal review.
 * `privacy-policy.blade.php`'s own "Terakhir diperbarui" line carries that
 * sentence and must stay even though the rest of the content is shown in
 * full — see this batch's final report.
 *
 * Company name/address are read from `App\Support\CompanyInfo` — the one
 * place this codebase's placeholder legal-entity info is defined (same
 * single-source discipline `App\Support\ContactInfo` already establishes
 * for placeholder contact details) — not hardcoded here or duplicated in
 * `TermsOfService`/`layouts/app.blade.php`'s footer, all three of which
 * read the same two constants.
 */
final class PrivacyPolicy extends Component
{
    /**
     * A fixed authoring date, not `now()` — using `now()` would silently
     * relabel this page "updated today" on every single request forever,
     * which misrepresents when the draft text actually last changed. This
     * is updated by hand whenever the body copy below actually changes.
     */
    private const UPDATED_AT = '26 Juli 2026';

    public function render(): View
    {
        return view('livewire.public.legal.privacy-policy', [
            'companyName' => CompanyInfo::name(),
            'companyAddress' => CompanyInfo::address(),
            'companyNib' => CompanyInfo::nib(),
            'legalReviewNote' => LegalReviewStatus::note(),
            'updatedAt' => self::UPDATED_AT,
        ])->layout('layouts.app', [
            'title' => 'Kebijakan Privasi - Makam.co.id',
            'active' => null,
        ]);
    }
}

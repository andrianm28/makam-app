<?php

declare(strict_types=1);

namespace App\Livewire\Public\Legal;

use App\Support\CompanyInfo;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/syarat-ketentuan` — sibling of `PrivacyPolicy`; see that class's own
 * doc block for the full reasoning (footer gap being closed, G7
 * non-delegable-legal-decision framing, and the placeholder-but-labelled
 * discipline the user explicitly authorised). Not repeated here in full to
 * avoid duplicating the same explanation in two places — read
 * `PrivacyPolicy`'s doc block first.
 *
 * ---------------------------------------------------------------------------
 * Payment/refund terms are deliberately vague here — a real open decision,
 * not an oversight
 * ---------------------------------------------------------------------------
 * `docs/governance/assumptions-and-gates.md` §5 item 8 lists "Merchant of
 * record, refund, chargeback, fees, tax, and vendor settlement?" as a
 * genuinely OPEN business/legal decision — nothing in this repository has
 * ever settled a specific refund percentage or timeline. `terms-of-
 * service.blade.php`'s payment section says plainly that detailed
 * payment/refund terms are still being finalised and will be published once
 * decided, rather than inventing a number for a question this codebase's
 * own governance doc records as unanswered.
 */
final class TermsOfService extends Component
{
    private const UPDATED_AT = '26 Juli 2026';

    public function render(): View
    {
        return view('livewire.public.legal.terms-of-service', [
            'companyName' => CompanyInfo::NAME,
            'companyAddress' => CompanyInfo::ADDRESS,
            'updatedAt' => self::UPDATED_AT,
        ])->layout('layouts.app', [
            'title' => 'Syarat & Ketentuan - Makam.co.id',
            'active' => null,
        ]);
    }
}

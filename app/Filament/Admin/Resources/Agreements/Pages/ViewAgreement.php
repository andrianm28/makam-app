<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Agreements\Pages;

use App\Domain\AgreementCertificate\Models\Agreement;
use App\Filament\Admin\Resources\Agreements\Actions\AcceptAgreementAction;
use App\Filament\Admin\Resources\Agreements\Actions\SupersedeAgreementAction;
use App\Filament\Admin\Resources\Agreements\AgreementsResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * View page for `AgreementsResource` — the agreement's AC4 display fields,
 * its AC2 acceptance binding, plus the two per-state header actions:
 * 'Terima' (accept — draft → accepted, only on a draft row) and 'Supersesi'
 * (accepted/active → superseded with a new draft version). The action
 * factories own their visibility.
 */
final class ViewAgreement extends ViewRecord
{
    protected static string $resource = AgreementsResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var Agreement $agreement */
        $agreement = $this->getRecord();

        return [
            AcceptAgreementAction::make($agreement),
            SupersedeAgreementAction::make($agreement),
        ];
    }
}

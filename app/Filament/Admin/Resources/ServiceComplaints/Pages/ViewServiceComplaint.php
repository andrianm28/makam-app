<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceComplaints\Pages;

use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Filament\Admin\Resources\ServiceComplaints\Actions\DismissComplaintAction;
use App\Filament\Admin\Resources\ServiceComplaints\Actions\ResolveComplaintAction;
use App\Filament\Admin\Resources\ServiceComplaints\Actions\StartInvestigatingAction;
use App\Filament\Admin\Resources\ServiceComplaints\ServiceComplaintsResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;

final class ViewServiceComplaint extends ViewRecord
{
    protected static string $resource = ServiceComplaintsResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var ServiceComplaint $record */
        $record = $this->getRecord();

        return [
            Action::make('mulaiInvestigasi')
                ->label('Mulai Investigasi')
                ->icon(Heroicon::OutlinedMagnifyingGlass)
                ->color('warning')
                ->visible(fn (): bool => StartInvestigatingAction::visible($record))
                ->authorize(fn (): bool => StartInvestigatingAction::isAuthorized())
                ->requiresConfirmation()
                ->modalHeading('Mulai investigasi keluhan ini?')
                ->action(fn () => StartInvestigatingAction::run($record)),

            Action::make('selesaikan')
                ->label('Selesaikan')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (): bool => ResolveComplaintAction::visible($record))
                ->authorize(fn (): bool => ResolveComplaintAction::isAuthorized())
                ->modalHeading('Selesaikan keluhan ini?')
                ->schema(ResolveComplaintAction::schema())
                ->action(fn (array $data) => ResolveComplaintAction::run($record, $data)),

            Action::make('tolak')
                ->label('Tolak')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->visible(fn (): bool => DismissComplaintAction::visible($record))
                ->authorize(fn (): bool => DismissComplaintAction::isAuthorized())
                ->modalHeading('Tolak keluhan ini?')
                ->schema(DismissComplaintAction::schema())
                ->action(fn (array $data) => DismissComplaintAction::run($record, $data)),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Reconciliations\Pages;

use App\Filament\Admin\Resources\Reconciliations\Actions\UploadProviderStatementAction;
use App\Filament\Admin\Resources\Reconciliations\ReconciliationsResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for `ReconciliationsResource` — the reconciliation register,
 * plus the header 'Unggah Pernyataan Provider' action (CSV upload ->
 * `ProviderStatement` -> `Jobs\ReconcileStatementJob`). The action re-checks
 * finance authority for the specific badan usaha named in the form; see
 * `Actions\UploadProviderStatementAction`.
 */
final class ListReconciliations extends ListRecords
{
    protected static string $resource = ReconciliationsResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            UploadProviderStatementAction::make(),
        ];
    }
}

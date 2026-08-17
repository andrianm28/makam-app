<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PreNeedCases\Pages;

use App\Filament\Admin\Resources\PreNeedCases\PreNeedCaseResource;
use Filament\Resources\Pages\ListRecords;

/**
 * The index page — a plain list. A case is never created from this
 * Resource: `PreNeedCase::create()` belongs to the interest-promotion
 * seam (the domain creates the case linked to a submit-time interest,
 * exactly as `PreNeedPaidFlowTest` does), and the case keeps its full
 * history, so there is no create action here.
 */
final class ListPreNeedCases extends ListRecords
{
    protected static string $resource = PreNeedCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}

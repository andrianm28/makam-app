<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RefundObligations\Pages;

use App\Filament\Admin\Resources\RefundObligations\RefundObligationsResource;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for `RefundObligationsResource` — the outstanding-debt queue,
 * oldest deadline first.
 *
 * No header actions. Both writes this stage adds are per-obligation decisions
 * (`Actions\RecordRefundExecutionAction`, `Actions\ConfirmRefundReceiptAction`)
 * and live on the row they concern, not on the page: a header action would
 * have to ask the operator which debt they meant, which is exactly the
 * mis-selection a money screen should not invite.
 */
final class ListRefundObligations extends ListRecords
{
    protected static string $resource = RefundObligationsResource::class;
}

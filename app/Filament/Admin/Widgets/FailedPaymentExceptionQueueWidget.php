<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Platform\FinancialLedger\Contracts\LedgerReadAuthorizer;
use App\Platform\FinancialLedger\Exceptions\LedgerReadNotAuthorisedException;
use App\Platform\FinancialLedger\Models\ReconciliationException;
use App\Platform\FinancialLedger\ReconciliationExceptionStatus;
use App\Platform\FinancialLedger\ReconciliationExceptionType;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

/**
 * ADM-001 (`.kiro/specs/admin-operations/requirements.md` AC11) — "dashboard
 * exception queues for failed payment, missing operator response, vendor
 * delay, and unmatched renewal."
 *
 * ---------------------------------------------------------------------------
 * Only the failed-payment queue is built here — the other three are NOT
 * ---------------------------------------------------------------------------
 * `docs/superpowers/plans/2026-08-12-platform-admin-operations.md` Task 8
 * already scoped AC11 for this lane and settled this exact question: "The
 * other three AC11 queues (missing operator response, vendor delay,
 * unmatched renewal) have no data model. Render nothing and ledger them; do
 * not fabricate data to fill a widget." Verified independently while
 * building this widget:
 *
 *  - `orders.status` (`App\Domain\OrderWorkflow\OrderStatus`) has no
 *    "awaiting operator" value and no last-staff-touch timestamp column —
 *    only `updated_at`, which is not a documented proxy for it.
 *  - `work_orders` (`App\Domain\VendorFulfillment\Models\WorkOrder`) has no
 *    due-by/SLA column anywhere in the schema — `scheduled_at` exists, but
 *    no threshold for "late" is defined by any spec or ADR.
 *  - `renewals.status` (`App\Domain\Renewal\RenewalStatus`) has no
 *    "unmatched" value, and `renewal_external_markings` is documented as
 *    NOT participating in any matching/reconciliation guard.
 *
 * Inventing a threshold or a status mapping for any of the three would be a
 * silent product decision this batch has no authority to make (the same
 * reasoning `StatusIntent`'s own doc block gives for refusing to invent an
 * intent mapping for an undocumented status family). They are left out
 * entirely rather than shipped as a widget that always reads empty or that
 * fabricates a definition of "late"/"unmatched" nobody approved.
 *
 * ---------------------------------------------------------------------------
 * The failed-payment queue itself
 * ---------------------------------------------------------------------------
 * Sourced from `reconciliation_exceptions` (open findings — a real payment
 * amount disagreement between the ledger and a provider statement), exactly
 * as Task 8 names it. Gated and scoped identically to
 * `FinancialOverviewWidget` — see that class's doc block for why this is
 * `LedgerReadAuthorizer`, not the master-data authorizer, and why every
 * query is filtered by the actor's own granted `entityRefs`.
 */
final class FailedPaymentExceptionQueueWidget extends TableWidget
{
    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        try {
            app(LedgerReadAuthorizer::class)->authorize(app(ActorContext::class));
        } catch (LedgerReadNotAuthorisedException) {
            return false;
        }

        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->heading('Antrian Pembayaran Gagal')
            ->query($this->scopedOpenExceptionsQuery())
            ->columns([
                TextColumn::make('entity_ref')
                    ->label('Badan Usaha'),

                TextColumn::make('period')
                    ->label('Periode'),

                TextColumn::make('type')
                    ->label('Jenis')
                    ->badge()
                    ->color('danger')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        ReconciliationExceptionType::AMOUNT_MISMATCH => 'Selisih Nominal',
                        ReconciliationExceptionType::MISSING => 'Hilang dari Jurnal',
                        ReconciliationExceptionType::EXTRA => 'Kelebihan di Jurnal',
                        ReconciliationExceptionType::UNBALANCED => 'Batch Tidak Seimbang',
                        default => $state,
                    }),

                TextColumn::make('subject_ref')
                    ->label('Referensi'),

                TextColumn::make('created_at')
                    ->label('Ditemukan')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading('Tidak ada pengecualian pembayaran terbuka')
            ->paginated([5, 10, 25]);
    }

    private function scopedOpenExceptionsQuery(): Builder
    {
        try {
            $scope = app(LedgerReadAuthorizer::class)->authorize(app(ActorContext::class));
        } catch (LedgerReadNotAuthorisedException) {
            // canView() already refuses render for this actor; this is
            // defence in depth against a grant revoked between canView()
            // and the table query resolving, matching FinanceReports'
            // own re-authorize-per-call shape.
            return ReconciliationException::query()->whereRaw('1 = 0');
        }

        return ReconciliationException::query()
            ->whereIn('entity_ref', $scope->entityRefs)
            ->where('status', ReconciliationExceptionStatus::OPEN);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Admin\Widgets;

use App\Platform\FinancialLedger\Contracts\LedgerReadAuthorizer;
use App\Platform\FinancialLedger\Exceptions\LedgerReadNotAuthorisedException;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\ProviderEventStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Batch M1b, PAY-02 — the admin-panel surface for a `provider_events` row a
 * settlement job could not resolve after exhausting every retry
 * (`Jobs\ProcessProviderEventJob::failed()`) and for a settlement anomaly a
 * webhook resolved but refused to apply
 * (`ProcessWebhookEvent::auditSettlementAnomaly()`, PAY-03) — both land the
 * row at `ProviderEventStatus::ManualReview`.
 *
 * ---------------------------------------------------------------------------
 * No existing `MANUAL_REVIEW` admin surface to follow — this is new ground
 * ---------------------------------------------------------------------------
 * Grepping the whole app for `ManualReview` before writing this file found
 * no admin-panel consumer of that status anywhere; `ProcessWebhookEvent`
 * already wrote `MANUAL_REVIEW` rows for a settlement conflict, but nothing
 * ever surfaced them. The closest real precedent for "a financial exception
 * queue widget, gated and scoped the same way" is
 * `FailedPaymentExceptionQueueWidget` (gated by `LedgerReadAuthorizer`,
 * scoped to the actor's granted `entityRefs`) — this widget follows that
 * gating convention exactly.
 *
 * `provider_events` carries no `entity_ref`/`badan_usaha_ref` column of its
 * own (only `merchant_ref`, which is not what `LedgerReadAuthorizer`'s scope
 * is keyed on) — the badan-usaha-level scope lives on `payment_sessions`
 * instead, reachable through `provider_transaction_id =
 * payment_sessions.provider_payment_id`. A `MANUAL_REVIEW` row with no
 * matching session (should not happen in practice —
 * `ApplyPaymentSettlement::resolveSessionOrFail()` runs before either
 * anomaly branch can be reached) is excluded rather than shown unscoped,
 * fail-closed the same way `scopedOpenExceptionsQuery()` fails closed on a
 * revoked grant below.
 */
final class PaymentSettlementManualReviewQueueWidget extends TableWidget
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
            ->heading('Antrian Peninjauan Manual Pembayaran')
            ->query($this->scopedManualReviewQuery())
            ->columns([
                TextColumn::make('provider')
                    ->label('Penyedia'),

                TextColumn::make('merchant_ref')
                    ->label('Merchant'),

                TextColumn::make('event_type')
                    ->label('Jenis Peristiwa')
                    ->placeholder('—'),

                TextColumn::make('invoice_reference')
                    ->label('Referensi Faktur')
                    ->placeholder('—'),

                TextColumn::make('rejection_detail')
                    ->label('Detail')
                    ->placeholder('—'),

                TextColumn::make('updated_at')
                    ->label('Terakhir Diperbarui')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('updated_at', 'desc')
            ->emptyStateHeading('Tidak ada peristiwa pembayaran yang menunggu peninjauan manual')
            ->paginated([5, 10, 25]);
    }

    private function scopedManualReviewQuery(): Builder
    {
        try {
            $scope = app(LedgerReadAuthorizer::class)->authorize(app(ActorContext::class));
        } catch (LedgerReadNotAuthorisedException) {
            // canView() already refuses render for this actor; this is
            // defence in depth against a grant revoked between canView()
            // and the table query resolving, matching
            // `FailedPaymentExceptionQueueWidget`'s own re-authorize-per-call
            // shape.
            return ProviderEvent::query()->whereRaw('1 = 0');
        }

        return ProviderEvent::query()
            ->where('status', ProviderEventStatus::ManualReview->value)
            ->whereIn('provider_transaction_id', function (QueryBuilder $query) use ($scope): void {
                $query->select('provider_payment_id')
                    ->from('payment_sessions')
                    ->whereIn('badan_usaha_ref', $scope->entityRefs);
            });
    }
}

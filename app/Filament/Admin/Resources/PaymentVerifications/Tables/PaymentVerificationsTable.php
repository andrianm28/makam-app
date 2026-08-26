<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentVerifications\Tables;

use App\Platform\FinancialLedger\Money;
use App\Platform\Payment\PaymentVerificationStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * List table for `PaymentVerificationsResource` — one row per
 * `payment_verifications` record, newest submission first. Read-only: no row
 * action, no bulk action — this table backs a resource with no write path
 * (see `PaymentVerificationsResource`'s own class-level doc block).
 *
 * `status` renders as plain text, NOT `->badge()`/`->color()` — a deliberate
 * omission, not an oversight: `PaymentVerificationStatus` is its own
 * separate, uncoupled state machine with no canonical
 * `App\Support\Design\StatusIntent` entry, so inventing colour/icon mapping
 * here would be exactly the kind of ad hoc status vocabulary
 * `docs/design/design-system.md` §3.7 exists to prevent.
 *
 * `reference` is still displayed as the caller-supplied free-text string it
 * always was (submission-time display value, not re-derived). Since PAY-02
 * (`2026_08_26_120000_add_order_link_and_amount_to_payment_verifications_
 * table.php`) it is ALSO backed by a real `order_id` foreign key to
 * `marketplace_orders` — `marketplaceOrder.order_number` is shown alongside
 * it so staff can see the real linkage, not just the free-text echo of it.
 */
final class PaymentVerificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Referensi')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('marketplaceOrder.order_number')
                    ->label('Pesanan')
                    ->placeholder('—'),

                TextColumn::make('amount_minor')
                    ->label('Jumlah')
                    ->placeholder('—')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '—' : (new Money($state))->format()),

                TextColumn::make('payment_method')
                    ->label('Metode Pembayaran'),

                TextColumn::make('payment_reference')
                    ->label('Referensi Pembayaran')
                    ->searchable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->formatStateUsing(fn (string $state): string => PaymentVerificationStatus::from($state)->value),

                TextColumn::make('submitted_at')
                    ->label('Waktu Pengajuan')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('decided_at')
                    ->label('Waktu Keputusan')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->defaultSort('submitted_at', 'desc');
    }
}

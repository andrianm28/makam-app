<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MarketplaceOrders\Tables;

use App\Domain\Marketplace\PaymentState;
use App\Filament\Admin\Resources\MarketplaceOrders\MarketplacePaymentStateBadge;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * List-page table for `MarketplaceOrderResource` — columns per the plan's
 * Task 5: order_number (searchable), vendor.name, item count, total in the
 * `Rp` minor-unit presentation, payment_state badge, placed_at. Filterable
 * by payment_state (`PaymentState::KNOWN` literals — the real constant; the
 * plan text's `KNOWN_STATES` name is drift, the literal list is identical).
 *
 * ---------------------------------------------------------------------------
 * Money presentation
 * ---------------------------------------------------------------------------
 * `total_minor` is integer minor units at `config('money.minor_units')` = 2,
 * so `'Rp '.number_format($state / 100, 0, ',', '.')` renders e.g.
 * `250000` as `Rp 2.500` — the same `Rp` presentation the public
 * marketplace presenter uses (whole-rupiah, thousand separators).
 *
 * ---------------------------------------------------------------------------
 * Authorization on row actions
 * ---------------------------------------------------------------------------
 * Only `ViewAction` is exposed here — this resource never edits or deletes
 * orders (they are written exclusively by domain actions), and the money
 * transition lives on the view page as a finance-gated header action
 * (`Actions\MarkMarketplaceOrderPaidAction`). Row-level mount is still
 * hard-gated by `MarketplaceOrderResource::getAuthorizationResponse()`.
 */
final class MarketplaceOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('placed_at', 'desc')
            ->columns([
                TextColumn::make('order_number')
                    ->label('No. Pesanan')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('vendor.name')
                    ->label('Vendor')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('items_count')
                    ->label('Jumlah item')
                    ->sortable(),

                TextColumn::make('total_minor')
                    ->label('Total')
                    ->formatStateUsing(fn (int $state): string => 'Rp '.number_format($state / 100, 0, ',', '.'))
                    ->sortable(),

                TextColumn::make('payment_state')
                    ->label('Status pembayaran')
                    ->badge()
                    ->color(fn (string $state): string => MarketplacePaymentStateBadge::color($state))
                    ->formatStateUsing(fn (string $state): string => MarketplacePaymentStateBadge::label($state))
                    ->sortable(),

                TextColumn::make('placed_at')
                    ->label('Ditempatkan')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('payment_state')
                    ->label('Status pembayaran')
                    ->options(MarketplacePaymentStateBadge::options()),
            ])
            ->recordActions([
                ViewAction::make(),
            ]);
    }
}

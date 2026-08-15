<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Tables;

use App\Domain\OrderWorkflow\OrderStatus;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderStatusBadge;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class BookingOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->orderByDesc('created_at'))
            ->columns([
                TextColumn::make('reference')->label('Referensi')->searchable(),
                TextColumn::make('bookingDraft.customer_full_name')->label('Pemesan')->searchable(),
                TextColumn::make('product_type')->label('Jenis Layanan'),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => BookingOrderStatusBadge::color(OrderStatus::from($state)))
                    ->formatStateUsing(fn (string $state): string => BookingOrderStatusBadge::label(OrderStatus::from($state))),
                TextColumn::make('created_at')->label('Dibuat')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options(collect(OrderStatus::cases())->mapWithKeys(
                        fn (OrderStatus $status): array => [$status->value => BookingOrderStatusBadge::label($status)]
                    )->all()),
            ])
            ->recordActions([
                ViewAction::make()->label('Lihat'),
            ]);
    }
}

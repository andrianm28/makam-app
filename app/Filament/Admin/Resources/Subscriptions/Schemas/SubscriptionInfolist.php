<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Subscriptions\Schemas;

use App\Filament\Admin\Resources\Subscriptions\Tables\SubscriptionsTable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * View-page read-only schema for `SubscriptionsResource`.
 */
final class SubscriptionInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Langganan')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reference')->label('Nomor referensi'),

                        TextEntry::make('grave.slot')
                            ->label('Makam')
                            ->placeholder('—'),

                        TextEntry::make('carePlan.name')
                            ->label('Rencana perawatan')
                            ->placeholder('—'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => SubscriptionsTable::statusLabel($state))
                            ->color(fn (string $state): string => SubscriptionsTable::statusColor($state)),

                        TextEntry::make('frequency')
                            ->label('Frekuensi')
                            ->formatStateUsing(fn (string $state): string => SubscriptionsTable::frequencyLabel($state)),

                        TextEntry::make('current_cycle_number')
                            ->label('Siklus saat ini'),

                        TextEntry::make('created_at')
                            ->label('Tanggal dibuat')
                            ->dateTime('d M Y H:i'),
                    ]),

                Section::make('Rencana Perawatan')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('carePlan.reference')
                            ->label('Nomor referensi')
                            ->placeholder('—'),

                        TextEntry::make('carePlan.price')
                            ->label('Harga')
                            ->formatStateUsing(fn ($state): string => 'Rp '.number_format((float) $state, 0, ',', '.'))
                            ->placeholder('—'),
                    ]),
            ]);
    }
}

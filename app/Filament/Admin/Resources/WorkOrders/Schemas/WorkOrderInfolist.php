<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkOrders\Schemas;

use App\Filament\Admin\Resources\WorkOrders\Tables\WorkOrdersTable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * View-page read-only schema for the admin `WorkOrdersResource`.
 */
final class WorkOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Pesanan Kerja')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reference')->label('Nomor'),

                        TextEntry::make('carePlan.name')
                            ->label('Rencana perawatan')
                            ->placeholder('—'),

                        TextEntry::make('vendor.name')
                            ->label('Vendor saat ini')
                            ->placeholder('Belum ditugaskan'),

                        TextEntry::make('status')
                            ->label('Status pekerjaan')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => WorkOrdersTable::statusLabel($state))
                            ->color(fn (string $state): string => WorkOrdersTable::statusColor($state)),

                        TextEntry::make('scheduled_at')
                            ->label('Jadwal')
                            ->dateTime('d M Y H:i')
                            ->placeholder('—'),

                        TextEntry::make('completed_at')
                            ->label('Selesai pada')
                            ->dateTime('d M Y H:i')
                            ->placeholder('—'),

                        TextEntry::make('notes')
                            ->label('Catatan')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}

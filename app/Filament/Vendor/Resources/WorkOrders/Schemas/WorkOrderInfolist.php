<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\WorkOrders\Schemas;

use App\Filament\Vendor\Resources\WorkOrders\Tables\WorkOrdersTable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * View-page read-only schema for `WorkOrdersResource`.
 * Two separate badges: billing status and work order status (AC2/AC6).
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

                        TextEntry::make('status')
                            ->label('Status pekerjaan')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => WorkOrdersTable::statusLabel($state))
                            ->color(fn (string $state): string => WorkOrdersTable::statusColor($state)),

                        TextEntry::make('scheduled_at')
                            ->label('Jadwal')
                            ->dateTime('d M Y H:i')
                            ->placeholder('—'),

                        TextEntry::make('started_at')
                            ->label('Mulai')
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

                Section::make('Checklist')
                    ->schema([
                        TextEntry::make('tasks')
                            ->label('Item checklist')
                            ->placeholder('Tidak ada checklist')
                            ->columnSpanFull()
                            ->formatStateUsing(function ($state): string {
                                if ($state === null || $state->isEmpty()) {
                                    return '—';
                                }

                                return $state->map(function ($task) {
                                    $icon = $task->status === 'completed' ? '✓' : '○';
                                    return $icon.' '.$task->name;
                                })->implode("\n");
                            }),
                    ]),
            ]);
    }
}

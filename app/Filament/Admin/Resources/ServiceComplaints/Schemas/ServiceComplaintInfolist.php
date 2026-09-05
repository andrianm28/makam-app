<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceComplaints\Schemas;

use App\Domain\VendorFulfillment\MakeGoodStatus;
use App\Filament\Admin\Resources\ServiceComplaints\Tables\ServiceComplaintsTable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class ServiceComplaintInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Keluhan')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('workOrder.reference')
                            ->label('Pesanan Kerja')
                            ->placeholder('—'),

                        TextEntry::make('customer.name')
                            ->label('Pelanggan')
                            ->placeholder('—'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => ServiceComplaintsTable::statusLabel($state))
                            ->color(fn (string $state): string => ServiceComplaintsTable::statusColor($state)),

                        TextEntry::make('filed_at')
                            ->label('Diajukan pada')
                            ->dateTime('d M Y H:i'),

                        TextEntry::make('complaint_text')
                            ->label('Isi Keluhan')
                            ->columnSpanFull(),

                        TextEntry::make('resolution_notes')
                            ->label('Catatan Penyelesaian')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('resolved_at')
                            ->label('Diselesaikan pada')
                            ->dateTime('d M Y H:i')
                            ->placeholder('—'),

                        TextEntry::make('makeGood.status')
                            ->label('Status Pesanan Perbaikan (Make-Good)')
                            ->badge()
                            ->formatStateUsing(fn (?string $state): ?string => $state === null ? null : match ($state) {
                                MakeGoodStatus::Pending->value => 'Menunggu',
                                MakeGoodStatus::InProgress->value => 'Dikerjakan',
                                MakeGoodStatus::Completed->value => 'Selesai',
                                default => $state,
                            })
                            ->color(fn (?string $state): string => match ($state) {
                                MakeGoodStatus::Pending->value => 'warning',
                                MakeGoodStatus::InProgress->value => 'info',
                                MakeGoodStatus::Completed->value => 'success',
                                default => 'gray',
                            })
                            ->placeholder('—'),

                        TextEntry::make('makeGood.replacementWorkOrder.reference')
                            ->label('Pesanan Kerja Pengganti')
                            ->placeholder('—'),
                    ]),
            ]);
    }
}

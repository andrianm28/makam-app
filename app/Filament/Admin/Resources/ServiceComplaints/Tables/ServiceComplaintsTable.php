<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceComplaints\Tables;

use App\Domain\VendorFulfillment\ComplaintStatus;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class ServiceComplaintsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('workOrder.reference')
                    ->label('Pesanan Kerja')
                    ->placeholder('—'),

                TextColumn::make('complaint_text')
                    ->label('Keluhan')
                    ->limit(60)
                    ->tooltip(fn (string $state): ?string => strlen($state) > 60 ? $state : null),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state)),

                TextColumn::make('filed_at')
                    ->label('Diajukan')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('resolved_at')
                    ->label('Diselesaikan')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions()),
            ])
            ->emptyStateHeading('Belum ada keluhan layanan')
            ->emptyStateDescription('Keluhan yang diajukan pelanggan akan muncul di sini.')
            ->emptyStateIcon(Heroicon::OutlinedExclamationTriangle)
            ->defaultSort('filed_at', 'desc');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            ComplaintStatus::Open->value => 'Terbuka',
            ComplaintStatus::Investigating->value => 'Sedang Diselidiki',
            ComplaintStatus::Resolved->value => 'Selesai',
            ComplaintStatus::Dismissed->value => 'Ditolak',
            default => $status,
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            ComplaintStatus::Open->value => 'danger',
            ComplaintStatus::Investigating->value => 'warning',
            ComplaintStatus::Resolved->value => 'success',
            ComplaintStatus::Dismissed->value => 'gray',
            default => 'gray',
        };
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return [
            ComplaintStatus::Open->value => 'Terbuka',
            ComplaintStatus::Investigating->value => 'Sedang Diselidiki',
            ComplaintStatus::Resolved->value => 'Selesai',
            ComplaintStatus::Dismissed->value => 'Ditolak',
        ];
    }
}

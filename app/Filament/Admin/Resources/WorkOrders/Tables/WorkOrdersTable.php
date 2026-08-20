<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkOrders\Tables;

use App\Domain\VendorFulfillment\WorkOrderStatus;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * List table for the admin `WorkOrdersResource`.
 */
final class WorkOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Nomor')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('carePlan.name')
                    ->label('Rencana perawatan')
                    ->placeholder('—'),

                TextColumn::make('vendor.name')
                    ->label('Vendor')
                    ->placeholder('Belum ditugaskan'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->color(fn (string $state): string => self::statusColor($state)),

                TextColumn::make('scheduled_at')
                    ->label('Jadwal')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions()),
            ])
            ->emptyStateHeading('Belum ada pesanan kerja')
            ->emptyStateDescription('Pesanan kerja perawatan akan muncul di sini setelah siklus langganan dibayar.')
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentCheck)
            ->defaultSort('created_at', 'desc');
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            WorkOrderStatus::Pending->value => 'Menunggu',
            WorkOrderStatus::Assigned->value => 'Ditugaskan',
            WorkOrderStatus::Scheduled->value => 'Terjadwal',
            WorkOrderStatus::InProgress->value => 'Sedang dikerjakan',
            WorkOrderStatus::Completed->value => 'Selesai',
            WorkOrderStatus::Missed->value => 'Terlewat',
            WorkOrderStatus::Complaint->value => 'Komplain',
            default => $status,
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            WorkOrderStatus::Pending->value => 'gray',
            WorkOrderStatus::Assigned->value => 'info',
            WorkOrderStatus::Scheduled->value => 'info',
            WorkOrderStatus::InProgress->value => 'warning',
            WorkOrderStatus::Completed->value => 'success',
            WorkOrderStatus::Missed->value => 'danger',
            WorkOrderStatus::Complaint->value => 'danger',
            default => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function statusOptions(): array
    {
        return [
            WorkOrderStatus::Pending->value => 'Menunggu',
            WorkOrderStatus::Assigned->value => 'Ditugaskan',
            WorkOrderStatus::Scheduled->value => 'Terjadwal',
            WorkOrderStatus::InProgress->value => 'Sedang dikerjakan',
            WorkOrderStatus::Completed->value => 'Selesai',
            WorkOrderStatus::Missed->value => 'Terlewat',
            WorkOrderStatus::Complaint->value => 'Komplain',
        ];
    }
}

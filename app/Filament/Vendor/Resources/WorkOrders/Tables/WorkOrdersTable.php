<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\WorkOrders\Tables;

use App\Domain\VendorFulfillment\WorkOrderStatus;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * List table for `WorkOrdersResource` — vendor's work order queue.
 * 44px row height for mobile outdoor use.
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
                    ->label('Rencana')
                    ->placeholder('—'),

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

                TextColumn::make('notes')
                    ->label('Catatan')
                    ->limit(50)
                    ->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(self::statusOptions()),
            ])
            ->emptyStateHeading('Tidak ada pekerjaan terjadwal')
            ->emptyStateDescription('Pekerjaan perawatan untuk klien Anda akan muncul di sini.')
            ->emptyStateIcon(Heroicon::OutlinedClipboardDocumentCheck)
            ->defaultSort('scheduled_at', 'asc')
            ->recordUrl(fn (WorkOrder $record): string => route('filament.vendor.resources.work-orders.view', ['record' => $record->getKey()]));
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
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
            WorkOrderStatus::Scheduled->value => 'Terjadwal',
            WorkOrderStatus::InProgress->value => 'Sedang dikerjakan',
            WorkOrderStatus::Completed->value => 'Selesai',
            WorkOrderStatus::Missed->value => 'Terlewat',
            WorkOrderStatus::Complaint->value => 'Komplain',
        ];
    }
}

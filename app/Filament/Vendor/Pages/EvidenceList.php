<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Domain\Marketplace\Models\VendorOrderEvidence;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Resources\Pages\ListRecords;

class EvidenceList extends ListRecords
{
    protected static ?string $title = 'Bukti Pekerjaan';
    protected static ?string $navigationIcon = 'heroicon-o-photo';
    protected static ?string $navigationLabel = 'Bukti';

    protected static string $model = VendorOrderEvidence::class;

    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('order.uuid')
                ->label('ID Pesanan')
                ->truncate(8),
            BadgeColumn::make('evidence_type')
                ->label('Jenis Bukti')
                ->color('info')
                ->formatStateUsing(fn (string $state): string => match ($state) {
                    'PHOTO' => 'Foto',
                    'DOCUMENT' => 'Dokumen',
                    default => $state,
                }),
            TextColumn::make('uploaded_at')
                ->label('Diunggah')
                ->dateTime(),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Domain\Marketplace\Models\VendorOrderEvidence;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\BadgeColumn;
use Filament\Resources\Pages\Page;

class EvidenceList extends Page
{
    protected static ?string $title = 'Bukti Pekerjaan';
    protected static ?string $navigationIcon = 'heroicon-o-photo';
    protected static ?string $navigationLabel = 'Bukti';

    protected static string $view = 'filament-vendor::pages.evidence-list';

    /**
     * @return array<int, \Filament\Tables\Columns\Column|\Filament\Tables\Columns\BadgeColumn>
     */
    protected function getTableColumns(): array
    {
        return [
            TextColumn::make('order.uuid')
                ->label('ID Pesanan')
                ->truncate(8),
            TextColumn::make('evidence_type')
                ->label('Jenis Bukti')
                ->badge(),
            TextColumn::make('uploaded_at')
                ->label('Diunggah')
                ->dateTime(),
        ];
    }
}

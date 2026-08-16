<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ModerationCases\Tables;

use App\Domain\Memorial\Models\ModerationCase;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * List table for `ModerationCaseResource` — the AC6 queue (open cases
 * first, then recency — the sort order the resource query already
 * applies). Shows the reported profile, the reported content reference,
 * the abuse-reason trail, and the case status badge.
 */
final class ModerationCasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(
                        fn (string $state): string => match ($state) {
                            ModerationCase::STATUS_RESOLVED => 'Selesai',
                            ModerationCase::STATUS_DISMISSED => 'Ditolak',
                            default => 'Terbuka',
                        }
                    )
                    ->color(fn (string $state): string => match ($state) {
                        ModerationCase::STATUS_RESOLVED => 'success',
                        ModerationCase::STATUS_DISMISSED => 'gray',
                        default => 'warning',
                    }),

                TextColumn::make('profile.display_name')
                    ->label('Profil memorial')
                    ->placeholder('—'),

                TextColumn::make('profile.graveRecord.cemetery.name')
                    ->label('TPU'),

                TextColumn::make('reported_content_type')
                    ->label('Jenis konten')
                    ->formatStateUsing(fn (string $state): string => $state === 'memorial_media' ? 'Media' : 'Catatan'),

                TextColumn::make('abuseReports.reason')
                    ->label('Alasan laporan')
                    ->wrap()
                    ->limit(80)
                    ->placeholder('—'),

                TextColumn::make('created_at')
                    ->label('Dilaporkan')
                    ->dateTime()
                    ->sortable(),
            ])
            ->searchable();
    }
}

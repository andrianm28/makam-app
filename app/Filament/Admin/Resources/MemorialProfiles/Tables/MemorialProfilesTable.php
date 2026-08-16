<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MemorialProfiles\Tables;

use App\Domain\Memorial\MemorialPrivacyMode;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * List table for `MemorialProfileResource` — grave ref, privacy badge,
 * published state (the plan's Task 4 brief).
 *
 * Privacy badges: private `neutral` · family-only `neutral` · unlisted
 * `pending` · public `info` (an intentional state, not a success) — the
 * kiro tasks.md intent mapping. Published state reads the profile's own
 * `published_at`/`unpublished_at` timestamps.
 */
final class MemorialProfilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label('Nama tampilan')
                    ->placeholder('—')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('graveRecord.cemetery.name')
                    ->label('TPU')
                    ->sortable(),

                TextColumn::make('graveRecord.deceased_name')
                    ->label('Catatan makam')
                    ->placeholder('—'),

                TextColumn::make('privacy_mode')
                    ->label('Privasi')
                    ->badge()
                    ->formatStateUsing(
                        fn (string $state): string => match ($state) {
                            MemorialPrivacyMode::PUBLIC->value => 'Publik',
                            MemorialPrivacyMode::UNLISTED->value => 'Tidak terdaftar',
                            MemorialPrivacyMode::FAMILY_ONLY->value => 'Keluarga',
                            default => 'Pribadi',
                        }
                    )
                    ->color(fn (string $state): string => match ($state) {
                        MemorialPrivacyMode::PUBLIC->value => 'info',
                        MemorialPrivacyMode::UNLISTED->value => 'warning',
                        MemorialPrivacyMode::FAMILY_ONLY->value => 'gray',
                        default => 'gray',
                    }),

                TextColumn::make('published_at')
                    ->label('Status terbit')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state !== null ? 'Terbit' : 'Draft')
                    ->color(fn (?string $state): string => $state !== null ? 'success' : 'gray'),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime()
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->searchable();
    }
}

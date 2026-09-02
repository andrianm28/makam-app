<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MemorialProfiles\Schemas;

use App\Domain\Memorial\MemorialPrivacyMode;
use App\Domain\Memorial\Models\MemorialProfile;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * View-page read-only schema for `MemorialProfileResource` (2 Sep 2026 UAT
 * finding: `ViewMemorialProfile` extended Filament's `ViewRecord` with no
 * `infolist()` anywhere — not on the page, not on the Resource — so the
 * view rendered its four relation-manager tabs with a completely empty
 * body above them; the record's own fields (privacy, publish state, its
 * grave record) were never shown anywhere). Same shape as
 * `CarePlanInfolist` — a dedicated `Schemas` class the Resource delegates
 * to, not inline on the page.
 *
 * `display_name` is deliberately shown even when null (`placeholder('—')`,
 * never a fallback to the grave record's name) — `MemorialProfile`'s own
 * doc block: "display_name is family-authored content... NEVER
 * auto-derived from grave_records.deceased_name". An empty value here is
 * a real, correct state (no family member has set one yet), not a bug to
 * paper over.
 */
final class MemorialProfileInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Profil Memorial')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('display_name')
                            ->label('Nama tampilan')
                            ->placeholder('— (belum diisi keluarga)'),

                        TextEntry::make('graveRecord.cemetery.name')
                            ->label('TPU')
                            ->placeholder('—'),

                        TextEntry::make('graveRecord.deceased_name')
                            ->label('Catatan makam')
                            ->placeholder('—'),

                        TextEntry::make('privacy_mode')
                            ->label('Privasi')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => match ($state) {
                                MemorialPrivacyMode::PUBLIC->value => 'Publik',
                                MemorialPrivacyMode::UNLISTED->value => 'Tidak terdaftar',
                                MemorialPrivacyMode::FAMILY_ONLY->value => 'Keluarga',
                                default => 'Pribadi',
                            })
                            ->color(fn (string $state): string => match ($state) {
                                MemorialPrivacyMode::PUBLIC->value => 'info',
                                MemorialPrivacyMode::UNLISTED->value => 'warning',
                                MemorialPrivacyMode::FAMILY_ONLY->value => 'gray',
                                default => 'gray',
                            }),

                        TextEntry::make('publish_status')
                            ->label('Status terbit')
                            ->state(fn (MemorialProfile $record): string => $record->published_at !== null ? 'published' : 'draft')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => $state === 'published' ? 'Terbit' : 'Draft')
                            ->color(fn (string $state): string => $state === 'published' ? 'success' : 'gray'),

                        TextEntry::make('published_at')
                            ->label('Diterbitkan pada')
                            ->dateTime()
                            ->placeholder('—'),

                        TextEntry::make('unpublished_at')
                            ->label('Ditarik pada')
                            ->dateTime()
                            ->placeholder('—'),

                        TextEntry::make('created_at')
                            ->label('Dibuat')
                            ->dateTime(),
                    ]),
            ]);
    }
}

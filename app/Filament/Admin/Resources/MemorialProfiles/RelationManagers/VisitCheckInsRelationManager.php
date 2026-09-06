<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MemorialProfiles\RelationManagers;

use App\Domain\Memorial\MemorialModerationState;
use App\Filament\Admin\Resources\MemorialProfiles\MemorialProfileResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * `memorial_visit_checkins` for `MemorialProfileResource` — moderator
 * VISIBILITY of check-in notes only
 * (`docs/superpowers/specs/2026-09-05-memorial-visit-checkin-design.md`
 * §4.6). Read-only, mirroring `MediaRelationManager`'s own precedent: no
 * per-state moderation action exists for this field in this batch.
 */
final class VisitCheckInsRelationManager extends RelationManager
{
    protected static string $relationship = 'visitCheckIns';

    protected static ?string $title = 'Kunjungan';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return MemorialProfileResource::canAccess();
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->defaultSort('checked_in_at', 'desc')
            ->columns([
                TextColumn::make('checked_in_at')
                    ->label('Waktu kunjungan')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('visitor_label')
                    ->label('Nama')
                    ->placeholder('—'),

                TextColumn::make('note')
                    ->label('Catatan')
                    ->wrap()
                    ->limit(160)
                    ->placeholder('—'),

                TextColumn::make('moderation_state')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        MemorialModerationState::APPROVED->value => 'Disetujui',
                        MemorialModerationState::REJECTED->value => 'Ditolak',
                        MemorialModerationState::HIDDEN->value => 'Disembunyikan',
                        default => 'Menunggu',
                    })
                    ->color(fn (string $state): string => match ($state) {
                        MemorialModerationState::APPROVED->value => 'success',
                        MemorialModerationState::REJECTED->value => 'danger',
                        MemorialModerationState::HIDDEN->value => 'warning',
                        default => 'gray',
                    }),
            ]);
    }
}

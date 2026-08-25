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
 * `memorial_media` for `MemorialProfileResource` — read-only: every
 * `memorial_media` row exists only when its vault document is ACCEPTED
 * (the model's creating guard), so this table shows accepted attachments
 * and their moderation state (the plan's Task 4 brief: "view
 * accepted/quarantined"). The media BYTES never render here — the vault
 * documents stay private; only the reference + state display.
 *
 * No write actions: media moderation follows the same
 * `ModerateMemorialContent` shape as contents when a media moderation
 * path is approved; today the row states are visible and auditable here.
 */
final class MediaRelationManager extends RelationManager
{
    protected static string $relationship = 'media';

    protected static ?string $title = 'Media kenangan';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return MemorialProfileResource::canAccess();
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('storage_ref')
                    ->label('Dokumen vault')
                    ->copyable()
                    ->limit(24),

                TextColumn::make('moderation_state')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(
                        fn (string $state): string => match ($state) {
                            MemorialModerationState::APPROVED->value => 'Disetujui',
                            MemorialModerationState::REJECTED->value => 'Ditolak',
                            MemorialModerationState::HIDDEN->value => 'Disembunyikan',
                            default => 'Menunggu',
                        }
                    )
                    ->color(fn (string $state): string => match ($state) {
                        MemorialModerationState::APPROVED->value => 'success',
                        MemorialModerationState::REJECTED->value => 'danger',
                        MemorialModerationState::HIDDEN->value => 'warning',
                        default => 'gray',
                    }),

                TextColumn::make('created_at')
                    ->label('Terpasang')
                    ->dateTime()
                    ->sortable(),
            ]);
    }
}

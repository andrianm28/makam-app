<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MemorialProfiles\RelationManagers;

use App\Domain\Memorial\Actions\ModerateMemorialContent;
use App\Domain\Memorial\MemorialModerationState;
use App\Domain\Memorial\Models\MemorialContent;
use App\Filament\Admin\Resources\MemorialProfiles\MemorialProfileResource;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * `memorial_contents` for `MemorialProfileResource` — the AC6 moderation
 * surface (`.kiro/specs/memorial-and-qr/requirements.md` AC6; the plan's
 * Task 4 brief: "moderate actions per-state (approve/reject/hide)").
 *
 * Each row action is offered per-state: `approve` (visible unless already
 * approved), `reject` (unless rejected), `hide` (unless hidden) — every
 * transition runs `ModerateMemorialContent`, which writes the audit row
 * and the `memorial.content_moderated.v1` outbox event in the same
 * transaction. Only approved rows ever render publicly.
 */
final class ContentsRelationManager extends RelationManager
{
    protected static string $relationship = 'contents';

    protected static ?string $title = 'Catatan keluarga';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return MemorialProfileResource::canAccess();
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('body')
                    ->label('Isi catatan')
                    ->wrap()
                    ->limit(120),

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
                    ->label('Dikirim')
                    ->dateTime()
                    ->sortable(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label('Setujui')
                    ->icon('heroicon-o-check-circle')
                    ->authorize(fn (): bool => MemorialProfileResource::canAccess())
                    ->visible(fn (MemorialContent $record): bool => $record->moderation_state !== MemorialModerationState::APPROVED->value)
                    ->action(function (MemorialContent $record): void {
                        $actor = app(ActorContext::class);

                        app(ModerateMemorialContent::class)(
                            $record,
                            MemorialModerationState::APPROVED,
                            $actor->identityReference ?? 'anonymous',
                            MemorialProfileResource::auditRoleFor($actor),
                        );
                    }),

                Action::make('reject')
                    ->label('Tolak')
                    ->color('danger')
                    ->icon('heroicon-o-x-circle')
                    ->authorize(fn (): bool => MemorialProfileResource::canAccess())
                    ->visible(fn (MemorialContent $record): bool => $record->moderation_state !== MemorialModerationState::REJECTED->value)
                    ->action(function (MemorialContent $record): void {
                        $actor = app(ActorContext::class);

                        app(ModerateMemorialContent::class)(
                            $record,
                            MemorialModerationState::REJECTED,
                            $actor->identityReference ?? 'anonymous',
                            MemorialProfileResource::auditRoleFor($actor),
                        );
                    }),

                Action::make('hide')
                    ->label('Sembunyikan')
                    ->color('warning')
                    ->icon('heroicon-o-eye-slash')
                    ->authorize(fn (): bool => MemorialProfileResource::canAccess())
                    ->visible(fn (MemorialContent $record): bool => $record->moderation_state !== MemorialModerationState::HIDDEN->value)
                    ->action(function (MemorialContent $record): void {
                        $actor = app(ActorContext::class);

                        app(ModerateMemorialContent::class)(
                            $record,
                            MemorialModerationState::HIDDEN,
                            $actor->identityReference ?? 'anonymous',
                            MemorialProfileResource::auditRoleFor($actor),
                        );
                    }),
            ]);
    }
}

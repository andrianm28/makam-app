<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ModerationCases\Pages;

use App\Domain\Memorial\Actions\ResolveModerationCase;
use App\Domain\Memorial\Models\ModerationCase;
use App\Filament\Admin\Resources\ModerationCases\ModerationCaseResource;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;

/**
 * View page for `ModerationCaseResource` — the case's report trail plus
 * the resolve/dismiss header actions (the plan's Task 4 brief:
 * "resolve/dismiss with reason + audit").
 *
 * Both actions require a reason (the moderator closing a case must record
 * why — enforced by `ResolveModerationCase`) and run that domain action,
 * which writes the audit row in the same transaction. Dismissal is the
 * "no violation found" close; resolution is the "acted on" close.
 */
final class ViewModerationCase extends ViewRecord
{
    protected static string $resource = ModerationCaseResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('resolve')
                ->label('Selesaikan')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalHeading('Selesaikan kasus ini?')
                ->modalDescription(
                    'Menandai kasus selesai karena telah ditindaklanjuti. '
                    .'Alasan wajib diisi dan tercatat di audit.'
                )
                ->visible(fn (ModerationCase $record): bool => $record->status === ModerationCase::STATUS_OPEN)
                ->schema([
                    Textarea::make('reason')
                        ->label('Alasan penyelesaian')
                        ->required()
                        ->maxLength(1000)
                        ->helperText('Tercatat di jejak audit.'),
                ])
                ->action(function (ModerationCase $record, array $data): void {
                    $actor = app(ActorContext::class);

                    app(ResolveModerationCase::class)(
                        $record,
                        ModerationCase::STATUS_RESOLVED,
                        (string) $data['reason'],
                        $actor->identityReference ?? 'anonymous',
                        ModerationCaseResource::auditRoleFor($actor),
                    );
                }),

            Action::make('dismiss')
                ->label('Tolak laporan')
                ->color('danger')
                ->icon('heroicon-o-x-circle')
                ->requiresConfirmation()
                ->modalHeading('Tolak laporan ini?')
                ->modalDescription(
                    'Menutup kasus tanpa tindakan karena tidak ditemukan '
                    .'pelanggaran. Alasan wajib diisi dan tercatat di audit.'
                )
                ->visible(fn (ModerationCase $record): bool => $record->status === ModerationCase::STATUS_OPEN)
                ->schema([
                    Textarea::make('reason')
                        ->label('Alasan penolakan')
                        ->required()
                        ->maxLength(1000)
                        ->helperText('Tercatat di jejak audit.'),
                ])
                ->action(function (ModerationCase $record, array $data): void {
                    $actor = app(ActorContext::class);

                    app(ResolveModerationCase::class)(
                        $record,
                        ModerationCase::STATUS_DISMISSED,
                        (string) $data['reason'],
                        $actor->identityReference ?? 'anonymous',
                        ModerationCaseResource::auditRoleFor($actor),
                    );
                }),
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MemorialProfiles\Pages;

use App\Domain\Memorial\Actions\PublishMemorial;
use App\Domain\Memorial\Actions\UnpublishMemorial;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Filament\Admin\Resources\MemorialProfiles\MemorialProfileResource;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * View page for `MemorialProfileResource` — renders the record with its
 * four relation managers (editors/contents/media/QR tokens) and carries
 * the moderator-backed publish/unpublish header actions.
 *
 * Publish/unpublish run the domain actions (`PublishMemorial`/
 * `UnpublishMemorial` — audited + outboxed, idempotent) and are gated by
 * `MemorialProfileResource::actorMayModerate()` — the role gate the
 * Lane-3 review watch demands ("PublishMemorial must be moderator-backed
 * in the ADMIN surface"). The confirmation modal states the consequence
 * explicitly (kiro tasks.md: publishing is "the highest-stakes
 * confirmation in the product"; unpublish is immediate, AC5).
 */
final class ViewMemorialProfile extends ViewRecord
{
    protected static string $resource = MemorialProfileResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('publish')
                ->label('Terbitkan')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalHeading('Terbitkan memorial ini?')
                ->modalDescription(
                    'Memorial ini dapat dilihat siapa saja yang memindai kode QR, '
                    .'sesuai pengaturan privasinya. Hanya konten yang disetujui '
                    .'moderator yang tampil.'
                )
                ->authorize(fn (): bool => MemorialProfileResource::actorMayModerate(app(ActorContext::class)))
                ->visible(fn (MemorialProfile $record): bool => $record->published_at === null)
                ->action(function (MemorialProfile $record): void {
                    $actor = app(ActorContext::class);

                    app(PublishMemorial::class)(
                        $record,
                        $actor->identityReference ?? 'anonymous',
                        MemorialProfileResource::auditRoleFor($actor),
                    );
                }),

            Action::make('unpublish')
                ->label('Tarik dari publik')
                ->color('danger')
                ->icon('heroicon-o-eye-slash')
                ->requiresConfirmation()
                ->modalHeading('Tarik memorial ini dari publik?')
                ->modalDescription(
                    'Memorial langsung tidak lagi dapat diakses publik. '
                    .'Kode QR yang sudah dicetak berhenti berlaku seketika (AC5).'
                )
                ->authorize(fn (): bool => MemorialProfileResource::actorMayModerate(app(ActorContext::class)))
                ->visible(fn (MemorialProfile $record): bool => $record->published_at !== null)
                ->action(function (MemorialProfile $record): void {
                    $actor = app(ActorContext::class);

                    app(UnpublishMemorial::class)(
                        $record,
                        $actor->identityReference ?? 'anonymous',
                        MemorialProfileResource::auditRoleFor($actor),
                    );
                }),
        ];
    }
}

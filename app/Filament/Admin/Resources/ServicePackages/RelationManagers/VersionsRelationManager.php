<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServicePackages\RelationManagers;

use App\Domain\ServiceCatalog\Actions\PublishServicePackageVersion;
use App\Domain\ServiceCatalog\Actions\ReviseServicePackageVersion;
use App\Domain\ServiceCatalog\Models\ServicePackage;
use App\Domain\ServiceCatalog\Models\ServicePackageVersion;
use App\Domain\ServiceCatalog\ServicePackageVersionStatus;
use App\Filament\Admin\Resources\ServicePackages\ServicePackageResource;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Versions of one `ServicePackage` (relationship `versions`), mounted on
 * the package's View page.
 *
 * ---------------------------------------------------------------------------
 * Every mutation routes through the domain Actions
 * ---------------------------------------------------------------------------
 * - Header 'Revisi' action → `Actions\ReviseServicePackageVersion`, which
 *   copies the current published version into a new DRAFT. It is visible
 *   ONLY when a package has something to revise: it already has a published
 *   version AND no open draft (the action itself throws otherwise — the
 *   try/catch below is the race-condition backstop that surfaces that throw
 *   as an honest notification, not a 500).
 * - Per-draft-row 'Terbitkan' action → `Actions\PublishServicePackageVersion`.
 *   Visible only for draft rows. The action refuses to publish a draft with
 *   zero items — that throw is caught and surfaced as a danger notification
 *   too.
 *
 * Both Actions self-audit (`SERVICE_PACKAGE_VERSION_PUBLISHED` /
 * `SERVICE_PACKAGE_VERSION_REVISED`), so nothing here is double-wrapped in
 * `Audit::wrap()`.
 *
 * ---------------------------------------------------------------------------
 * Authorization
 * ---------------------------------------------------------------------------
 * The embedding View page already enforces the resource gate, but this
 * relation manager is itself a Livewire component addressable over the
 * wire, so it carries its own two layers — the same hardening
 * `PackagesRelationManager` documents: `canViewForRecord()` override (the
 * base implementation resolves a policy that does not exist and fails
 * OPEN) and `->authorize(...)` on every action.
 *
 * `isReadOnly()` is overridden to `false` because on the View page Filament
 * hides all relationship-modifying actions by default; this manager's whole
 * purpose is those two lifecycle actions.
 */
final class VersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'versions';

    protected static ?string $title = 'Versi';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::actorMayManage();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->defaultSort('version_number', 'desc')
            ->columns([
                TextColumn::make('version_number')
                    ->label('Versi')
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => $state === ServicePackageVersionStatus::PUBLISHED ? 'success' : 'gray')
                    ->formatStateUsing(fn (string $state): string => $state === ServicePackageVersionStatus::PUBLISHED ? 'Terbit' : 'Draft')
                    ->sortable(),

                TextColumn::make('published_at')
                    ->label('Diterbitkan pada')
                    ->dateTime()
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('items_count')
                    ->label('Jumlah item')
                    ->counts('items'),
            ])
            ->headerActions([
                Action::make('revise')
                    ->label('Revisi')
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->visible(fn (): bool => $this->mayRevise())
                    ->action(function (): void {
                        $actor = app(ActorContext::class);

                        try {
                            (new ReviseServicePackageVersion)(
                                $this->getOwnerRecord(),
                                actorReference: $actor->identityReference ?? 0,
                                actorRole: ServicePackageResource::auditRoleFor($actor),
                                auditSource: AuditSource::Panel,
                            );

                            Notification::make()
                                ->title('Draft revisi dibuat.')
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Revisi gagal dibuat.')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                Action::make('publish')
                    ->label('Terbitkan')
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->visible(fn (ServicePackageVersion $record): bool => $record->isDraft())
                    ->action(function (ServicePackageVersion $record): void {
                        $actor = app(ActorContext::class);

                        try {
                            (new PublishServicePackageVersion)(
                                $record,
                                actorReference: $actor->identityReference ?? 0,
                                actorRole: ServicePackageResource::auditRoleFor($actor),
                                auditSource: AuditSource::Panel,
                            );

                            Notification::make()
                                ->title('Versi diterbitkan.')
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Versi gagal diterbitkan.')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    /**
     * `ReviseServicePackageVersion` is meaningful only when the package has
     * something to revise: a published version exists AND no open draft
     * blocks a new one (the action enforces both itself; hiding the button
     * in the same states keeps the surface honest).
     */
    private function mayRevise(): bool
    {
        /** @var ServicePackage $package */
        $package = $this->getOwnerRecord();

        return $package->currentPublishedVersion() !== null && $package->draftVersion() === null;
    }

    private static function actorMayManage(): bool
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }

        return true;
    }
}

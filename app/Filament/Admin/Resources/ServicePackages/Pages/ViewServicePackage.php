<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServicePackages\Pages;

use App\Domain\ServiceCatalog\Models\ServicePackage;
use App\Domain\ServiceCatalog\ServiceCatalogAuditActions;
use App\Filament\Admin\Resources\ServicePackages\ServicePackageResource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Throwable;

/**
 * View page for `ServicePackageResource` — hosts the two relation managers
 * (Versions, VersionItems) and the resource's ONLY delete surface.
 *
 * ---------------------------------------------------------------------------
 * Honest delete protection
 * ---------------------------------------------------------------------------
 * `ServicePackage::booted()`'s `deleting` guard refuses to delete a package
 * that still owns a published version (the FK cascade would destroy frozen
 * versions and their items without firing a single Eloquent event). The
 * view page checks the same condition up front and refuses with a danger
 * notification instead of letting the exception escape as a 500; the
 * `\Throwable` catch is the race-condition backstop (a version published
 * between the check and the DELETE), matching `EditCemetery`'s own shape.
 *
 * A package with only draft versions may be deleted — the cascade is
 * exactly what the migration's reasoning intends. A SUCCESSFUL delete is a
 * write like any other, so it is wrapped in `Audit::wrap()` recording
 * `SERVICE_PACKAGE_DELETED` (same AC4 contract as the domain actions).
 */
final class ViewServicePackage extends ViewRecord
{
    protected static string $resource = ServicePackageResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->action(function (ServicePackage $record): void {
                    if ($record->currentPublishedVersion() !== null) {
                        Notification::make()
                            ->title('Paket tidak dapat dihapus.')
                            ->body(
                                'Paket ini masih memiliki versi yang diterbitkan. '
                                .'Versi terbit tidak dapat dihapus; nonaktifkan paket sebagai gantinya.'
                            )
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $actor = app(ActorContext::class);

                        Audit::wrap(
                            fn (): bool => $record->delete(),
                            action: ServiceCatalogAuditActions::SERVICE_PACKAGE_DELETED,
                            subject: new AuditSubject(
                                type: 'service_package',
                                id: (string) $record->getKey(),
                            ),
                            outcome: AuditOutcome::Allowed,
                            actorRef: $actor->identityReference,
                            actorRole: ServicePackageResource::auditRoleFor($actor),
                            source: AuditSource::Panel,
                        );
                    } catch (Throwable $exception) {
                        Notification::make()
                            ->title('Paket tidak dapat dihapus.')
                            ->body($exception->getMessage())
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Paket dihapus.')
                        ->success()
                        ->send();

                    $this->redirect(ServicePackageResource::getUrl('index'));
                }),
        ];
    }
}

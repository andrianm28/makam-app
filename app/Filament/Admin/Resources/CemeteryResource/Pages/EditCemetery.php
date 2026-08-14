<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CemeteryResource\Pages;

use App\Domain\CemeteryDirectory\CemeteryAuditActions;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Filament\Admin\Resources\CemeteryResource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Resources\Pages\EditRecord;

/**
 * Edit page for `CemeteryResource` — the `FaqArticles` ground-truth shape.
 *
 * `handleRecordUpdate()` is NOT overridden, for the same reason the create
 * page keeps Filament's default: no update Domain Action exists, the model
 * save is the write path, and `Cemetery::booted()`'s `saving` hook still
 * fires on it. The slug field is disabled on edit (`Schemas\CemeteryForm`),
 * so it is excluded from the dehydrated update payload and cannot be
 * changed here.
 *
 * The audit row is written from the `afterSave()` hook (inside the
 * transaction `EditRecord::save()` opened — verified against the installed
 * v5.7.3 source), with the publication-status transition captured by
 * `beforeSave()` while the record still holds its pre-update values.
 */
final class EditCemetery extends EditRecord
{
    protected static string $resource = CemeteryResource::class;

    private ?string $previousPublicationStatus = null;

    protected function beforeSave(): void
    {
        /** @var Cemetery $record */
        $record = $this->record;

        $this->previousPublicationStatus = $record->publication_status;
    }

    protected function afterSave(): void
    {
        /** @var Cemetery $record */
        $record = $this->record;
        $actor = app(ActorContext::class);

        Audit::record(
            action: CemeteryAuditActions::UPDATED,
            subject: new AuditSubject(type: 'cemetery', id: (string) $record->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actor->identityReference,
            actorRole: CemeteryResource::auditRoleFor($actor),
            source: AuditSource::Panel,
            metadata: [
                'previous_state' => $this->previousPublicationStatus,
                'new_state' => $record->publication_status,
            ],
        );
    }
}

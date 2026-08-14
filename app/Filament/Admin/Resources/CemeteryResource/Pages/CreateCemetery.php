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
use Filament\Resources\Pages\CreateRecord;

/**
 * Create page for `CemeteryResource` — the `FaqArticles` ground-truth shape.
 *
 * Unlike `Pages\CreateFaqArticle`, `handleRecordCreation()` is NOT
 * overridden: no `CreateCemetery` Domain Action exists, so Filament's
 * default `new Cemetery($data); $record->save()` IS the write path, and it
 * still fires `Cemetery::booted()`'s `saving` hook (closed-list assertions)
 * — see `Schemas\CemeteryForm`'s doc block.
 *
 * The audit row is written from the `afterCreate()` hook, which runs inside
 * the transaction `CreateRecord::create()` opened (verified against the
 * installed `filament/filament` v5.7.3 source), so the state change and its
 * audit record commit together (`Audit::record()` doc block's AC4
 * guarantee).
 */
final class CreateCemetery extends CreateRecord
{
    protected static string $resource = CemeteryResource::class;

    protected function afterCreate(): void
    {
        /** @var Cemetery $record */
        $record = $this->record;
        $actor = app(ActorContext::class);

        Audit::record(
            action: CemeteryAuditActions::CREATED,
            subject: new AuditSubject(type: 'cemetery', id: (string) $record->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actor->identityReference,
            actorRole: CemeteryResource::auditRoleFor($actor),
            source: AuditSource::Panel,
            metadata: ['new_state' => $record->publication_status],
        );
    }
}

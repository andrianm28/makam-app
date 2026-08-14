<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceDefinitionResource\Pages;

use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCatalogAuditActions;
use App\Filament\Admin\Resources\ServiceDefinitionResource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Create page for `ServiceDefinitionResource`.
 *
 * `handleRecordCreation()` is overridden (verified against the real
 * installed `Filament\Resources\Pages\CreateRecord::handleRecordCreation
 * (array $data): Model`, v5.7.3) so the row insert and its audit record
 * commit inside ONE transaction via `Audit::wrap()` — the state change and
 * the `SERVICE_DEFINITION_CREATED` audit row can never be separated
 * (`Audit::wrap()`'s AC4 contract). The `reason` field the form collects is
 * read out of `$data` here and passed to the audit call; it is never written
 * to the model.
 *
 * The insert itself is a plain `ServiceDefinition::create()` — there is no
 * `CreateServiceDefinition` Domain Action, and the model's own `booted()`
 * `saving` hook is the module's validation seam (it re-asserts the three
 * closed lists on every write). The write is additionally validated at the
 * form boundary (closed-list selects, `unique` rule on code).
 */
final class CreateServiceDefinition extends CreateRecord
{
    protected static string $resource = ServiceDefinitionResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = app(ActorContext::class);

        return Audit::wrap(
            mutation: fn (): ServiceDefinition => ServiceDefinition::create(Arr::except($data, ['reason'])),
            action: ServiceCatalogAuditActions::SERVICE_DEFINITION_CREATED,
            subject: fn (ServiceDefinition $record): AuditSubject => new AuditSubject('service_definition', $record->id),
            outcome: AuditOutcome::Allowed,
            actorRef: $actor->identityReference,
            actorRole: $actor->roles[0] ?? ($actor->isAuthenticated() ? 'unresolved' : 'system'),
            source: AuditSource::Panel,
            reason: filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
        );
    }
}

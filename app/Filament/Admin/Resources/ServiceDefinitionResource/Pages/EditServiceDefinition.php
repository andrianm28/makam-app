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
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

/**
 * Edit page for `ServiceDefinitionResource`.
 *
 * `handleRecordUpdate()` is overridden (verified against the real installed
 * `Filament\Resources\Pages\EditRecord::handleRecordUpdate(Model $record,
 * array $data): Model`, v5.7.3) so the update and its audit record commit
 * inside ONE transaction via `Audit::wrap()` — the state change and the
 * `SERVICE_DEFINITION_UPDATED` audit row can never be separated. The
 * `reason` field the form collects is read out of `$data` here and passed
 * to the audit call; it is never written to the model.
 *
 * `code` is disabled in the form on edit, so it never reaches `$data` — an
 * edit physically cannot change a registered service's code, and the model's
 * `booted()` `saving` hook re-asserts the closed lists on the resulting
 * write regardless.
 *
 * There is no header `DeleteAction`, deliberately: no Domain Action deletes
 * a `service_definitions` row, and this Resource must never fall back to
 * Filament's default `$record->delete()` for an operation the Domain layer
 * does not itself support (the same discipline `FaqArticles`' Edit page
 * applies).
 */
final class EditServiceDefinition extends EditRecord
{
    protected static string $resource = ServiceDefinitionResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var ServiceDefinition $record */
        $actor = app(ActorContext::class);

        return Audit::wrap(
            mutation: fn (): ServiceDefinition => tap($record)->update(Arr::except($data, ['reason'])),
            action: ServiceCatalogAuditActions::SERVICE_DEFINITION_UPDATED,
            subject: new AuditSubject('service_definition', $record->id),
            outcome: AuditOutcome::Allowed,
            actorRef: $actor->identityReference,
            actorRole: $actor->roles[0] ?? ($actor->isAuthenticated() ? 'unresolved' : 'system'),
            source: AuditSource::Panel,
            reason: filled($data['reason'] ?? null) ? (string) $data['reason'] : null,
        );
    }
}

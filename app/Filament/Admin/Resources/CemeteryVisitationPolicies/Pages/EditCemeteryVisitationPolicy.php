<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CemeteryVisitationPolicies\Pages;

use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Domain\Visitation\VisitationAuditActions;
use App\Filament\Admin\Resources\CemeteryVisitationPolicies\CemeteryVisitationPolicyResource;
use App\Filament\Admin\Resources\CemeteryVisitationPolicies\RelationManagers\BlackoutDatesRelationManager;
use App\Filament\Admin\Resources\CemeteryVisitationPolicies\Schemas\CemeteryVisitationPolicyForm;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Edit page for `CemeteryVisitationPolicyResource` — the single per-
 * cemetery policy (the cemetery Select is disabled here; the row can
 * never be moved onto a second cemetery) plus the
 * `BlackoutDatesRelationManager`. The stored `operating_hours` JSON is
 * expanded into the per-weekday form fields on fill and collapsed back on
 * save, and the update + its `CEMETERY_VISITATION_POLICY_UPDATED` audit
 * record commit together via `Audit::wrap`.
 */
final class EditCemeteryVisitationPolicy extends EditRecord
{
    protected static string $resource = CemeteryVisitationPolicyResource::class;

    public function getRelationManagers(): array
    {
        return [
            BlackoutDatesRelationManager::class,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        return CemeteryVisitationPolicyForm::expandOperatingHours($data);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return CemeteryVisitationPolicyForm::collapseOperatingHours($data);
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var CemeteryVisitationPolicy $record */
        $actor = app(ActorContext::class);

        return Audit::wrap(
            mutation: fn (): CemeteryVisitationPolicy => tap($record)->update($data),
            action: VisitationAuditActions::CEMETERY_VISITATION_POLICY_UPDATED,
            subject: new AuditSubject('cemetery_visitation_policy', (string) $record->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actor->identityReference,
            actorRole: CemeteryVisitationPolicyResource::auditRoleFor($actor),
            source: AuditSource::Panel,
            reason: 'Kebijakan kunjungan diperbarui.',
        );
    }
}

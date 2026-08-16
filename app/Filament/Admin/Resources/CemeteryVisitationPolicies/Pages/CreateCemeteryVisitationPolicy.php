<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CemeteryVisitationPolicies\Pages;

use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Domain\Visitation\VisitationAuditActions;
use App\Filament\Admin\Resources\CemeteryVisitationPolicies\CemeteryVisitationPolicyResource;
use App\Filament\Admin\Resources\CemeteryVisitationPolicies\Schemas\CemeteryVisitationPolicyForm;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Create page for `CemeteryVisitationPolicyResource`. The policy enters
 * the system here — with the cemetery Select restricted to cemeteries
 * that do not have a policy yet (one policy per cemetery, the migration's
 * `cemetery_id` unique constraint backstops the form). The collapsed
 * `operating_hours` shape is built from the per-weekday form fields by
 * `CemeteryVisitationPolicyForm::collapseOperatingHours()`, and the row
 * write + its `CEMETERY_VISITATION_POLICY_UPDATED` audit record commit
 * together via `Audit::wrap` — a policy can never exist without its
 * trail.
 */
final class CreateCemeteryVisitationPolicy extends CreateRecord
{
    protected static string $resource = CemeteryVisitationPolicyResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return CemeteryVisitationPolicyForm::collapseOperatingHours($data);
    }

    protected function handleRecordCreation(array $data): Model
    {
        $actor = app(ActorContext::class);

        return Audit::wrap(
            mutation: fn (): CemeteryVisitationPolicy => CemeteryVisitationPolicy::query()->create($data),
            action: VisitationAuditActions::CEMETERY_VISITATION_POLICY_UPDATED,
            subject: fn (CemeteryVisitationPolicy $policy): AuditSubject => new AuditSubject(
                'cemetery_visitation_policy',
                (string) $policy->getKey(),
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $actor->identityReference,
            actorRole: CemeteryVisitationPolicyResource::auditRoleFor($actor),
            source: AuditSource::Panel,
            reason: 'Kebijakan kunjungan dibuat.',
        );
    }
}

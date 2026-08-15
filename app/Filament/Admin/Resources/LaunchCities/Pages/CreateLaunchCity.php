<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LaunchCities\Pages;

use App\Domain\CemeteryDirectory\LaunchCityAuditActions;
use App\Domain\CemeteryDirectory\Models\LaunchCity;
use App\Filament\Admin\Resources\LaunchCities\LaunchCityResource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Create page for `LaunchCityResource` — the `CemeteryResource` ground-truth
 * shape with one deliberate difference: the task brief requires every write
 * to go through `Audit::wrap`, so `handleRecordCreation()` is overridden
 * (verified against the installed `Filament\Resources\Pages\CreateRecord:
 * :handleRecordCreation(array $data): Model` hook, v5.7.3) to wrap the
 * model save — instead of relying on an `afterCreate()` hook calling
 * `Audit::record()`.
 *
 * The subject id is only known AFTER the save, so the `Audit::wrap`
 * subject is a closure receiving the saved model — the
 * `PackagesRelationManager` create shape.
 *
 * `LaunchCity::booted()`'s `saving` hook (uppercase code assertion) still
 * fires on the wrapped save, so the admin cannot write a code the model
 * would reject.
 */
final class CreateLaunchCity extends CreateRecord
{
    protected static string $resource = LaunchCityResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = app(ActorContext::class);

        return Audit::wrap(
            fn (): LaunchCity => LaunchCity::query()->create($data),
            action: LaunchCityAuditActions::CREATED,
            subject: fn (LaunchCity $saved): AuditSubject => new AuditSubject(
                type: 'launch_city',
                id: (string) $saved->getKey(),
            ),
            outcome: AuditOutcome::Allowed,
            actorRef: $actor->identityReference,
            actorRole: LaunchCityResource::auditRoleFor($actor),
            source: AuditSource::Panel,
            metadata: ['new_state' => (bool) ($data['is_active'] ?? false)],
        );
    }
}

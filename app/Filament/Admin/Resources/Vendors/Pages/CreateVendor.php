<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Vendors\Pages;

use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\VendorAuditActions;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Resources\Pages\CreateRecord;

/**
 * Create page for `VendorResource` — the `CemeteryResource` ground-truth
 * shape.
 *
 * `handleRecordCreation()` is NOT overridden: no `CreateVendor` Domain
 * Action exists, so Filament's default `new Vendor($data); $record->save()`
 * IS the write path.
 *
 * The audit row is written from the `afterCreate()` hook, which runs inside
 * the transaction `CreateRecord::create()` opened (the same verified
 * `CemeteryResource` precedent), so the state change and its audit record
 * commit together.
 */
final class CreateVendor extends CreateRecord
{
    protected static string $resource = VendorResource::class;

    protected function afterCreate(): void
    {
        /** @var Vendor $record */
        $record = $this->record;
        $actor = app(ActorContext::class);

        Audit::record(
            action: VendorAuditActions::VENDOR_CREATED,
            subject: new AuditSubject(type: 'vendor', id: (string) $record->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actor->identityReference,
            actorRole: VendorResource::auditRoleFor($actor),
            source: AuditSource::Panel,
            metadata: ['new_state' => $record->is_active ? 'active' : 'inactive'],
        );
    }
}

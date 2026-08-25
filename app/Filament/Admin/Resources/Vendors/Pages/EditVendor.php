<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Vendors\Pages;

use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorAvailability;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\Models\VendorUser;
use App\Domain\Marketplace\VendorAuditActions;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\QueryException;

/**
 * Edit page for `VendorResource` — the `CemeteryResource` ground-truth
 * shape.
 *
 * `handleRecordUpdate()` is NOT overridden, for the same reason the create
 * page keeps Filament's default: no update Domain Action exists, the model
 * save is the write path. The audit row is written from the `afterSave()`
 * hook, with the `is_active` transition captured by `beforeSave()` while
 * the record still holds its pre-update values.
 *
 * ---------------------------------------------------------------------------
 * Honest delete protection (the Task-7 requirement)
 * ---------------------------------------------------------------------------
 * `getHeaderActions()` exposes a `DeleteAction` whose `->action()` closure
 * refuses the delete up front when the vendor still has `vendor_users`
 * (members) or `vendor_listings` rows — both FKs are `restrictOnDelete`,
 * so a bare `$record->delete()` would throw at the database and surface as
 * a 500. The refusal shows a danger notification and leaves the record in
 * place. The `QueryException` catch is the race-condition backstop (a
 * member/listing inserted between the check and the DELETE).
 *
 * A SUCCESSFUL delete is a write like any other, so it is wrapped in
 * `Audit::wrap()` recording `VendorAuditActions::VENDOR_DELETED` — the row
 * change and its `audit_events` entry commit in the same transaction (AC4),
 * and the same `QueryException` catch still covers the race between the
 * check and the wrapped DELETE.
 */
final class EditVendor extends EditRecord
{
    protected static string $resource = VendorResource::class;

    private ?bool $previousIsActive = null;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->action(function (Vendor $record): void {
                    if ($this->hasDependentRows($record)) {
                        Notification::make()
                            ->title('Vendor tidak dapat dihapus.')
                            ->body(
                                'Vendor masih memiliki anggota/listing. Hapus atau cabut '
                                .'anggota dan listing terlebih dahulu.'
                            )
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $actor = app(ActorContext::class);

                        Audit::wrap(
                            fn (): bool => $record->delete(),
                            action: VendorAuditActions::VENDOR_DELETED,
                            subject: new AuditSubject(
                                type: 'vendor',
                                id: (string) $record->getKey(),
                            ),
                            outcome: AuditOutcome::Allowed,
                            actorRef: $actor->identityReference,
                            actorRole: VendorResource::auditRoleFor($actor),
                            source: AuditSource::Panel,
                        );
                    } catch (QueryException) {
                        Notification::make()
                            ->title('Vendor tidak dapat dihapus.')
                            ->body('Data lain masih terhubung ke vendor ini.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Vendor dihapus.')
                        ->success()
                        ->send();

                    $this->redirect(VendorResource::getUrl('index'));
                }),
        ];
    }

    private function hasDependentRows(Vendor $record): bool
    {
        return VendorUser::query()->where('vendor_id', $record->getKey())->exists()
            || VendorListing::query()->where('vendor_id', $record->getKey())->exists()
            || VendorAvailability::query()->where('vendor_id', $record->getKey())->exists();
    }

    protected function beforeSave(): void
    {
        /** @var Vendor $record */
        $record = $this->record;

        $this->previousIsActive = $record->is_active;
    }

    protected function afterSave(): void
    {
        /** @var Vendor $record */
        $record = $this->record;
        $actor = app(ActorContext::class);

        Audit::record(
            action: VendorAuditActions::VENDOR_UPDATED,
            subject: new AuditSubject(type: 'vendor', id: (string) $record->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actor->identityReference,
            actorRole: VendorResource::auditRoleFor($actor),
            source: AuditSource::Panel,
            metadata: [
                'previous_state' => $this->previousIsActive ? 'active' : 'inactive',
                'new_state' => $record->is_active ? 'active' : 'inactive',
            ],
        );
    }
}

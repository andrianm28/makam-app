<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CemeteryResource\Pages;

use App\Domain\CemeteryDirectory\CemeteryAuditActions;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Filament\Admin\Resources\CemeteryResource;
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
 *
 * ---------------------------------------------------------------------------
 * Honest delete protection (the Task-4 requirement)
 * ---------------------------------------------------------------------------
 * `getHeaderActions()` exposes a `DeleteAction` whose `->action()` closure
 * refuses the delete up front when the cemetery still has `grave_records`
 * rows — `grave_records.cemetery_id` is `restrictOnDelete` (the model has
 * deliberately NO `graveRecords()` relation; the inbound dependency points
 * inward from a module it does not own), so a bare `$record->delete()` would
 * throw at the database and surface as a 500. The refusal shows a danger
 * notification and leaves the record in place. The `QueryException` catch is
 * the race-condition backstop (a grave record inserted between the check
 * and the DELETE), so even that pathological path stays honest.
 */
final class EditCemetery extends EditRecord
{
    protected static string $resource = CemeteryResource::class;

    private ?string $previousPublicationStatus = null;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->action(function (Cemetery $record): void {
                    if ($this->hasGraveRecords($record)) {
                        Notification::make()
                            ->title('Makam tidak dapat dihapus.')
                            ->body(
                                'Makam ini masih memiliki data pemakaman (grave records) yang '
                                .'terhubung. Hapus data pemakaman tersebut terlebih dahulu.'
                            )
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $record->delete();
                    } catch (QueryException) {
                        Notification::make()
                            ->title('Makam tidak dapat dihapus.')
                            ->body('Data lain masih terhubung ke makam ini.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Makam dihapus.')
                        ->success()
                        ->send();

                    $this->redirect(CemeteryResource::getUrl('index'));
                }),
        ];
    }

    private function hasGraveRecords(Cemetery $record): bool
    {
        return GraveRecord::query()->where('cemetery_id', $record->getKey())->exists();
    }

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

<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LaunchCities\Pages;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\LaunchCityAuditActions;
use App\Domain\CemeteryDirectory\Models\LaunchCity;
use App\Filament\Admin\Resources\LaunchCities\LaunchCityResource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;

/**
 * Edit page for `LaunchCityResource` — the `CemeteryResource` ground-truth
 * shape with one deliberate difference: the task brief requires every write
 * to go through `Audit::wrap`, so `handleRecordUpdate()` is overridden
 * (verified against the installed `Filament\Resources\Pages\EditRecord:
 * :handleRecordUpdate(Model $record, array $data): Model` hook, v5.7.3) to
 * wrap the model save — instead of relying on an `afterSave()` hook
 * calling `Audit::record()`.
 *
 * The audit row's metadata carries the `is_active` transition
 * (`previous_state`/`new_state` — both `MetadataAllowlist` keys): the
 * previous value is read off the record before the update runs, and the
 * new value is read from the same `$data` payload the update applies, so
 * the metadata cannot disagree with the committed row even though
 * `Audit::wrap`'s own `record()` runs after the mutation.
 *
 * ---------------------------------------------------------------------------
 * Honest delete protection (the task-brief requirement)
 * ---------------------------------------------------------------------------
 * `getHeaderActions()` exposes a `DeleteAction` whose `->action()` closure
 * refuses the delete up front when any `booking_drafts` row references the
 * city's code — `booking_drafts.city_code` is a plain string column, not a
 * foreign key (`LaunchCityQuery::isKnown()` is the save-time validation),
 * so the check is a deliberate `where('city_code', $code)` existence probe
 * rather than a relation. The refusal shows a danger notification and
 * leaves the record in place. The `QueryException` catch is the
 * race-condition backstop (a draft referencing the code created between
 * the check and the DELETE), so even that pathological path stays honest.
 *
 * A SUCCESSFUL delete is a write like any other, so it is wrapped in
 * `Audit::wrap()` recording `LaunchCityAuditActions::DELETED` — the row
 * change and its `audit_events` entry commit in the same transaction (AC4),
 * and the same `QueryException` catch still covers the race between the
 * `hasBookingDrafts()` check and the wrapped DELETE (the transaction rolls
 * back, nothing is deleted, nothing is audited).
 */
final class EditLaunchCity extends EditRecord
{
    protected static string $resource = LaunchCityResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var LaunchCity $record */
        $previousState = $record->is_active;
        $actor = app(ActorContext::class);

        return Audit::wrap(
            fn (): Model => tap($record)->update($data),
            action: LaunchCityAuditActions::UPDATED,
            subject: new AuditSubject(type: 'launch_city', id: (string) $record->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actor->identityReference,
            actorRole: LaunchCityResource::auditRoleFor($actor),
            source: AuditSource::Panel,
            metadata: [
                'previous_state' => $previousState,
                'new_state' => array_key_exists('is_active', $data)
                    ? (bool) $data['is_active']
                    : $previousState,
            ],
        );
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->action(function (LaunchCity $record): void {
                    if ($this->hasBookingDrafts($record)) {
                        Notification::make()
                            ->title('Kota tidak dapat dihapus.')
                            ->body('Kota masih digunakan oleh pemesanan yang tersimpan.')
                            ->danger()
                            ->send();

                        return;
                    }

                    try {
                        $actor = app(ActorContext::class);

                        Audit::wrap(
                            fn (): bool => $record->delete(),
                            action: LaunchCityAuditActions::DELETED,
                            subject: new AuditSubject(
                                type: 'launch_city',
                                id: (string) $record->getKey(),
                            ),
                            outcome: AuditOutcome::Allowed,
                            actorRef: $actor->identityReference,
                            actorRole: LaunchCityResource::auditRoleFor($actor),
                            source: AuditSource::Panel,
                        );
                    } catch (QueryException) {
                        Notification::make()
                            ->title('Kota tidak dapat dihapus.')
                            ->body('Data lain masih terhubung ke kota ini.')
                            ->danger()
                            ->send();

                        return;
                    }

                    Notification::make()
                        ->title('Kota dihapus.')
                        ->success()
                        ->send();

                    $this->redirect(LaunchCityResource::getUrl('index'));
                }),
        ];
    }

    private function hasBookingDrafts(LaunchCity $record): bool
    {
        return BookingDraft::query()->where('city_code', $record->code)->exists();
    }
}

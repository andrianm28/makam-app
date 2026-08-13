<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorOrders\Pages;

use App\Domain\Marketplace\Actions\UpdateVendorOrderStatus;
use App\Domain\Marketplace\Models\VendorOrder;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Filament\Vendor\Resources\VendorOrders\VendorOrderResource;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The order's work page: full order context, the status/notes form, and the
 * six one-click status transition actions the plan's Task 4.3 asks for.
 *
 * ---------------------------------------------------------------------------
 * No vendor stamping, deliberately
 * ---------------------------------------------------------------------------
 * `Concerns\StampsCurrentVendor` is NOT used here: it exists to decide
 * `vendor_id` server-side when a record is *created*, and this resource has
 * no create page. An existing order's vendor was fixed at checkout, so there
 * is nothing to stamp — and stamping on edit would be the very
 * order-reassignment `VendorOrderForm` refuses to expose.
 *
 * The record was resolved through `VendorOrderResource::getEloquentQuery()`,
 * which is vendor-scoped, so an actor can only ever reach an order already
 * inside their own scope. That resolution IS the authorization for this page
 * and for everything on it: a vendor can reach only its own orders, so no
 * action below needs a second gate for "may this actor touch THIS order".
 * The panel boundary (`VendorPanelAccessPolicy`, role + grant) separately
 * answers "is this actor a vendor at all". The one thing no layer here does
 * is let a write name another vendor: `VendorOrderForm` dehydrates only
 * `status` and `notes`, and `UpdateVendorOrderStatus` never touches
 * `vendor_id`.
 *
 * ---------------------------------------------------------------------------
 * Both write paths go through ONE audited Domain Action
 * ---------------------------------------------------------------------------
 * The form save (`handleRecordUpdate` below) and the six header actions all
 * call `UpdateVendorOrderStatus`. There is deliberately no second, unaudited
 * path: Filament's default `EditRecord::handleRecordUpdate()` writes
 * `$record->update($data)` directly, which would bypass the audit row and the
 * closed-list validation this Action owns. Routing every status write through
 * the Action keeps "who moved which order to what status, when" on
 * `audit_events` no matter which UI did it.
 *
 * The six actions set a status and nothing else — no transition graph is
 * enforced, because `VendorProcessingStatus` defines a closed list and no
 * legal-moves ordering (see `VendorOrderForm` and the Action's doc blocks).
 * Each is `->visible()` only while the order is not already at that status
 * (the "meaningful for this record" guard, orthogonal to authorization —
 * see `FaqArticlesTable`'s doc block on that distinction). The terminal or
 * customer-visible-negative moves (reject, complete, complain) additionally
 * `->requiresConfirmation()`; the forward progressions do not.
 */
final class EditVendorOrder extends EditRecord
{
    protected static string $resource = VendorOrderResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        /** @var VendorOrder $record */
        $record = $this->getRecord();

        return [
            Action::make('accept')
                ->label('Terima pesanan')
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success')
                ->visible(fn (): bool => $record->status !== VendorProcessingStatus::DITERIMA_VENDOR)
                ->action(fn (): mixed => $this->transitionTo(
                    VendorProcessingStatus::DITERIMA_VENDOR,
                    'Pesanan diterima.',
                )),

            Action::make('reject')
                ->label('Tolak pesanan')
                ->icon(Heroicon::OutlinedXCircle)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $record->status !== VendorProcessingStatus::DITOLAK_VENDOR)
                ->action(fn (): mixed => $this->transitionTo(
                    VendorProcessingStatus::DITOLAK_VENDOR,
                    'Pesanan ditolak.',
                )),

            Action::make('process')
                ->label('Mulai proses')
                ->icon(Heroicon::OutlinedWrenchScrewdriver)
                ->color('warning')
                ->visible(fn (): bool => $record->status !== VendorProcessingStatus::DIPROSES)
                ->action(fn (): mixed => $this->transitionTo(
                    VendorProcessingStatus::DIPROSES,
                    'Pesanan sedang diproses.',
                )),

            Action::make('sendSchedule')
                ->label('Tandai dikirim / dijadwalkan')
                ->icon(Heroicon::OutlinedTruck)
                ->color('primary')
                ->visible(fn (): bool => $record->status !== VendorProcessingStatus::DIKIRIM_OR_DIJADWALKAN)
                ->action(fn (): mixed => $this->transitionTo(
                    VendorProcessingStatus::DIKIRIM_OR_DIJADWALKAN,
                    'Pesanan dikirim / dijadwalkan.',
                )),

            Action::make('complete')
                ->label('Tandai selesai')
                ->icon(Heroicon::OutlinedCheckBadge)
                ->color('success')
                ->requiresConfirmation()
                ->visible(fn (): bool => $record->status !== VendorProcessingStatus::SELESAI)
                ->action(fn (): mixed => $this->transitionTo(
                    VendorProcessingStatus::SELESAI,
                    'Pesanan selesai dikerjakan.',
                )),

            Action::make('complain')
                ->label('Tandai komplain')
                ->icon(Heroicon::OutlinedExclamationTriangle)
                ->color('danger')
                ->requiresConfirmation()
                ->visible(fn (): bool => $record->status !== VendorProcessingStatus::KOMPLAIN)
                ->action(fn (): mixed => $this->transitionTo(
                    VendorProcessingStatus::KOMPLAIN,
                    'Pesanan ditandai komplain.',
                )),
        ];
    }

    /**
     * Route the form save through the audited Domain Action — see the class
     * doc block for why the default `$record->update($data)` path is not used.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var VendorOrder $record */
        return (new UpdateVendorOrderStatus)(
            order: $record,
            status: (string) $data['status'],
            actorReference: Auth::id(),
            actorRole: 'vendor',
            notes: filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
        );
    }

    private function transitionTo(string $status, string $notificationTitle): void
    {
        /** @var VendorOrder $record */
        $record = $this->getRecord();

        (new UpdateVendorOrderStatus)(
            order: $record,
            status: $status,
            actorReference: Auth::id(),
            actorRole: 'vendor',
            // Preserve whatever note already exists — a one-click transition
            // must not wipe a note it had no reason to touch.
            notes: $record->notes,
        );

        // The Action re-read under the row lock returns a fresh row; the
        // mounted record is stale until refreshed, and so is the form. Refresh
        // both so the edit screen reflects the transition immediately.
        $record->refresh();
        $this->refreshFormData(['status', 'notes']);

        Notification::make()
            ->title($notificationTitle)
            ->success()
            ->send();
    }
}

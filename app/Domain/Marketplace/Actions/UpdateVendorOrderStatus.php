<?php

declare(strict_types=1);

namespace App\Domain\Marketplace\Actions;

use App\Domain\Marketplace\MarketplaceAuditActions;
use App\Domain\Marketplace\Models\VendorOrder;
use App\Domain\Marketplace\VendorProcessingStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY path a vendor order's `status` (and `notes`) may be written
 * through, from any surface — the edit form's save hook and the six
 * one-click transition actions on `/vendor/orders/{id}/edit` both call this.
 * Mirrors `app/Domain/Faq/Actions/PublishFaqArticle`'s "domain logic lives
 * in Actions, one write path, audited in the same transaction" shape
 * (`app/Domain/README.md`'s binding rule).
 *
 * ---------------------------------------------------------------------------
 * No transition graph, deliberately
 * ---------------------------------------------------------------------------
 * `VendorProcessingStatus` defines a closed list of eight statuses and says
 * nothing about which moves between them are legal. No ordering is invented
 * here: the action accepts any known status as its target, exactly as the
 * closed list permits. A future batch with a real state machine replaces
 * this call with the machine's transition validator — the audit + single-
 * write-path shape below is what survives that change.
 *
 * ---------------------------------------------------------------------------
 * What this does not do
 * ---------------------------------------------------------------------------
 * - It does not touch payment. `VendorProcessingStatus` is fulfilment-only
 *   by design (`DIBAYAR` is not in its list; AC12 forbids treating a paid
 *   order as fulfilment complete), and `vendor_orders` has no `is_paid`
 *   column — payment lives on the financial ledger side.
 * - It does not change `vendor_id`. The order's vendor was fixed by the
 *   customer at checkout; nothing here can reassign it (and no form in the
 *   panel ever submits a `vendor_id` key — see `VendorOrderForm`'s doc block).
 *   Scope is enforced where the record was resolved, per query, by
 *   `VendorOrderResource::getEloquentQuery()`.
 * - It does not re-audit when only `notes` change. `notes` is explicitly a
 *   vendor's internal scratch field (`VendorOrderForm`'s helper text); the
 *   audit row exists to record status movement, so an unchanged status saves
 *   without one.
 *
 * ---------------------------------------------------------------------------
 * Locking
 * ---------------------------------------------------------------------------
 * Re-reads with `lockForUpdate()` before writing, following
 * `PublishFaqArticle`. A multi-grant vendor can legitimately hold the same
 * order open in two tabs; without the row lock two concurrent transitions
 * could interleave into a lost update. The lock serialises them so the last
 * writer wins on a record it re-read under the lock.
 *
 * ---------------------------------------------------------------------------
 * Audited, but not `SensitiveActions`-listed
 * ---------------------------------------------------------------------------
 * See `MarketplaceAuditActions`'s doc block — routine fulfilment-state
 * change, `previous_state`/`new_state` metadata, no mandatory reason.
 */
final readonly class UpdateVendorOrderStatus
{
    /**
     * @param  int|string|null  $actorReference  The actor's identity reference
     *                                           (`ActorContext::$identityReference`), written to `audit_events.actor_ref`.
     *                                           Null only if the write genuinely has no actor (queue/system replay);
     *                                           `$actorRole` is still required even then.
     * @param  string  $actorRole  The actor's role label for the audit row. The
     *                             panel passes `'vendor'`; a non-panel caller should pass its own.
     * @param  string|null  $notes  The `notes` value to write alongside the
     *                              status, exactly as given — `null` clears the column. The edit form
     *                              passes the field's dehydrated value (blank means null); the one-click
     *                              transition actions pass the record's CURRENT notes so a transition
     *                              never wipes an existing note it had no reason to touch.
     */
    public function __invoke(
        VendorOrder $order,
        string $status,
        int|string|null $actorReference = null,
        string $actorRole = 'vendor',
        AuditSource $auditSource = AuditSource::Panel,
        ?string $notes = null,
    ): VendorOrder {
        VendorProcessingStatus::assertKnown($status);

        return DB::transaction(function () use ($order, $status, $actorReference, $actorRole, $auditSource, $notes): VendorOrder {
            /** @var VendorOrder $order */
            $order = VendorOrder::query()->lockForUpdate()->findOrFail($order->id);

            $previousStatus = $order->status;
            $statusChanged = $previousStatus !== $status;

            $order->forceFill([
                'status' => $status,
                'notes' => $notes,
            ])->save();

            if ($statusChanged) {
                Audit::record(
                    action: MarketplaceAuditActions::ORDER_STATUS_CHANGED,
                    subject: new AuditSubject('vendor_order', $order->id, $order->uuid),
                    outcome: AuditOutcome::Allowed,
                    actorRef: $actorReference,
                    actorRole: $actorRole,
                    source: $auditSource,
                    metadata: [
                        'previous_state' => $previousStatus,
                        'new_state' => $status,
                    ],
                );
            }

            return $order;
        });
    }
}

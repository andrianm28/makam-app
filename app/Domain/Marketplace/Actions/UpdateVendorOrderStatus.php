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
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY path a vendor order's `status` (and `notes`) may be written
 * through, from any surface — the edit form's save hook and the six
 * one-click transition actions on `/vendor/pesanan/{id}/edit` both call this.
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
 *
 * ---------------------------------------------------------------------------
 * `vendor_order.decided.v1` — one matrix row, two real outcomes
 * ---------------------------------------------------------------------------
 * "Vendor accepted/rejected" (`docs/contracts/notification-matrix.md`) is
 * ONE row for TWO real outcomes, the same "one event, outcome-as-data" shape
 * `payment.outcome_failed.v1` established — not two event names. Emitted
 * ONLY for the `MENUNGGU_VENDOR` → `DITERIMA_VENDOR`/`DITOLAK_VENDOR`
 * transitions: `VendorProcessingStatus::KNOWN_STATUSES` names eight values
 * and this Action accepts any known status as its target (see "No
 * transition graph, deliberately" above), so `$previousStatus` genuinely
 * needs checking, not just `$status` — the same "reachable from more than
 * one source, only one of them is the real matrix event" reasoning
 * `Listeners\DispatchOrderNotifications`'s `DITOLAK` arm documents (there:
 * matching `to_status`/`from_status` off an outbox event's data; here:
 * `$previousStatus`/`$status` are already local variables, so the check is
 * inline).
 *
 * The idempotency key is `vendor_order_decided:{id}:{outcome}:{audit_id}` —
 * neither bare `{id}` nor `{id}:{outcome}` is enough. Unlike
 * `ApplyPaymentSettlement::transitionToTerminal()`, which guards its own
 * outcome-scoped key by only ever reaching that terminal state once per
 * session, THIS Action has no terminal-state guard at all (see "No
 * transition graph, deliberately" above): a vendor order can legitimately
 * go `MENUNGGU_VENDOR` → `DITOLAK_VENDOR` → `MENUNGGU_VENDOR` (a correction)
 * → `DITERIMA_VENDOR`, which is two real, independent decisions on the same
 * aggregate — `{id}`-only collides them. But outcome-scoping alone
 * (`{id}:{outcome}`) is ALSO insufficient: the same correction can land on
 * the SAME outcome twice (`MENUNGGU_VENDOR` → `DITERIMA_VENDOR` →
 * `MENUNGGU_VENDOR` (correction) → `DITERIMA_VENDOR` again), and this
 * Action, unlike `ApplyPaymentSettlement`, has no redelivery pathway either
 * (it is only ever called synchronously from the panel's save hook or the
 * six one-click actions) — so the key never needed content-determinism
 * across calls, only a guarantee of one row per real decision. Folding in
 * `$audit->id` — a fresh `audit_events` row created on every real
 * transition, just above this call, in the same `if ($statusChanged)` block
 * — gives every real decision a distinct key while the pre-existing
 * `$statusChanged` guard still stops a literal duplicate submit of the
 * exact same request from ever reaching this code at all.
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
                $audit = Audit::record(
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

                if ($previousStatus === VendorProcessingStatus::MENUNGGU_VENDOR
                    && in_array($status, [VendorProcessingStatus::DITERIMA_VENDOR, VendorProcessingStatus::DITOLAK_VENDOR], true)) {
                    Outbox::record(
                        eventName: 'vendor_order.decided.v1',
                        eventVersion: 1,
                        aggregateType: 'vendor_order',
                        aggregateId: $order->getKey(),
                        data: [
                            'vendor_order_id' => $order->getKey(),
                            'outcome' => $status,
                        ],
                        classification: OutboxClassification::Internal,
                        idempotencyKey: "vendor_order_decided:{$order->getKey()}:{$status}:{$audit->id}",
                    );
                }
            }

            return $order;
        });
    }
}

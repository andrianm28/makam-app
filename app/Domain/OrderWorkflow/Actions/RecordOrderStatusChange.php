<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow\Actions;

use App\Domain\OrderWorkflow\Exceptions\OrderAlreadyPaidException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\OrderTransition;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Audit\MetadataAllowlist;
use App\Platform\Audit\SensitiveActions;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Database\QueryException;
use InvalidArgumentException;

/**
 * The ONE writer of `orders.status` and `order_status_events` — Task 2 of
 * `docs/superpowers/plans/2026-08-12-platform-order-orchestration.md`.
 * Every other module that needs to move an order forward calls this
 * Action; nothing else in this codebase writes either the column or the
 * table.
 *
 * ---------------------------------------------------------------------------
 * Sequencing (`task-2-brief.md` Step 3), and why
 * ---------------------------------------------------------------------------
 * 1. Re-read the order with `lockForUpdate()` INSIDE the same transaction
 *    `Audit::wrap()` opens — not the possibly-stale `$order` the caller
 *    passed in. Two concurrent callers racing the same transition each
 *    block on this row lock; the first to commit wins, and the second's
 *    re-read then sees the already-updated status, so its own
 *    `OrderTransition::assertAllowed()` call is what actually rejects it
 *    (`order_status_events_paid_once`, the partial unique index on
 *    `order_status_events`, is the second, database-level backstop for the
 *    `DIBAYAR` case specifically — see the migration's own doc block).
 * 2. `OrderTransition::assertAllowed()` — throws `IllegalOrderTransitionException`
 *    before anything is written.
 * 3. Blank-reason rejection when `$to->requiresReason()` (true only for
 *    `DITOLAK`). Delegates to `Audit::reasonIsBlank()` — the same
 *    Unicode-aware check the audit layer itself uses — rather than
 *    reimplementing it (`task-2-brief.md` ambiguity 2: "do not write your
 *    own blank/empty-string check"). Deliberately NOT done by adding
 *    `ORDER_STATUS_CHANGED` to `SensitiveActions::ACTIONS`: that list makes
 *    a reason mandatory for every occurrence of the action, but only
 *    `DITOLAK` needs one here — the other twelve transitions must keep
 *    working with no reason at all.
 * 4. Insert the `order_status_events` row, then update `orders.status`.
 * 5. Emit `order.status_changed.v1` via the existing `Outbox` — the only
 *    catalogued order event (`docs/contracts/event-catalog.md:20`); no new
 *    event name is invented.
 *
 * The whole sequence runs inside `Audit::wrap()`, which is what actually
 * provides the transaction — AC4's "mutation and its audit record can
 * never be committed separately." If `assertAllowed()` or the blank-reason
 * check throws, the transaction (containing zero writes so far) rolls
 * back and the exception propagates to the caller untouched.
 */
final readonly class RecordOrderStatusChange
{
    public function __invoke(
        Order $order,
        OrderStatus $to,
        string $actorRef,
        string $actorRole,
        ?string $reason = null,
        array $metadata = [],
    ): OrderStatusEvent {
        // Before the transaction opens, and before anything is written.
        // `Audit::record()` runs the same check, but only AFTER the mutation
        // closure has already inserted the event row and moved the status —
        // so relying on it alone would let a rejected key roll back a write
        // that should never have been attempted. `order_status_events.metadata`
        // is a financial table's free-form JSON column, and this Action was
        // the only writer of caller-supplied metadata in the repo subject to
        // no allowlist at all. The list is deliberately narrow; extending it
        // is meant to be a reviewed change — see `MetadataAllowlist`'s own
        // doc block on keeping a KTP number or bank detail from being
        // smuggled in through a casually added key.
        MetadataAllowlist::assertAllowed($metadata);

        try {
            return $this->record($order, $to, $actorRef, $actorRole, $reason, $metadata);
        } catch (QueryException $exception) {
            if (! $this->isDuplicatePaidEvent($exception)) {
                throw $exception;
            }

            // Deliberately not chained as `$previous` — see
            // `OrderAlreadyPaidException`'s doc block: the original message
            // carries the interpolated `reason`/`metadata` bindings.
            throw OrderAlreadyPaidException::forOrder((string) $order->getKey());
        }
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function record(
        Order $order,
        OrderStatus $to,
        string $actorRef,
        string $actorRole,
        ?string $reason,
        array $metadata,
    ): OrderStatusEvent {
        return Audit::wrap(
            mutation: function () use ($order, $to, $actorRef, $actorRole, $reason, $metadata): OrderStatusEvent {
                $current = Order::query()->lockForUpdate()->findOrFail($order->getKey());
                $from = $current->status();

                OrderTransition::assertAllowed($from, $to);

                if ($to->requiresReason() && Audit::reasonIsBlank($reason)) {
                    throw new InvalidArgumentException(
                        "Transitioning an order to [{$to->value}] requires a non-blank reason."
                    );
                }

                $event = OrderStatusEvent::query()->create([
                    'order_id' => $current->getKey(),
                    'from_status' => $from->value,
                    'to_status' => $to->value,
                    'actor_ref' => $actorRef,
                    'actor_role' => $actorRole,
                    'reason' => $reason,
                    'metadata' => $metadata,
                    'occurred_at' => now(),
                ]);

                // `Order::applyStatus()` is the single door left open by the
                // model's write guard — a bare `$current->update([...])` or
                // `$current->save()` throws `OrderIsGuardedException`. See
                // `Order`'s class doc block.
                $current->applyStatus($to);

                // Sync the caller's own instance. `$current` is a SEPARATE
                // object read under `lockForUpdate()`, so without this the
                // caller's `$order` still reports the pre-transition status
                // and the obvious next line —
                // `if ($order->status() === OrderStatus::DIBAYAR)` — silently
                // reads stale state. Every remaining task in this lane calls
                // this Action, `ApplyPaidEffects` among them, so a stale read
                // on the paid path is exactly the failure to prevent here.
                // `PaymentVerification::decide()` sets the same precedent of
                // leaving the caller's instance current.
                if ($order !== $current) {
                    $order->setRawAttributes($current->getAttributes(), true);
                }

                // `event-catalog.md:20` — the only catalogued order event.
                // References only: order id and the two status values, never
                // order content.
                Outbox::record(
                    eventName: 'order.status_changed.v1',
                    eventVersion: 1,
                    aggregateType: 'order',
                    aggregateId: $current->getKey(),
                    data: [
                        'order_id' => $current->getKey(),
                        'from_status' => $from->value,
                        'to_status' => $to->value,
                    ],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "order_status_event:{$event->getKey()}",
                );

                return $event;
            },
            // When the target status is ITSELF a registered sensitive action,
            // record under that name so `Audit::record()`'s AC3 check
            // (`SensitiveActions::requiresReason()`) actually evaluates for it.
            // `DITOLAK` is the only `OrderStatus` value currently on that list,
            // and this Action is the codebase's only order-rejection path — so
            // recording every transition under `ORDER_STATUS_CHANGED` left the
            // platform's own mandatory-reason control with zero producers, and
            // `audit_events WHERE action = 'DITOLAK'` empty even after orders
            // had been rejected. Written against the list rather than against
            // `OrderStatus::DITOLAK` specifically, so that adding a status to
            // `SensitiveActions::ACTIONS` is enough to make the control fire
            // here too.
            action: SensitiveActions::requiresReason($to->value)
                ? $to->value
                : 'ORDER_STATUS_CHANGED',
            subject: fn (OrderStatusEvent $event): AuditSubject => new AuditSubject('order', $event->order_id),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: AuditSource::Api,
            reason: $reason,
            // `AGENTS.md` §Observability: "Preserve trace/request IDs across
            // request, outbox, queue, provider, and notification flows."
            // `Outbox::record()` reads this context itself, so the outbox row
            // already carried a `trace_id` while its paired `audit_events`
            // row carried null — the two could not be joined, which is
            // precisely the correlation break the requirement exists to
            // prevent. Read the same way `GateActivationRecorder` reads it.
            correlationId: app(CorrelationContext::class)->current()?->value,
            // Forwarded so the audit row and the event row carry the SAME
            // reviewed keys. Omitting it left the two records describing the
            // same transition with different content.
            metadata: $metadata,
        );
    }

    /**
     * Same detection style as
     * `App\Platform\Payment\Actions\Concerns\DetectsDuplicatePaymentReversal`
     * and `App\Platform\DocumentVault\Actions\UploadDocument::
     * isDuplicateClientUploadId()`, and deliberately narrow for the reason
     * that trait documents at length: `QueryException`'s message always
     * echoes the INSERT's own column list, so matching a BARE column name
     * would classify a NOT NULL or length violation on this table as a
     * duplicate payment.
     *
     * PostgreSQL names the failing index directly
     * (`order_status_events_paid_once`) and is matched first. SQLite reports
     * the QUALIFIED `table.column` form, which appears only in its
     * constraint description and never in the unqualified INSERT column
     * list. Verified against this repository's SQLite test driver:
     *   - genuine duplicate: "UNIQUE constraint failed:
     *     order_status_events.order_id" — both signals present, matches.
     *   - NOT NULL violation: "NOT NULL constraint failed:
     *     order_status_events.actor_role" — neither "unique" nor the
     *     qualified `order_id` form, so it propagates as the real
     *     `QueryException` rather than being mistranslated into "already
     *     paid".
     */
    private function isDuplicatePaidEvent(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        if (str_contains($message, 'order_status_events_paid_once')) {
            return true;
        }

        return str_contains($message, 'unique')
            && str_contains($message, 'order_status_events.order_id');
    }
}

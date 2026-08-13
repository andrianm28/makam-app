<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Exceptions\IllegalOrderTransitionException;
use App\Domain\OrderWorkflow\Exceptions\OrderAlreadyPaidException;
use App\Domain\OrderWorkflow\Exceptions\OrderIsGuardedException;
use App\Domain\OrderWorkflow\Exceptions\OrderStatusEventIsAppendOnlyException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Platform\Audit\Exceptions\AuditMetadataKeyNotAllowedException;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Correlation\CorrelationId;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\OutboxClassification;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `task-2-brief.md` Step 1 — `RecordOrderStatusChange` is the sole writer
 * of `orders.status` and `order_status_events`. See that Action's own doc
 * block for the sequencing this test suite exercises.
 */
final class RecordOrderStatusChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_legal_transition_is_recorded_with_an_audit_row(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);

        $event = app(RecordOrderStatusChange::class)(
            $order, OrderStatus::DIVERIFIKASI, 'actor:admin-1', 'admin'
        );

        self::assertSame('DIVERIFIKASI', $order->fresh()->status);
        self::assertSame('MASUK', $event->from_status);
        self::assertDatabaseHas('audit_events', [
            'action' => 'ORDER_STATUS_CHANGED',
            'subject_type' => 'order',
            'subject_id' => $order->getKey(),
            'outcome' => 'allowed',
        ]);

        // N-12 ("never invent an event name") has no teeth without this:
        // before it existed, the whole `Outbox::record(...)` call could be
        // deleted, or the event renamed, with the suite still green.
        self::assertDatabaseHas('outbox_events', [
            'event_name' => 'order.status_changed.v1',
            'event_version' => 1,
            'aggregate_type' => 'order',
            'aggregate_id' => $order->getKey(),
            'classification' => OutboxClassification::Internal->value,
            'idempotency_key' => "order_status_event:{$event->getKey()}",
        ]);

        // References only — the payload must never carry order content.
        // Order-insensitive comparison: `payload` is a jsonb column whose key
        // order PostgreSQL serializes in an implementation-defined order (Task
        // 10 surfaced the difference; SQLite preserved insertion order).
        $outbox = OutboxEvent::query()->where('aggregate_id', $order->getKey())->sole();
        $this->assertEqualsCanonicalizing([
            'order_id' => $order->getKey(),
            'from_status' => 'MASUK',
            'to_status' => 'DIVERIFIKASI',
        ], $outbox->payload);
    }

    /**
     * `AGENTS.md` §Observability: "Preserve trace/request IDs across request,
     * outbox, queue, provider, and notification flows." `Outbox::record()`
     * reads `CorrelationContext` itself, so the outbox row always had a
     * `trace_id`; the paired audit row had null, so the two could not be
     * joined at all.
     */
    public function test_the_audit_row_and_the_outbox_row_carry_the_same_correlation_id(): void
    {
        $correlationId = CorrelationId::fromString('01JC0RRELAT10NIDF0RTEST00');
        app(CorrelationContext::class)->set($correlationId);

        $order = $this->makeOrder(OrderStatus::MASUK);

        app(RecordOrderStatusChange::class)(
            $order, OrderStatus::DIVERIFIKASI, 'actor:admin-1', 'admin'
        );

        self::assertDatabaseHas('audit_events', [
            'subject_id' => $order->getKey(),
            'correlation_id' => $correlationId->value,
        ]);
        self::assertDatabaseHas('outbox_events', [
            'aggregate_id' => $order->getKey(),
            'trace_id' => $correlationId->value,
        ]);
    }

    /**
     * `order_status_events.metadata` is caller-supplied JSON on a financial
     * table. `MetadataAllowlist` is the platform's existing control for
     * exactly this — a reviewed key list whose stated purpose is keeping a
     * KTP number or bank detail from being smuggled in through a casually
     * added key. The check runs BEFORE the transaction, so a rejected key
     * never causes a write to be attempted and rolled back.
     */
    public function test_metadata_is_allowlisted_and_reaches_both_the_event_and_the_audit_row(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);

        try {
            app(RecordOrderStatusChange::class)(
                $order, OrderStatus::DIVERIFIKASI, 'actor:admin-1', 'admin', null, ['ktp_number' => '3171...']
            );
            self::fail('Expected AuditMetadataKeyNotAllowedException');
        } catch (AuditMetadataKeyNotAllowedException) {
            // expected
        }

        self::assertSame('MASUK', $order->fresh()->status);
        self::assertSame(0, OrderStatusEvent::query()->where('order_id', $order->getKey())->count());

        $event = app(RecordOrderStatusChange::class)(
            $order, OrderStatus::DIVERIFIKASI, 'actor:admin-1', 'admin', null, ['note' => 'Verified by phone.']
        );

        self::assertSame(['note' => 'Verified by phone.'], $event->metadata);

        $audit = DB::table('audit_events')->where('subject_id', $order->getKey())->first();
        self::assertNotNull($audit);
        self::assertSame(['note' => 'Verified by phone.'], json_decode((string) $audit->metadata, true));
    }

    /**
     * The Action re-reads the row under `lockForUpdate()` into a separate
     * instance, so without an explicit sync the object the caller handed in
     * still reports the OLD status on return. Asserted WITHOUT `fresh()` on
     * purpose — `fresh()` would hide exactly the bug this covers.
     */
    public function test_the_callers_own_order_instance_reflects_the_new_status_on_return(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);

        app(RecordOrderStatusChange::class)(
            $order, OrderStatus::DIVERIFIKASI, 'actor:admin-1', 'admin'
        );

        self::assertSame('DIVERIFIKASI', $order->status);
        self::assertSame(OrderStatus::DIVERIFIKASI, $order->status());
        // Synced as persisted state, not as a pending change.
        self::assertFalse($order->isDirty());
    }

    public function test_an_illegal_transition_throws_and_writes_nothing(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);

        try {
            app(RecordOrderStatusChange::class)($order, OrderStatus::DIBAYAR, 'actor:admin-1', 'admin');
            self::fail('Expected IllegalOrderTransitionException');
        } catch (IllegalOrderTransitionException) {
            // expected
        }

        self::assertSame('MASUK', $order->fresh()->status);
        self::assertSame(0, OrderStatusEvent::query()->where('order_id', $order->getKey())->count());
    }

    /**
     * `DITOLAK` is on `SensitiveActions::ACTIONS`, and this Action is the
     * codebase's only order-rejection path. Recording a rejection under
     * `ORDER_STATUS_CHANGED` left that platform control with no producer at
     * all: `Audit::record()`'s mandatory-reason check never evaluated for it,
     * and `audit_events WHERE action = 'DITOLAK'` stayed empty even after
     * orders had been rejected.
     */
    public function test_an_order_rejection_is_audited_under_the_sensitive_action_name(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);

        app(RecordOrderStatusChange::class)(
            $order, OrderStatus::DITOLAK, 'actor:admin-1', 'admin', 'Berkas kematian tidak lengkap.'
        );

        self::assertDatabaseHas('audit_events', [
            'action' => 'DITOLAK',
            'subject_type' => 'order',
            'subject_id' => $order->getKey(),
            'outcome' => 'allowed',
        ]);
        self::assertDatabaseMissing('audit_events', ['action' => 'ORDER_STATUS_CHANGED']);
    }

    /**
     * The positive counterpart to
     * `test_rejection_without_a_reason_is_refused`. Without it, replacing
     * `$to->requiresReason() && Audit::reasonIsBlank($reason)` with a bare
     * `$to->requiresReason()` — i.e. dropping the blankness check entirely
     * and making order rejection impossible — left the whole suite green.
     */
    public function test_a_rejection_with_a_reason_is_recorded(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);

        $event = app(RecordOrderStatusChange::class)(
            $order, OrderStatus::DITOLAK, 'actor:admin-1', 'admin', 'Berkas kematian tidak lengkap.'
        );

        self::assertSame('DITOLAK', $order->fresh()->status);
        self::assertSame('DITOLAK', $event->to_status);
        self::assertSame('Berkas kematian tidak lengkap.', $event->reason);
        self::assertDatabaseHas('audit_events', [
            'action' => 'DITOLAK',
            'reason' => 'Berkas kematian tidak lengkap.',
        ]);
    }

    public function test_rejection_without_a_reason_is_refused(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);

        try {
            app(RecordOrderStatusChange::class)($order, OrderStatus::DITOLAK, 'actor:admin-1', 'admin');
            self::fail('Expected InvalidArgumentException');
        } catch (InvalidArgumentException) {
            // expected
        }

        // "Writes nothing" across all four tables the Action touches, not
        // just the throw: the check runs before any write, inside the
        // transaction, so a rollback must leave no trace anywhere.
        self::assertSame('MASUK', $order->fresh()->status);
        self::assertSame(0, OrderStatusEvent::query()->where('order_id', $order->getKey())->count());
        self::assertDatabaseMissing('audit_events', ['subject_id' => $order->getKey()]);
        self::assertDatabaseMissing('outbox_events', ['aggregate_id' => $order->getKey()]);
    }

    /**
     * The model-level half of the exactly-once guarantee.
     * `order_status_events_paid_once` is an index on `order_status_events`,
     * so it never sees a direct write to `orders.status` — without this
     * guard, `$order->update(['status' => 'DIBAYAR'])` reaches paid with no
     * event row, no audit row, and no outbox row.
     */
    public function test_orders_status_cannot_be_written_outside_the_action(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);

        try {
            $order->update(['status' => OrderStatus::DIBAYAR->value]);
            self::fail('Expected OrderIsGuardedException from Order::update()');
        } catch (OrderIsGuardedException) {
            // expected
        }

        // The other door onto the same column: direct assignment + save(),
        // which routes through performUpdate() rather than update().
        try {
            $order->status = OrderStatus::DIBAYAR->value;
            $order->save();
            self::fail('Expected OrderIsGuardedException from Order::save()');
        } catch (OrderIsGuardedException) {
            // expected
        }

        self::assertSame('MASUK', $order->fresh()->status);
        self::assertSame(0, OrderStatusEvent::query()->where('order_id', $order->getKey())->count());
    }

    /**
     * `Order::applyStatus()` is public, so the write guard's one open door
     * cannot be closed by visibility the way `Document`'s private writers
     * are. It is closed by a token instead: the persisted
     * `order_status_events` row that records the move. Three ways of NOT
     * holding one, all of which would otherwise reach a status with no
     * event, audit or outbox row.
     */
    public function test_a_status_write_requires_the_persisted_event_that_records_it(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);
        $otherOrder = $this->makeOrder(OrderStatus::MASUK);

        // 1. An unsaved event is not proof of anything — this is the shape
        //    the old bare-enum signature made trivially available.
        try {
            $order->applyStatus(new OrderStatusEvent([
                'order_id' => $order->getKey(),
                'to_status' => OrderStatus::DIBAYAR->value,
            ]));
            self::fail('Expected OrderIsGuardedException from applyStatus() with an unsaved event');
        } catch (OrderIsGuardedException) {
            // expected
        }

        // 2. A genuinely persisted event belonging to a DIFFERENT order.
        $foreignEvent = app(RecordOrderStatusChange::class)(
            $otherOrder, OrderStatus::DIVERIFIKASI, 'actor:admin-1', 'admin'
        );

        try {
            $order->applyStatus($foreignEvent);
            self::fail('Expected OrderIsGuardedException from applyStatus() with another order\'s event');
        } catch (OrderIsGuardedException) {
            // expected
        }

        // 3. This order's own persisted event, reassigned in memory to name
        //    a richer target. `OrderStatusEvent` is append-only so this can
        //    never be saved — but it can be passed here, and only what the
        //    ROW says may authorize the write.
        $ownEvent = app(RecordOrderStatusChange::class)(
            $order, OrderStatus::DIVERIFIKASI, 'actor:admin-1', 'admin'
        );
        $ownEvent->to_status = OrderStatus::DIBAYAR->value;

        try {
            $order->applyStatus($ownEvent);
            self::fail('Expected OrderIsGuardedException from applyStatus() with an in-memory to_status');
        } catch (OrderIsGuardedException) {
            // expected
        }

        // Only the one legitimate transition in step 3 moved the column.
        self::assertSame('DIVERIFIKASI', $order->fresh()->status);
        self::assertSame(0, OrderStatusEvent::query()
            ->where('order_id', $order->getKey())
            ->where('to_status', OrderStatus::DIBAYAR->value)
            ->count());
    }

    public function test_a_recorded_status_event_cannot_be_revised_or_deleted(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);

        $event = app(RecordOrderStatusChange::class)(
            $order, OrderStatus::DIVERIFIKASI, 'actor:admin-1', 'admin'
        );

        try {
            $event->update(['reason' => 'rewritten']);
            self::fail('Expected OrderStatusEventIsAppendOnlyException from update()');
        } catch (OrderStatusEventIsAppendOnlyException) {
            // expected
        }

        try {
            $event->delete();
            self::fail('Expected OrderStatusEventIsAppendOnlyException from delete()');
        } catch (OrderStatusEventIsAppendOnlyException) {
            // expected
        }

        self::assertSame(1, OrderStatusEvent::query()->where('order_id', $order->getKey())->count());
        self::assertNull($event->fresh()->reason);
    }

    /**
     * The Action's own encounter with `order_status_events_paid_once`.
     *
     * A paid event row is planted directly WITHOUT moving `orders.status`,
     * which is the state a lost race leaves behind: the in-memory
     * `assertAllowed()` check still sees `MENUNGGU_PEMBAYARAN` and passes, so
     * the partial unique index — not the state graph — is what refuses the
     * insert. That makes the translation path reachable deterministically on
     * a single SQLite connection.
     *
     * Two things are asserted, and the second is the `AGENTS.md`
     * §Observability one: the raw `QueryException` interpolates the INSERT's
     * bindings (which include `reason` and `metadata`) into its message, so
     * the translated error must not carry them.
     */
    public function test_a_paid_index_violation_reaches_the_caller_as_a_domain_error_without_the_bindings(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);

        OrderStatusEvent::query()->create([
            'order_id' => $order->getKey(),
            'from_status' => 'MENUNGGU_PEMBAYARAN',
            'to_status' => 'DIBAYAR',
            'actor_ref' => 'actor:system',
            'actor_role' => 'system',
            'occurred_at' => now(),
        ]);

        try {
            app(RecordOrderStatusChange::class)(
                $order,
                OrderStatus::DIBAYAR,
                'actor:system',
                'system',
                'REASON-TOKEN-MUST-NOT-BE-LOGGED',
                ['note' => 'METADATA-TOKEN-MUST-NOT-BE-LOGGED'],
            );
            self::fail('Expected OrderAlreadyPaidException');
        } catch (OrderAlreadyPaidException $exception) {
            self::assertStringContainsString((string) $order->getKey(), $exception->getMessage());
            self::assertStringNotContainsString('REASON-TOKEN-MUST-NOT-BE-LOGGED', $exception->getMessage());
            self::assertStringNotContainsString('METADATA-TOKEN-MUST-NOT-BE-LOGGED', $exception->getMessage());
            // Chaining the original would put the interpolated bindings back
            // into the logged exception chain.
            self::assertNull($exception->getPrevious());
        }

        self::assertSame('MENUNGGU_PEMBAYARAN', $order->fresh()->status);
    }

    /**
     * `order_status_events.order_id` is `restrictOnDelete()`. Deleting the
     * order would otherwise destroy its financial history AND release
     * `order_status_events_paid_once` for that `order_id`, making an
     * already-paid order payable again. Asserted at the DATABASE layer
     * (`DB::table()`, which bypasses `Order::delete()`'s model guard) because
     * the FK is the backstop for every path that does not go through the
     * model.
     */
    public function test_an_order_with_status_history_cannot_be_deleted(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);

        app(RecordOrderStatusChange::class)(
            $order, OrderStatus::DIVERIFIKASI, 'actor:admin-1', 'admin'
        );

        try {
            // Nested transaction = a SAVEPOINT. PostgreSQL aborts the WHOLE
            // transaction on the first failed statement (25P02), so the
            // `orders` DELETE must fail inside its own savepoint or the
            // verification queries below can never run (Task 10). SQLite lets
            // the transaction limp on, which is why this only surfaced on PG.
            DB::transaction(function () use ($order) {
                DB::table('orders')->where('id', $order->getKey())->delete();
            });
            self::fail('Expected the order_status_events FK to refuse the delete');
        } catch (QueryException) {
            // expected
        }

        self::assertSame(1, OrderStatusEvent::query()->where('order_id', $order->getKey())->count());
        self::assertDatabaseHas('orders', ['id' => $order->getKey()]);
    }

    public function test_at_most_one_paid_event_can_exist_per_order(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);

        app(RecordOrderStatusChange::class)($order, OrderStatus::DIBAYAR, 'actor:system', 'system');

        $this->expectException(QueryException::class);

        // Force a second paid row past the in-memory guard to prove the DATABASE
        // rejects it — application logic is not the thing under test here.
        OrderStatusEvent::query()->create([
            'order_id' => $order->getKey(),
            'from_status' => 'MENUNGGU_PEMBAYARAN',
            'to_status' => 'DIBAYAR',
            'actor_ref' => 'actor:system',
            'actor_role' => 'system',
            'occurred_at' => now(),
        ]);
    }

    /**
     * MOVED, not deleted — Task 10 fix. This test's body drives a SECOND
     * independent database session (`pgsql_race`), so its fixture must be
     * COMMITTED before that session queries it. `RefreshDatabase`'s outer
     * transaction makes that impossible from inside this class (the second
     * connection never sees the uncommitted fixture, and the body had never
     * executed on any driver because of it). The test now lives in
     * `tests/Feature/OrderWorkflow/RecordOrderStatusChangeTwoConnectionTest.php`,
     * which owns its own `migrate:fresh` and runs the two sessions against
     * committed state. The SQLite skip guard lives there too, before the
     * migration, so this lane's hermetic suite pays nothing for it.
     */
    public function test_a_second_paid_transition_is_refused_after_the_first_has_committed(): void
    {
        $this->markTestSkipped('Moved to RecordOrderStatusChangeTwoConnectionTest — Task 10');
    }

    /**
     * `product_type` was the placeholder literal `'funeral_at_need'` when
     * this suite was written, because `ProductType` did not exist yet and
     * the column was an unconstrained string. Task 3 added the enum and,
     * per the `orders` migration's own instruction, the PostgreSQL
     * `orders_product_type_check` that pins the column to it — under which
     * the old literal is not a legal value. Changed to a real
     * `ProductType` case so this suite keeps passing on the PostgreSQL run
     * Task 10 owns; on SQLite, where the CHECK is not created, the change
     * is inert.
     */
    private function makeOrder(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
        ]);
    }
}

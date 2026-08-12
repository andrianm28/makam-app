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
        $outbox = OutboxEvent::query()->where('aggregate_id', $order->getKey())->sole();
        self::assertSame([
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
            DB::table('orders')->where('id', $order->getKey())->delete();
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
     * NOT a concurrency test — renamed in fix round 1 because the previous
     * name and doc block both overclaimed what the body proves.
     *
     * What this actually covers: SEQUENTIAL re-read semantics. The two calls
     * run one after the other in a `foreach`, so the first `Audit::wrap()`
     * transaction runs `BEGIN`→`COMMIT` to completion before the second call
     * starts. The row lock is therefore never contended and no interleaving
     * occurs. What is asserted is that the second call's `lockForUpdate()`
     * re-read observes the first call's ALREADY-COMMITTED status, so its own
     * `OrderTransition::assertAllowed()` refuses it and exactly one `DIBAYAR`
     * event exists. That is a real property and the assertions would go red
     * if the re-check broke — it is simply not the race it used to claim.
     *
     * ---------------------------------------------------------------------
     * Deferred to Task 10, and one known blocker Task 10 inherits
     * ---------------------------------------------------------------------
     * Genuine concurrent-race verification — two sessions both passing
     * `assertAllowed()` before either commits, with only
     * `order_status_events_paid_once` between them — needs parallel
     * connections driven from separate processes. That is out of scope here
     * and is not exercisable at all on this repository's hermetic
     * single-connection in-memory SQLite suite, where `lockForUpdate()` is
     * additionally a no-op (Laravel's `SQLiteGrammar::compileLock()` returns
     * an empty string). Task 10 owns real PostgreSQL 18 verification and is
     * the correct home for a genuine race test.
     *
     * KNOWN BLOCKER, recorded so Task 10 inherits an accurate description
     * rather than rediscovering it: the body below has never been executed
     * on any driver. `RefreshDatabase` wraps the whole test in an
     * uncommitted transaction on the default connection, so the `makeOrder()`
     * fixture is invisible to the separate `pgsql_race` backend session and
     * `findOrFail()` on that connection will raise `ModelNotFoundException`
     * — which the `catch` below does not cover. Task 10 must take this test
     * outside the outer transaction (`DatabaseMigrations`, or an empty
     * `connectionsToTransact()` with explicit cleanup) before the
     * two-connection body can run at all. The `pgsql`-only skip guard is
     * left exactly as it was: it skips on the ABSENCE of `pgsql`, so it
     * cannot silently swallow a run on PostgreSQL — on that driver the body
     * executes and the blocker above surfaces as a visible error.
     */
    public function test_a_second_paid_transition_is_refused_after_the_first_has_committed(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Sequential cross-connection re-read is only meaningful on PostgreSQL; Task 10 owns the real race harness');
        }

        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);

        // A second logical connection against the same PostgreSQL database —
        // a real, independent backend session, not a clone of the first.
        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);

        $originalDefault = config('database.default');
        $outcomes = [];

        try {
            foreach (['pgsql', 'pgsql_race'] as $connectionName) {
                DB::setDefaultConnection($connectionName);

                try {
                    app(RecordOrderStatusChange::class)(
                        Order::query()->findOrFail($order->getKey()),
                        OrderStatus::DIBAYAR,
                        'actor:system',
                        'system',
                    );
                    $outcomes[] = 'ok';
                } catch (IllegalOrderTransitionException) {
                    $outcomes[] = 'blocked';
                }
            }
        } finally {
            DB::setDefaultConnection($originalDefault);
            DB::purge('pgsql_race');
        }

        self::assertSame(1, collect($outcomes)->filter(fn ($o) => $o === 'ok')->count());
        self::assertSame(1, OrderStatusEvent::query()
            ->where('order_id', $order->getKey())->where('to_status', 'DIBAYAR')->count());
    }

    private function makeOrder(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => 'funeral_at_need',
            'status' => $status->value,
        ]);
    }
}

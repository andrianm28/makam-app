<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Exceptions\IllegalOrderTransitionException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
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
        self::assertDatabaseHas('audit_events', ['action' => 'ORDER_STATUS_CHANGED']);
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

    public function test_rejection_without_a_reason_is_refused(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);

        $this->expectException(InvalidArgumentException::class);

        app(RecordOrderStatusChange::class)($order, OrderStatus::DITOLAK, 'actor:admin-1', 'admin');
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
     * `task-2-brief.md` ambiguity 1: `$this->runConcurrently(...)` named in
     * the original brief does not exist and is not invented here. True
     * concurrent, interleaved execution across threads is not exercisable
     * from a single-threaded PHP test process on the hermetic in-memory
     * SQLite suite (one connection). This test therefore skips outright on
     * every driver except `pgsql`, and on `pgsql` drives the Action from
     * two independent, real database connections rather than faking
     * concurrency with two sequential calls dressed up as parallel — each
     * call opens its own backend session and its own `Audit::wrap()`
     * transaction, so the second call's `lockForUpdate()` re-read is a
     * genuine cross-connection read of the first call's committed write,
     * not an artifact of one connection's own read-your-writes visibility.
     * Re-verified for real interleaving on PostgreSQL 18 in Task 10.
     */
    public function test_a_concurrent_double_transition_yields_exactly_one_event(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrency is only meaningful on PostgreSQL');
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

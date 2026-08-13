<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Exceptions\IllegalOrderTransitionException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The two-connection paid-transition test, OUTSIDE the `RefreshDatabase`
 * outer transaction — Task 10's fix for the blocker its own doc block
 * predicted.
 *
 * Why this class exists at all, and why it does NOT use `RefreshDatabase`:
 * `RefreshDatabase` wraps every test in an uncommitted transaction on the
 * default connection, so a fixture created by the test is invisible to a
 * SECOND database session. This test exists precisely to drive a second
 * logical session (`pgsql_race`), which means its fixture must be
 * COMMITTED before the second connection queries it. So the test owns its
 * schema lifecycle: `migrate:fresh` before AND after the body.
 * `RecordOrderStatusChangeTest` keeps the transactional body of every other
 * assertion; this one body cannot live inside a transaction.
 *
 * The trailing `migrate:fresh` is LOAD-BEARING, not a nicety. The two
 * sessions COMMIT real rows (that is the point), and without the wipe those
 * rows would leak into every later `RefreshDatabase` test class in the same
 * process (they begin a transaction, they do not re-migrate, so they see the
 * committed residue — verified as an actual in-suite failure). A trait that
 * rolls back (`DatabaseMigrations`) cannot be used here: its teardown
 * `migrate:rollback` executes sibling migrations' `down()` methods, one of
 * which (`2026_08_09_100010`) calls `dropForeign` by name — an operation
 * `SQLiteGrammar` refuses. `migrate:fresh` drops tables directly and never
 * runs a `down()`, so it is the portable wipe; and because the body is
 * guarded to pgsql only, on SQLite neither `migrate:fresh` ever executes.
 *
 * This is NOT a concurrency test, and the name says what the body proves:
 * SEQUENTIAL re-read semantics across two independent backend sessions. The
 * two calls run one after the other, so the first `Audit::wrap()` transaction
 * runs `BEGIN`→`COMMIT` to completion before the second call starts; the row
 * lock is never contended. What is asserted is that the second session's
 * `lockForUpdate()` re-read observes the first session's ALREADY-COMMITTED
 * status, so its own `OrderTransition::assertAllowed()` refuses it and
 * exactly one `DIBAYAR` event exists. A genuine two-writer race — both
 * sessions passing `assertAllowed()` before either commits, with only
 * `order_status_events_paid_once` between them — is still not exercised
 * here; that needs true parallel drivers and is out of scope on the 2/4
 * host, as recorded in the plan's Task 10.
 *
 * The `pgsql`-only guard skips on the ABSENCE of pgsql and is deliberately
 * placed BEFORE the migration, so the SQLite run costs nothing: on that
 * driver the body (and the `migrate:fresh`) never executes.
 */
final class RecordOrderStatusChangeTwoConnectionTest extends TestCase
{
    public function test_a_second_paid_transition_is_refused_after_the_first_has_committed(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Sequential cross-connection re-read is only meaningful on PostgreSQL');
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

        // Wipe the committed rows so later `RefreshDatabase` test classes in
        // this same process start from an empty, migrated schema (they begin a
        // transaction, they do not re-migrate — see the class doc block).
        Artisan::call('migrate:fresh');
    }

    private function makeOrder(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
        ]);
    }
}

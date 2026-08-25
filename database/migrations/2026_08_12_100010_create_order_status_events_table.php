<?php

declare(strict_types=1);

use App\Domain\OrderWorkflow\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `order_status_events` — Task 2 of the `platform-order-orchestration` plan.
 * One row per commercial status transition (`OrderStatus`). Written ONLY by
 * `App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange` — see that
 * class's own doc block for why this is the sole writer.
 *
 * `from_status` is nullable — the very first event on an order (its arrival
 * at `MASUK`) has no predecessor.
 *
 * ---------------------------------------------------------------------------
 * `order_status_events_paid_once` — the load-bearing invariant of this
 * whole lane
 * ---------------------------------------------------------------------------
 * "Ratified design (Q3)" (`task-2-brief.md`): a PARTIAL unique index on
 * `order_id` WHERE `to_status = 'DIBAYAR'` is the single mechanism that
 * makes "paid at most once per order" a database guarantee rather than an
 * application-level convention that a race condition (two concurrent
 * `DIBAYAR` transitions both passing the in-memory check before either
 * commits) could silently violate.
 *
 * Written with `DB::statement()`, unguarded by driver, because Laravel's
 * schema builder has no portable partial-index API and both PostgreSQL and
 * SQLite accept the identical `CREATE UNIQUE INDEX ... WHERE ...` syntax —
 * verified by this migration running clean against the hermetic SQLite
 * suite. This is deliberately NOT inside the `pgsql`-only guard below: a
 * constraint that exists in production but not in the test suite is
 * exactly the failure mode this index exists to prevent (see
 * `tests/Feature/OrderWorkflow/RecordOrderStatusChangeTest.php::
 * test_at_most_one_paid_event_can_exist_per_order`, and the mutation-check
 * evidence in `task-2-report.md`).
 *
 * Postgres CHECK constraints on `from_status`/`to_status` pin both to the
 * 13 known `OrderStatus` values, guarded to `pgsql` for the same reason as
 * `orders.status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_status_events', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // `restrictOnDelete()`, NOT `cascadeOnDelete()`. This is the
            // financial and audit history of an order's money movements, and
            // a cascade would let one `DELETE FROM orders` destroy the
            // evidence AND release `order_status_events_paid_once` for that
            // `order_id` — silently making a once-paid order payable again.
            // The `audit_events` rows have no FK and would survive, leaving a
            // trail pointing at a subject whose own status history no longer
            // exists. Every comparable audit-trail table in this repository
            // chose the same way: `document_access_events`, `document_scans`,
            // `signed_url_grants`, `notification_deliveries`,
            // `notification_recipients`, `payment_verifications`. An order
            // with status history is simply not deletable; a future retention
            // policy must delete the events explicitly and audibly rather
            // than as a side effect. `Order::delete()` refuses at the model
            // layer for the same reason.
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();

            // Nullable: the initial MASUK event has no predecessor.
            $table->string('from_status', 64)->nullable();
            $table->string('to_status', 64);

            $table->string('actor_ref')->nullable();
            $table->string('actor_role');

            $table->text('reason')->nullable();
            $table->json('metadata')->default('{}');

            $table->timestamp('occurred_at');

            $table->index(['order_id', 'occurred_at']);
        });

        // The exactly-once index — see class doc block. Both PostgreSQL and
        // SQLite support partial indexes with this exact syntax, so this is
        // written once and NOT guarded by driver.
        DB::statement(
            'CREATE UNIQUE INDEX order_status_events_paid_once '.
            'ON order_status_events (order_id) '.
            "WHERE to_status = 'DIBAYAR'"
        );

        // SQLite cannot ADD CONSTRAINT. CI and every real environment run
        // Postgres — same guard as `orders.status`.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $statuses = implode("', '", array_map(
                static fn (OrderStatus $status): string => $status->value,
                OrderStatus::cases(),
            ));

            DB::statement(
                'ALTER TABLE order_status_events ADD CONSTRAINT order_status_events_from_status_check '.
                "CHECK (from_status IS NULL OR from_status IN ('{$statuses}'))"
            );

            DB::statement(
                'ALTER TABLE order_status_events ADD CONSTRAINT order_status_events_to_status_check '.
                "CHECK (to_status IN ('{$statuses}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_events');
    }
};

<?php

declare(strict_types=1);

use App\Domain\OrderWorkflow\ProductType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 3 of the `platform-order-orchestration` plan. Two additions to
 * `orders`, both of which Task 2's own migration explicitly left for the
 * task that would need them.
 *
 * ---------------------------------------------------------------------------
 * 1. `idempotency_key` — the database half of "never a second order"
 * ---------------------------------------------------------------------------
 * The plan's Task 3 requires submission to be idempotent on a caller-supplied
 * key: resubmitting returns the SAME order, satisfying design-system §6.6
 * ("Duplicate / retry-safe … Idempotent confirmation, no double charge").
 *
 * The uniqueness is a DATABASE constraint, not a read-then-write check in
 * PHP. `App\Domain\OrderWorkflow\Actions\SubmitBookingDraft` does have a
 * read-then-write fast path, but that is a convenience: on its own it is a
 * check-then-act race, and the thing being raced here is the creation of a
 * duplicate commercial ORDER. The index is what actually decides; the Action
 * translates the losing insert back into the incumbent order.
 *
 * Shape copied from `documents.client_upload_id`
 * (`2026_08_09_100000_create_documents_table.php`), the repository's existing
 * "client-supplied retry token on the aggregate's own table" precedent:
 * a nullable column plus a PARTIAL unique index over the non-null rows,
 * written with `DB::statement()` because Laravel's schema builder has no
 * portable partial-index API. Nullable, because not every order in this
 * codebase's future arrives through public submission — an admin-created or
 * migrated order legitimately has no client retry token, and a nullable
 * UNIQUE column would have been a subtly different (and driver-dependent)
 * rule. Both PostgreSQL and SQLite accept this exact syntax; the same
 * unguarded-by-driver reasoning as `order_status_events_paid_once`, and for
 * the same reason — a constraint that exists in production but not in the
 * test suite is precisely the failure mode it exists to prevent.
 *
 * NOT reused: `booking_drafts.last_idempotency_key`. That column is the
 * WITHIN-draft step-save replay guard (one mutable column, no uniqueness at
 * all), which is the right shape for "did this same step-save already
 * apply?" and the wrong shape for "does an order for this key already
 * exist?" — the latter must be enforceable across rows.
 *
 * ---------------------------------------------------------------------------
 * 2. The `product_type` CHECK, now that `ProductType` exists
 * ---------------------------------------------------------------------------
 * `2026_08_12_100000_create_orders_table.php` said, in its own doc block:
 * "`product_type` is a plain string column here. `App\Domain\OrderWorkflow\
 * ProductType` … is built by a later task in this lane — this migration does
 * not invent it, matching this repo's convention of not gold-plating a
 * schema ahead of the enum that will constrain it (see
 * `payment_verifications.status`, which got its CHECK only once
 * `PaymentVerificationStatus` existed)." This is that later task, so the
 * CHECK lands here, guarded to `pgsql` exactly as that migration's own
 * `orders_status_check` is.
 *
 * KNOWN LIMITATION, stated rather than assumed away: because the guard is
 * `pgsql`-only and this repository's test suite runs SQLite, this constraint
 * is NOT exercised by any test on the development host. Task 10 owns real
 * PostgreSQL 18 verification and is where it first executes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('idempotency_key', 191)->nullable()->after('correlation_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX orders_idempotency_key_unq ON orders (idempotency_key) '.
            'WHERE idempotency_key IS NOT NULL'
        );

        if (DB::connection()->getDriverName() === 'pgsql') {
            $types = implode("', '", array_map(
                static fn (ProductType $type): string => $type->value,
                ProductType::cases(),
            ));

            DB::statement(
                'ALTER TABLE orders ADD CONSTRAINT orders_product_type_check '.
                "CHECK (product_type IN ('{$types}'))"
            );
        }
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE orders DROP CONSTRAINT IF EXISTS orders_product_type_check');
        }

        DB::statement('DROP INDEX IF EXISTS orders_idempotency_key_unq');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('idempotency_key');
        });
    }
};

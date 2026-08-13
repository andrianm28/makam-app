<?php

declare(strict_types=1);

use App\Domain\OrderWorkflow\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `orders` — Task 2 of the `platform-order-orchestration` plan
 * (`docs/superpowers/plans/2026-08-12-platform-order-orchestration.md`
 * Task 2). The commercial-order aggregate. `status` is
 * `App\Domain\OrderWorkflow\OrderStatus` (Task 1, already merged) and is
 * changed ONLY by `App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange`
 * — see that class's own doc block.
 *
 * `product_type` is a plain string column here. `App\Domain\OrderWorkflow\
 * ProductType` (the closed-list enum named in the plan's File Structure) is
 * built by a later task in this lane — this migration does not invent it,
 * matching this repo's convention of not gold-plating a schema ahead of the
 * enum that will constrain it (see `payment_verifications.status`, which
 * got its CHECK only once `PaymentVerificationStatus` existed).
 *
 * `booking_draft_id` is a real foreign key — `booking_drafts` exists
 * (`2026_08_08_130000_create_booking_drafts_table.php`). `nullOnDelete`
 * because an order must never be silently deleted by a draft cleanup; it
 * simply loses the convenience link back to its originating draft, the
 * same reasoning `booking_drafts.cemetery_id` already uses.
 *
 * `funeral_case_id` and `pre_need_case_id` are plain nullable UUID columns
 * with NO foreign key — neither `funeral_cases` nor `pre_need_cases`
 * exists yet in this repository (grep confirms no migration for either).
 * A later lane that creates those tables is expected to add the
 * constraint then, not this one.
 *
 * `paid_via` and `paid_source_ref` are nullable and are set ONLY by
 * `Actions\ApplyPaidEffects` (a later task, not built here) — never by
 * `RecordOrderStatusChange`, which changes `status` alone.
 *
 * Postgres CHECK constraint on `status` pins it to the 13 known
 * `OrderStatus` values, guarded to `pgsql` because SQLite cannot `ALTER
 * TABLE ... ADD CONSTRAINT` and remains this repository's local/test
 * driver — same convention as `payment_verifications.status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Human-facing reference, e.g. "MK-2026-000123". Unique, but
            // generated elsewhere (a later task) — this migration only
            // declares the column and its uniqueness constraint.
            $table->string('reference', 32)->unique();

            $table->string('product_type', 64);

            // `App\Domain\OrderWorkflow\OrderStatus`. CHECK-constrained on
            // Postgres below.
            $table->string('status', 64);

            $table->foreignUuid('booking_draft_id')
                ->nullable()
                ->constrained('booking_drafts')
                ->nullOnDelete();

            // No FK — see class doc block. Plain reference columns until the
            // owning tables exist.
            $table->uuid('funeral_case_id')->nullable();
            $table->uuid('pre_need_case_id')->nullable();

            // Set only by `Actions\ApplyPaidEffects` (a later task).
            $table->string('paid_via', 64)->nullable();
            $table->string('paid_source_ref', 191)->nullable();

            $table->string('correlation_id')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('product_type');
        });

        // SQLite cannot ADD CONSTRAINT. CI and every real environment run
        // Postgres — same guard as `payment_verifications.status`.
        if (DB::connection()->getDriverName() === 'pgsql') {
            $statuses = implode("', '", array_map(
                static fn (OrderStatus $status): string => $status->value,
                OrderStatus::cases(),
            ));

            DB::statement(
                'ALTER TABLE orders ADD CONSTRAINT orders_status_check '.
                "CHECK (status IN ('{$statuses}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};

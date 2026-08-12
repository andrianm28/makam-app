<?php

declare(strict_types=1);

use App\Domain\Quotation\QuoteStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `quotes` — Task 4 of the `platform-order-orchestration` plan
 * (`docs/superpowers/plans/2026-08-12-platform-order-orchestration.md`
 * Task 4). One row per QUOTE VERSION for an order; acceptance and
 * supersession are properties of the version, never of the order
 * (`task-4-brief.md` Q4/Q5).
 *
 * `version_number` is unique PER ORDER (the `(order_id, version_number)`
 * unique pair below) — the same "the database decides, not a read-then-
 * write" discipline the lane applies everywhere else. Two concurrent
 * issuances for one order race on this pair, not on a PHP `MAX()+1`.
 *
 * `status` is `App\Domain\Quotation\QuoteStatus` and moves ONLY through
 * `Actions\IssueQuote` (issued -> superseded stamp on the current version)
 * and `Actions\AcceptQuote` (issued -> accepted). `Models\Quote`'s write
 * guard refuses every other update path — see that model's doc block for
 * what the overrides close and what they cannot.
 *
 * `total_minor` is the integer minor-units total of the version's lines —
 * computed exactly once at issuance from the frozen `ServicePackageVersion`
 * prices (`line_total_minor = unit_amount_minor * quantity`, summed with
 * `Money::add`). Stored as an integer bigint because money is never a float
 * in this codebase (`App\Platform\FinancialLedger\Money`).
 *
 * `issued_by_ref` / `issued_by_role` / `accepted_by_ref` record who moved
 * the version, mirroring `order_status_events.actor_ref` / `actor_role` —
 * the action interfaces carry the actors, and the version row is the only
 * honest home for them (the outbox envelope's `actor` is emitted null by
 * design — see `outbox-event-contract.md` §5 — and no audit row is written
 * by this task).
 *
 * `expires_at` is a reference to a point in time; whether an accepted
 * version is still usable is evaluated LAZILY and AUTHORITATIVELY at guard
 * time (`Quote::isAcceptedAndUnexpired()`), never trusted to a scheduled
 * job.
 *
 * Postgres CHECK constraint on `status` pins it to the 3 known
 * `QuoteStatus` values, guarded to `pgsql` because SQLite cannot `ALTER
 * TABLE ... ADD CONSTRAINT` and remains this repository's local/test driver
 * — same convention as `orders.status`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // `restrictOnDelete()`, NOT `cascadeOnDelete()`. A quote is a
            // frozen commercial record a later payment guard and its outbox
            // events reference; deleting an order must never silently
            // destroy its quote history. Same choice as
            // `order_status_events.order_id`.
            $table->foreignUuid('order_id')->constrained('orders')->restrictOnDelete();

            // Unique per order — see class doc block.
            $table->unsignedInteger('version_number');

            $table->string('status', 64);

            $table->bigInteger('total_minor');
            $table->string('currency', 3);

            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('superseded_at')->nullable();

            $table->string('issued_by_ref')->nullable();
            $table->string('issued_by_role');
            $table->string('accepted_by_ref')->nullable();

            $table->unique(['order_id', 'version_number'], 'quotes_order_version_unique');
            $table->index(['order_id', 'status'], 'quotes_order_status_idx');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            $statuses = implode("', '", array_map(
                static fn (QuoteStatus $status): string => $status->value,
                QuoteStatus::cases(),
            ));

            DB::statement(
                'ALTER TABLE quotes ADD CONSTRAINT quotes_status_check '.
                "CHECK (status IN ('{$statuses}'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('quotes');
    }
};

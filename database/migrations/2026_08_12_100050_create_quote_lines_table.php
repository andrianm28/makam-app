<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `quote_lines` — Task 4 of the `platform-order-orchestration` plan. The
 * frozen line-level snapshot of a quote version. One row per quoted item,
 * written once at issuance and never modified afterward
 * (`Models\QuoteLine`'s write guard refuses every update/delete path).
 *
 * `service_package_version_id` and `price_version_id` are real foreign keys
 * (`restrictOnDelete`) because they are the AUTHORITATIVE source of what
 * was quoted: `design.md` §Consumption boundary says the quote holds the
 * OLD snapshot, and the frozen rows are what AC3's "exact package, service,
 * and price versions selected" means. Both tables are append-only at the
 * model layer, so a restrict FK here adds a database-level backstop for a
 * delete that should be impossible anyway.
 *
 * `unit_amount_minor` and `line_total_minor` are computed by
 * `Actions\IssueQuote` at issuance (`Money::fromDecimal()` exactly once,
 * then `line_total_minor = unit_amount_minor * quantity`) — never trusted
 * from a caller. `price_version_number` is the denormalized version stamp
 * carried alongside the `price_versions.id` reference.
 *
 * `currency` is repeated on the line because the whole line set shares one
 * currency (`IssueQuote` rejects mixed sets); keeping it here lets a single
 * line row read as a complete snapshot without a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('quote_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // See the `quotes` migration for why restrict, not cascade.
            $table->foreignUuid('quote_id')->constrained('quotes')->restrictOnDelete();

            // Frozen authoritative references — see class doc block.
            $table->foreignId('service_package_version_id')
                ->constrained('service_package_versions')
                ->restrictOnDelete();
            $table->foreignId('price_version_id')
                ->constrained('price_versions')
                ->restrictOnDelete();
            $table->unsignedInteger('price_version_number');

            $table->string('description');
            $table->unsignedInteger('quantity');
            $table->bigInteger('unit_amount_minor');
            $table->bigInteger('line_total_minor');
            $table->string('currency', 3);

            $table->string('fulfillment_owner', 64);

            $table->index('quote_id');
            $table->index('service_package_version_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('quote_lines');
    }
};

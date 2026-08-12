<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `vendors` — the marketplace vendor identity this repository has referred to
 * by opaque string id since the financial ledger landed.
 *
 * `id` is a UUID because `vendor_payables.vendor_id` is a plain string that
 * shipped code already uses as `scope_assignments.entity_id` for
 * `entity_type = 'vendor'`. Matching that value space means this table needs
 * no backfill and `vendor_payables` needs no schema change.
 *
 * No bank or payout columns: payouts (requirement 11) are out of this lane's
 * scope, and an unused column holding bank details is a liability, not a
 * placeholder.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendors', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendors');
    }
};

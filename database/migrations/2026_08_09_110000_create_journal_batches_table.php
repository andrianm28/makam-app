<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('journal_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('business_key')->unique();
            $table->string('entity_ref');
            $table->string('source_type');
            $table->string('source_id');
            $table->string('correlation_id')->nullable();
            $table->timestamp('occurred_at');
            $table->string('status');

            $table->index('entity_ref');
            $table->index(['source_type', 'source_id']);
            $table->index('occurred_at');
            $table->index('status');
        });

        // SQLite cannot add table constraints with ALTER TABLE. Local tests
        // therefore cover the required shape there, while PostgreSQL 18 is
        // the authoritative production/CI enforcement path.
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE journal_batches ADD CONSTRAINT journal_batches_source_type_check '.
                "CHECK (source_type IN ('payment', 'manual_verification', 'renewal', 'refund', 'chargeback', 'payout', 'reversal'))"
            );
            DB::statement(
                'ALTER TABLE journal_batches ADD CONSTRAINT journal_batches_status_check '.
                "CHECK (status IN ('open', 'posted', 'reversed'))"
            );
            DB::statement(
                'ALTER TABLE journal_batches ADD CONSTRAINT journal_batches_business_key_prefix_check '.
                "CHECK (position(':' in business_key) > 1)"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('journal_batches');
    }
};

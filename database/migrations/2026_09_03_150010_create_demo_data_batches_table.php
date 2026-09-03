<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per `demo-data:seed` run (Task 10). `demo-data:purge` (Task 11)
 * reads the most recent row here when no explicit batch id is given, and
 * this table is itself the human-readable audit trail of what's been
 * seeded when — deliberately NOT tagged with its own demo_batch_id column
 * (it IS the batch registry, not seeded data itself).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('demo_data_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('batch_id')->unique();
            $table->json('summary')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('demo_data_batches');
    }
};

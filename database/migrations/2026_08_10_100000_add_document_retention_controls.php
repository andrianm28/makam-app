<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Additive retention controls for private documents. The fields are separate
 * so an evidence hold and a legal hold can be set and cleared independently.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->boolean('legal_hold')->default(false);
            $table->boolean('evidence_hold')->default(false);
        });
    }

    public function down(): void
    {
        // Forward-only production rollback preserves retention evidence.
    }
};

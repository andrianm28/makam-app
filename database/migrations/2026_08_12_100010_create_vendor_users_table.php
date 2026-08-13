<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `vendor_users` — MEMBERSHIP METADATA ONLY. Never an authorization source.
 *
 * `scope_assignments` with `entity_type = 'vendor'` is the single authority on
 * whether an actor may act for a vendor, and shipped code already queries it
 * that way. If this table also answered that question the two could disagree,
 * which is exactly the rival-scoping-mechanism defect the identity seam exists
 * to prevent. No policy or query scope may branch on this table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vendor_users', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->uuid('vendor_id');
            $table->string('actor_identifier');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->foreign('vendor_id')->references('id')->on('vendors')->restrictOnDelete();
            $table->unique(['vendor_id', 'actor_identifier'], 'vendor_users_membership_unique');
            $table->index('actor_identifier');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vendor_users');
    }
};

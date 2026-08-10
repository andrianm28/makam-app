<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds retry-safe provider identity and an atomic claim lease to
 * `notification_deliveries`.
 *
 * The provider key is nullable only for rows created before this migration;
 * every new delivery written by `DispatchNotification` persists a deterministic
 * SHA-256 key derived from its durable `(event_id, recipient_ref, channel,
 * window_key)` identity. `claimed_at`/`claim_token` keep QUEUED as the truthful
 * UI state while preventing concurrent workers from entering the provider
 * boundary together. A stale lease can be reclaimed with the same provider key.
 *
 * `template_version_id` is nullable so a missing active version is represented
 * by an explicit `UNAVAILABLE` delivery row rather than a blank notification or
 * a silently omitted external delivery. Valid deliveries still pin an immutable
 * template version.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->string('provider_idempotency_key', 64)->nullable()->after('window_key');
            $table->string('claim_token', 36)->nullable()->after('provider_idempotency_key');
            $table->timestamp('claimed_at')->nullable()->after('claim_token');
            $table->unsignedBigInteger('template_version_id')->nullable()->change();

            $table->unique('provider_idempotency_key', 'notification_deliveries_provider_key_unique');
            $table->index(['state', 'claimed_at'], 'notification_deliveries_claim_index');
        });
    }

    public function down(): void
    {
        Schema::table('notification_deliveries', function (Blueprint $table): void {
            $table->dropUnique('notification_deliveries_provider_key_unique');
            $table->dropIndex('notification_deliveries_claim_index');
            $table->dropColumn(['provider_idempotency_key', 'claim_token', 'claimed_at']);
            $table->unsignedBigInteger('template_version_id')->nullable(false)->change();
        });
    }
};

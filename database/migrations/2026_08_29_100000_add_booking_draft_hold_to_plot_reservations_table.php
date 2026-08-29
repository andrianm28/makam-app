<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E (`docs/superpowers/plans/2026-08-29-customer-plot-picker-hold.md`
 * Task 1) — extends `plot_reservations` so a hold can be anchored to a
 * `BookingDraft` (a customer's step-2 plot pick, before an `Order` exists)
 * the same way `order_id` already anchors an operator-initiated hold.
 *
 * `restrictOnDelete()`, matching `order_id`'s own choice on this table —
 * see that column's comment in `2026_08_16_100020_create_plot_reservations
 * _table.php`: a cascade would let a `booking_drafts` row deletion silently
 * erase reservation evidence. `booking_drafts` has no delete/purge path in
 * this codebase today, so restrict costs nothing operationally.
 *
 * No CHECK constraint enforcing "exactly one of order_id/booking_draft_id":
 * this codebase's own precedent for that shape
 * (`orders.funeral_case_id`/`pre_need_case_id`) is construction discipline
 * in the writing actions, not a database constraint — see this plan's
 * "Resolutions" section, point 2.
 *
 * `expires_at` is nullable because only draft-scoped `held` rows ever set
 * it — an operator-initiated (`order_id`-anchored) hold has no TTL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plot_reservations', function (Blueprint $table) {
            $table->foreignUuid('booking_draft_id')->nullable()->after('order_id')->constrained('booking_drafts')->restrictOnDelete();
            $table->timestamp('expires_at')->nullable()->after('expired_at');

            $table->index(['booking_draft_id', 'state']);
        });
    }

    public function down(): void
    {
        Schema::table('plot_reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('booking_draft_id');
            $table->dropColumn('expires_at');
        });
    }
};

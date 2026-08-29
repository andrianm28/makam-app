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
 * `nullOnDelete()` — and NOT `restrictOnDelete()`, which is what this plan's
 * own "Resolutions" section (point 1) and an earlier version of this doc
 * block both specified. That specification was wrong on its stated premise
 * ("`booking_drafts` has no delete/purge path in this codebase today"):
 * `App\Domain\Booking\Actions\PurgeStaleBookingDrafts` bulk-`delete()`s
 * stale drafts nightly (`routes/console.php`, 30-day retention from
 * `config/booking.php`), for privacy/retention reasons. Reconciling the two:
 * the retention sweep wins, because `plot_reservations` is append-only —
 * its rows are NEVER deleted (the model's `delete()` throws
 * unconditionally), so a RESTRICT could never be satisfied by cascading
 * cleanup. The first purged draft that had ever held a plot would raise an
 * FK violation, and since the purge is one bulk `DELETE` in one
 * transaction, that single row would abort the WHOLE nightly sweep — every
 * night, forever, silently — leaving customer and deceased PII in
 * `booking_drafts` indefinitely against the documented retention policy.
 *
 * `order_id`'s own `restrictOnDelete()` on this table is NOT contradicted:
 * `orders` has no purge path and an order is a commercial record that must
 * not vanish, so restrict is right there. The asymmetry is deliberate — a
 * draft is transient PII with a scheduled expiry date, an order is not.
 *
 * The evidence this table exists to preserve survives the null: the
 * reservation rows themselves are untouched (append-only), and
 * `reserved_by_ref` independently stores `"booking_draft:{id}"` as a plain
 * string, so which draft a hold belonged to stays textually traceable after
 * the FK is severed. Only the join link is lost, and only for a draft that
 * has already been deliberately erased.
 *
 * No CHECK constraint enforcing "exactly one of order_id/booking_draft_id":
 * this codebase's own precedent for that shape
 * (`orders.funeral_case_id`/`pre_need_case_id`) is construction discipline
 * in the writing actions, not a database constraint — see this plan's
 * "Resolutions" section, point 2.
 *
 * `expires_at` is nullable because only draft-scoped `held` rows ever set
 * it — an operator-initiated (`order_id`-anchored) hold has no TTL.
 *
 * Two indexes, because the two readers have different leading columns:
 * `(booking_draft_id, state)` serves `PlotReservation::activeForDraft()`,
 * which always knows the draft; `(state, expires_at)` serves
 * `PlotReservationExpiryScheduler`'s candidate sweep, which runs every
 * minute with `booking_draft_id` entirely unconstrained and would
 * otherwise have no usable index at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plot_reservations', function (Blueprint $table) {
            $table->foreignUuid('booking_draft_id')->nullable()->after('order_id')->constrained('booking_drafts')->nullOnDelete();
            $table->timestamp('expires_at')->nullable()->after('expired_at');

            $table->index(['booking_draft_id', 'state']);
            $table->index(['state', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::table('plot_reservations', function (Blueprint $table) {
            // Dropped explicitly, and BEFORE its columns: `state` outlives
            // this migration, so unlike `(booking_draft_id, state)` this
            // index is not carried away by the column drops below.
            $table->dropIndex(['state', 'expires_at']);

            $table->dropConstrainedForeignId('booking_draft_id');
            $table->dropColumn('expires_at');
        });
    }
};

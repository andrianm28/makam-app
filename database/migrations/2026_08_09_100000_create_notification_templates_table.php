<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Versioned notification template catalogue. The event and recipient/channel
 * facts remain owned by `docs/contracts/notification-matrix.md`; this table
 * stores the current versioned rendering pointer for each matrix event. The
 * default channel is nullable because matrix rows with only IN_APP/optional/
 * status facts must not acquire an invented external delivery channel.
 *
 * `active_version_id` is added before the versions table exists so the two
 * tables can be created in dependency order. Its foreign key is attached by
 * the following migration after the target table has been created.
 *
 * `outbox_event_name` — Task 1 fix round applying ruling 1's approved
 * refinement (`docs/superpowers/plans/2026-08-10-wave1a-notifications-
 * decisions.md`). `event_name` stays the matrix's own human row label (the
 * matrix remains the row-identity authority per AC1); this column carries
 * the machine event key from `docs/contracts/event-catalog.md` that later
 * dispatch work keys on. It is nullable and deliberately NOT unique:
 *
 * - Only 6 of the 17 matrix rows have a clean, unambiguous catalogue
 *   counterpart (`Booking submitted`, `Availability requested`,
 *   `Availability confirmed/rejected`, `Quote issued`, `Quote accepted`,
 *   `Payment received`). Every other row is NULL — NULL means no template
 *   matches, so nothing is sent. That is the same honest-absence pattern as
 *   this table's nullable `default_channel`, not an error condition.
 * - `Availability confirmed/rejected` maps only to
 *   `availability.confirmed.v2`, the *confirmed* half. No catalogue event
 *   exists for the rejected half, so rejection notifications have no
 *   trigger yet.
 * - `Order processing` and `Order completed` both correspond to
 *   `order.status_changed.v1`, but the column is non-unique specifically
 *   because that mapping is ambiguous, not to allow duplicates casually:
 *   whether `order.status_changed.v1` needs discrimination by status value
 *   so these two rows can be addressed separately is an OPEN question
 *   recorded under ruling 1. It is NOT assumed here — both rows stay NULL.
 * - `Reminder due` deliberately stays NULL. It is NOT mapped to
 *   `grave.reminder_sent.v1` — that event's catalogue definition *records
 *   that a reminder was already sent* (main consumer: Reporting), so using
 *   it to trigger sending a reminder would be circular. Flagged here for
 *   whoever builds the reminder lane: that lane needs its own trigger
 *   event, not this one.
 * - The remaining 8 rows have no catalogue counterpart at all and stay
 *   NULL rather than receiving an invented event name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('event_name')->unique();
            $table->string('default_channel')->nullable();
            $table->string('outbox_event_name')->nullable();
            $table->unsignedBigInteger('active_version_id')->nullable();
            $table->index('active_version_id');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_default_channel_check '.
                "CHECK (default_channel IS NULL OR default_channel IN ('EMAIL', 'WA'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};

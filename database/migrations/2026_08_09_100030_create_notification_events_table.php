<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `notification_events` — Task 3 of the L2 `platform-notifications` lane
 * (`docs/superpowers/plans/2026-08-09-platform-notifications.md`,
 * `task-3-brief.md`). The idempotency anchor for the whole outbox-fed
 * dispatch pipeline (AC8): `App\Platform\Notification\Actions\
 * DispatchNotification::consumeOutboxEvent()` inserts exactly one row per
 * outbox `event_id` — a second delivery of the same outbox event finds the
 * row already present and no-ops the entire per-event pipeline (recipient
 * resolution, delivery rows, in-app rows) rather than repeating any of it.
 *
 * `event_id` (the outbox row's own id, a UUIDv7 string — see
 * `App\Platform\Outbox\Models\OutboxEvent`) is the PRIMARY KEY, not merely
 * a unique column: this table has no independent identity of its own, one
 * row exists per outbox event by construction, and making it the primary
 * key lets `notification_recipients`/`notification_deliveries`/
 * `in_app_notifications` reference it directly without a redundant
 * surrogate id. Same pattern as `outbox_events.id` itself.
 *
 * `event_name` is the MACHINE event name carried by the outbox envelope
 * (e.g. `booking.draft_submitted.v2`); `matrix_event_name` is the matrix
 * row LABEL (e.g. `Booking submitted`) that `RecipientResolver::resolve()`
 * and `NotificationMatrixSource::forEvent()` key on — see `task-3-brief.md`
 * D2 for why both must be stored: the envelope only carries the former, and
 * bridging between the two happens once, via `notification_templates.
 * outbox_event_name`, at consume time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_events', function (Blueprint $table): void {
            $table->string('event_id')->primary();
            $table->string('event_name');
            $table->string('matrix_event_name');
            $table->string('aggregate_type');
            $table->string('aggregate_id');
            $table->string('trace_id')->nullable();
            $table->timestamp('consumed_at');

            $table->index('matrix_event_name');
            $table->index(['aggregate_type', 'aggregate_id']);
            $table->index('trace_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_events');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `notification_deliveries` — Task 3 of the L2 `platform-notifications`
 * lane (`task-3-brief.md`). The ONLY table a view may read to claim a
 * message was sent/delivered (AC4) — `App\Platform\Notification\Actions\
 * DispatchNotification` is the ONE write API for this table (AC9); no
 * other class inserts, updates, or deletes a row here.
 *
 * `channel` holds `EMAIL` or `WA` only — the two channel values supported by
 * `App\Platform\Notification\Contracts\Channel` (development uses
 * `LogChannel`; a closed provider uses `NullChannel`). `IN_APP` is
 * deliberately NOT a row in this table: AC7's
 * in-app record is unconditional for admin/operator/vendor recipients and
 * lives in `in_app_notifications` instead, written in the SAME transaction
 * as this table's rows so a failed/unavailable external channel can never
 * erase it. `MANUAL` is a legend token `task-3-brief.md` D4 names, but the
 * channel-token scanner (`Actions\DispatchNotification::
 * DISPATCHABLE_CHANNEL_TOKENS`) deliberately does NOT scan for it — it
 * never appears in any current matrix cell (see `task-3-report.md`) and
 * has no `Channel` implementation to dispatch to. Flagged there for Task 6,
 * not modelled here.
 *
 * `window_key` (task-3-brief.md D8): a real column with a real unique
 * constraint below, degenerate today — every one of the 6 outbox-mapped
 * matrix rows is transactional, so every row this lane writes sets
 * `window_key = event_id`. Reminder-style events will need a real time
 * bucket here later; the column exists now so that arrives as a value
 * change, not a schema migration.
 *
 * `state` is `App\Platform\Notification\DeliveryState`'s five values
 * (`QUEUED|SENT|DELIVERED|FAILED|UNAVAILABLE`) — structurally validated by
 * the PHP enum and, on Postgres, also by the CHECK constraint below (same
 * pgsql-only guard pattern as `outbox_events.classification` and
 * `notification_templates.default_channel` — SQLite's `ALTER TABLE` has no
 * `ADD CONSTRAINT`, and this repo's default local test driver is SQLite).
 *
 * `template_version_id` pins the exact immutable
 * `notification_template_versions` row a delivery was rendered against
 * (AC13) — a later template edit can never retroactively change what an
 * already-queued/sent record says it said. Migration
 * `2026_08_09_100070` makes it nullable only for an explicit `UNAVAILABLE`
 * configuration row when no active version exists; valid deliveries remain
 * pinned.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_deliveries', function (Blueprint $table): void {
            $table->id();

            $table->string('event_id');
            $table->foreign('event_id')
                ->references('event_id')
                ->on('notification_events')
                ->restrictOnDelete();

            $table->foreignId('notification_recipient_id')
                ->constrained('notification_recipients')
                ->restrictOnDelete();

            $table->string('recipient_ref');
            $table->string('channel', 16);
            $table->string('window_key');
            $table->string('state', 16);

            $table->foreignId('template_version_id')
                ->constrained('notification_template_versions')
                ->restrictOnDelete();

            $table->string('provider_ref')->nullable();
            $table->text('failure_message')->nullable();
            $table->unsignedInteger('attempt_count')->default(0);

            $table->timestamps();

            // AC8/D8: the idempotency key a redelivered outbox event's
            // insert-ignoring-conflicts write collides on.
            $table->unique(
                ['event_id', 'recipient_ref', 'channel', 'window_key'],
                'notification_deliveries_idempotency_unique'
            );

            $table->index('state');
        });

        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_state_check '.
                "CHECK (state IN ('QUEUED', 'SENT', 'DELIVERED', 'FAILED', 'UNAVAILABLE'))"
            );
            DB::statement(
                'ALTER TABLE notification_deliveries ADD CONSTRAINT notification_deliveries_channel_check '.
                "CHECK (channel IN ('EMAIL', 'WA'))"
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_deliveries');
    }
};

<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `notification_recipients` — Task 3 of the L2 `platform-notifications`
 * lane (`task-3-brief.md`). One row per recipient
 * `App\Platform\Notification\RecipientResolver::resolve()` returned for a
 * `notification_events` row — the durable record of WHO was resolved,
 * independent of what channel(s), if any, were dispatched for them (a
 * `PLATFORM_ADMIN`/`CEMETERY_OPERATOR`/`VENDOR` recipient with an
 * `optional`/no-token matrix cell still gets a row here, and an
 * `in_app_notifications` row, even with zero `notification_deliveries`
 * rows — see AC7).
 *
 * `recipient_ref`/`scope_entity_id` are plain strings, not typed foreign
 * keys — same reasoning as `scope_assignments.actor_identifier`/
 * `entity_id` (see that migration's own doc block): identity is not
 * mastered here, and `Recipient::actorRef`/`scopeEntityId` are themselves
 * `int|string`.
 *
 * No unique constraint on `(event_id, recipient_ref, actor_role,
 * scope_entity_type, scope_entity_id)`: `notification_events.event_id`
 * already gates the entire per-event pipeline exactly-once (see that
 * migration's own doc block) — `DispatchNotification::consumeOutboxEvent()`
 * only ever reaches this table's insert once per event_id, so a second
 * uniqueness layer here would be enforcing a condition that can no longer
 * occur by the time this table is written. `notification_deliveries` still
 * gets its own unique constraint (task-3-brief.md D8) because that table's
 * insert additionally uses an insert-ignoring-conflicts path as explicit,
 * literal, defense-in-depth per that ruling — this table does not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_recipients', function (Blueprint $table): void {
            $table->id();

            $table->string('event_id');
            $table->foreign('event_id')
                ->references('event_id')
                ->on('notification_events')
                ->restrictOnDelete();

            $table->string('recipient_ref');
            $table->string('actor_role');
            $table->string('scope_entity_type')->nullable();
            $table->string('scope_entity_id')->nullable();

            $table->index('event_id');
            $table->index(['scope_entity_type', 'scope_entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_recipients');
    }
};

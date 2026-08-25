<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `in_app_notifications` — Task 3 of the L2 `platform-notifications` lane
 * (`task-3-brief.md`). AC7: "Always create relevant admin/operator/vendor
 * in-app records using record scope" (`AGENTS.md` §Notifications) —
 * unconditional for every resolved `PLATFORM_ADMIN`/`CEMETERY_OPERATOR`/
 * `VENDOR` recipient, independent of whether their matrix cell carries an
 * `IN_APP` token and independent of any external channel's later outcome.
 * `App\Platform\Notification\Actions\RecordInAppNotification` writes this
 * table inside the SAME transaction as the owning `notification_events`
 * row, so a failed/unavailable email or WhatsApp send can never erase it.
 *
 * `subject`/`body` are a rendered COPY, not a live pointer to the template
 * version — the in-app record must keep reading the same text after a
 * later template edit, the same "pin the render, don't repoint it" reason
 * `notification_deliveries.template_version_id` exists.
 *
 * `read_at` (nullable) is the only column this table's own write path
 * (`RecordInAppNotification`) does not set — Task 3 creates the record
 * unread; a later "mark as read" surface (out of this task's scope) is
 * expected to update it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('in_app_notifications', function (Blueprint $table): void {
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

            $table->string('subject')->nullable();
            $table->text('body');

            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index('event_id');
            // The panel's own primary access path: "this recipient's
            // unread-first notification list."
            $table->index(['recipient_ref', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('in_app_notifications');
    }
};

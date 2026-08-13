<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Wires the order-lifecycle notification events to their outbox event names.
 * The template rows for "Order processing" and "Order completed" already exist
 * (created by the notification-matrix seed). This migration sets their
 * `outbox_event_name` values, which is what `DispatchNotification` reads to
 * route an outbox event to the correct notification template.
 *
 * "Payment opened" and "Payment received" are NOT wired here:
 * - payment.received.v1 is emitted by ApplyPaidEffects (Task 7); its
 *   outbox_event_name is already on the "Payment received" template — the
 *   Wave-1a seeder maps it (`2026_08_09_100020_...:135`), so no wiring is
 *   needed here.
 * - payment.opened.v1 has no producer yet; wiring it would produce zero
 *   notifications since nothing emits it.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('notification_templates')
            ->where('event_name', 'Order processing')
            ->update(['outbox_event_name' => 'order.processing.v1']);

        DB::table('notification_templates')
            ->where('event_name', 'Order completed')
            ->update(['outbox_event_name' => 'order.completed.v1']);
    }

    public function down(): void
    {
        DB::table('notification_templates')
            ->where('outbox_event_name', 'order.processing.v1')
            ->update(['outbox_event_name' => null]);

        DB::table('notification_templates')
            ->where('outbox_event_name', 'order.completed.v1')
            ->update(['outbox_event_name' => null]);
    }
};

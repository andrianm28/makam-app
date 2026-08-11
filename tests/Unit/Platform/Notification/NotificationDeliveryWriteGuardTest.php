<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\Exceptions\NotificationDeliveryWriteNotAllowedException;
use App\Platform\Notification\NotificationDeliveryWriteGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class NotificationDeliveryWriteGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_direct_delivery_write_is_rejected_before_the_row_lands(): void
    {
        $eventId = 'guard-test-event';

        [$recipientId, $versionId] = $this->createWriteParents($eventId);

        try {
            $this->expectException(NotificationDeliveryWriteNotAllowedException::class);

            DB::table('notification_deliveries')->insert([
                'event_id' => $eventId,
                'notification_recipient_id' => $recipientId,
                'recipient_ref' => 'guard-test-recipient',
                'channel' => 'EMAIL',
                'window_key' => $eventId,
                'state' => 'QUEUED',
                'template_version_id' => $versionId,
                'attempt_count' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } finally {
            $this->assertDatabaseMissing('notification_deliveries', ['event_id' => $eventId]);
        }
    }

    public function test_the_write_scope_cannot_be_used_by_a_caller_outside_dispatch_notification(): void
    {
        $eventId = 'guard-scope-test-event';
        [$recipientId, $versionId] = $this->createWriteParents($eventId);

        try {
            $this->expectException(NotificationDeliveryWriteNotAllowedException::class);

            NotificationDeliveryWriteGuard::withWritesUnlocked(function () use ($eventId, $recipientId, $versionId): void {
                DB::table('notification_deliveries')->insert([
                    'event_id' => $eventId,
                    'notification_recipient_id' => $recipientId,
                    'recipient_ref' => 'guard-scope-recipient',
                    'channel' => 'EMAIL',
                    'window_key' => $eventId,
                    'state' => 'QUEUED',
                    'template_version_id' => $versionId,
                    'attempt_count' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } finally {
            $this->assertDatabaseMissing('notification_deliveries', ['event_id' => $eventId]);
        }
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function createWriteParents(string $eventId): array
    {
        DB::table('notification_events')->insert([
            'event_id' => $eventId,
            'event_name' => 'guard.test.v1',
            'matrix_event_name' => 'Guard test',
            'aggregate_type' => 'guard_test',
            'aggregate_id' => 'guard-test-aggregate',
            'consumed_at' => now(),
        ]);
        $recipientId = DB::table('notification_recipients')->insertGetId([
            'event_id' => $eventId,
            'recipient_ref' => 'guard-test-recipient',
            'actor_role' => 'customer',
        ]);
        $templateId = DB::table('notification_templates')->insertGetId([
            'event_name' => 'Guard test '.$eventId,
            'default_channel' => 'EMAIL',
            'outbox_event_name' => null,
            'active_version_id' => null,
        ]);
        $versionId = DB::table('notification_template_versions')->insertGetId([
            'template_id' => $templateId,
            'version' => 1,
            'subject' => null,
            'body' => 'Guard test',
            'variable_allowlist' => json_encode([]),
            'restricted_fields' => json_encode([]),
            'created_by' => 'test',
            'created_at' => now(),
        ]);

        return [$recipientId, $versionId];
    }
}

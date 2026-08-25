<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\Exceptions\NotificationDeliveryWriteNotAllowedException;
use App\Platform\Notification\NotificationDeliveryWriteGuard;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use WeakReference;

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
     * The guard used to record which connections it had hooked by
     * `spl_object_id()`. PHP reuses those integers once the original
     * object is collected, so a replacement connection could inherit a
     * destroyed one's id, `register()` would see it as
     * already-registered, return early, and leave that connection with
     * no `beforeExecuting()` hook at all — writes to
     * `notification_deliveries` on it would be completely unguarded.
     *
     * What actually builds and discards connections while calling
     * `register()` again is repeated application bootstraps in one
     * process — this suite boots the providers afresh per test, which is
     * where the original intermittent failure surfaced.
     *
     * The recycling is forced here rather than waited for: purge the
     * connection, collect, and rebuild until PHP hands the replacement
     * the same id the registered one had. That turns a GC-timing race
     * that only showed up intermittently under full-suite load into a
     * deterministic assertion.
     *
     * Purging drops the in-memory schema with the connection, which is
     * why this asserts the exception rather than the absence of a row:
     * `beforeExecuting()` fires before the statement reaches PDO, so a
     * hooked connection rejects the write before SQLite can complain
     * that the table is gone. An unhooked one raises `QueryException`
     * instead — a different failure, which is exactly what makes this
     * test able to tell the two apart.
     */
    public function test_register_hooks_a_replacement_connection_that_reuses_a_recycled_object_id(): void
    {
        // Runs against a throwaway connection, never the suite's own:
        // this repeatedly destroys the connection it is given, and the
        // in-memory database dies with it, which would strip the schema
        // out from under every later test in the process.
        $defaultConnection = config('database.default');

        config(['database.connections.sqlite_guard_probe' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]]);
        config(['database.default' => 'sqlite_guard_probe']);

        try {
            // Settle into the state where rebuilding the connection
            // reuses one recycled object id. A strong reference to the
            // purged connection survives the cycle that purges it, so
            // its handle is still taken when the replacement is
            // allocated and the very first purge does not recycle; one
            // further cycle is enough, and 5 is margin.
            for ($warmUp = 0; $warmUp < 5; $warmUp++) {
                DB::purge();
                gc_collect_cycles();
                DB::connection();
            }

            NotificationDeliveryWriteGuard::register();
            $registeredId = spl_object_id(DB::connection());
            $registered = WeakReference::create(DB::connection());

            DB::purge();
            gc_collect_cycles();

            // Id equality alone cannot tell "a new object recycled the id"
            // from "the purge never dropped the object, so this IS the same
            // instance". The degenerate case still has the original hook
            // attached, so the insert below would be rejected and the test
            // would pass green under the buggy keying too — asserting
            // nothing while looking healthy. Proving the original object is
            // gone is what makes the id match mean what the next assertion
            // says it means.
            $this->assertNull(
                $registered->get(),
                'precondition: expected the registered connection object to be destroyed by the purge',
            );

            $this->assertSame(
                $registeredId,
                spl_object_id(DB::connection()),
                'precondition: expected the replacement connection to reuse the recycled spl_object_id',
            );

            NotificationDeliveryWriteGuard::register();

            $rejected = false;

            try {
                DB::table('notification_deliveries')->insert([
                    'event_id' => 'guard-recycled-id-event',
                    'recipient_ref' => 'guard-recycled-id-recipient',
                    'channel' => 'EMAIL',
                    'state' => 'QUEUED',
                ]);
            } catch (NotificationDeliveryWriteNotAllowedException) {
                $rejected = true;
            } catch (QueryException $e) {
                // The unguarded write reached PDO. Reported here rather
                // than left to propagate, because a bare missing-table
                // error reads as environment breakage rather than as the
                // security regression it actually is.
                $this->fail(
                    'the replacement connection was left unguarded: the write reached the database ('.$e->getMessage().')',
                );
            }

            $this->assertTrue(
                $rejected,
                'the replacement connection was left unguarded: the write reached the database instead of being rejected',
            );
        } finally {
            DB::purge();
            config(['database.default' => $defaultConnection]);
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

<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Audit\Exceptions\AuditMetadataKeyNotAllowedException;
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\Models\AuditEvent;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `Audit::record()` — the single write API. AC2 (required fields),
 * AC3 (sensitive-action reason requirement), AC5 (metadata allowlist).
 */
final class AuditRecordTest extends TestCase
{
    use RefreshDatabase;

    public function test_record_writes_a_row_with_every_ac2_required_field(): void
    {
        $event = Audit::record(
            action: 'booking.updated',
            subject: new AuditSubject(type: 'booking', id: 7, version: 2),
            outcome: AuditOutcome::Allowed,
            actorRef: 99,
            actorRole: 'admin',
            source: AuditSource::Panel,
            correlationId: 'corr-123',
            metadata: ['note' => 'rescheduled'],
        );

        $this->assertDatabaseHas('audit_events', [
            'id' => $event->id,
            'actor_ref' => '99',
            'actor_role' => 'admin',
            'action' => 'booking.updated',
            'source' => 'panel',
            'subject_type' => 'booking',
            'subject_id' => '7',
            'subject_version' => '2',
            'correlation_id' => 'corr-123',
            'outcome' => 'allowed',
        ]);

        $this->assertNotNull($event->occurred_at);
        $this->assertSame(['note' => 'rescheduled'], $event->metadata);
    }

    public function test_record_allows_a_null_actor_ref_alongside_a_required_actor_role(): void
    {
        $event = Audit::record(
            action: 'booking.autosaved',
            subject: new AuditSubject(type: 'booking', id: 1),
            outcome: AuditOutcome::Allowed,
            actorRef: null,
            actorRole: 'guest',
            source: AuditSource::Api,
        );

        $this->assertNull($event->actor_ref);
        $this->assertSame('guest', $event->actor_role);
    }

    public function test_a_sensitive_action_without_a_reason_throws_and_writes_no_row(): void
    {
        $this->expectException(AuditReasonRequiredException::class);

        try {
            Audit::record(
                action: 'DITOLAK',
                subject: new AuditSubject(type: 'booking', id: 1),
                outcome: AuditOutcome::Denied,
                actorRef: 1,
                actorRole: 'admin',
                source: AuditSource::Panel,
            );
        } finally {
            $this->assertSame(0, AuditEvent::query()->count());
        }
    }

    public function test_a_sensitive_action_with_only_whitespace_as_reason_still_throws(): void
    {
        $this->expectException(AuditReasonRequiredException::class);

        Audit::record(
            action: 'VENDOR_PAYOUT',
            subject: new AuditSubject(type: 'payout', id: 1),
            outcome: AuditOutcome::Allowed,
            actorRef: 1,
            actorRole: 'finance',
            source: AuditSource::Panel,
            reason: '   ',
        );
    }

    public function test_a_sensitive_action_with_a_real_reason_succeeds(): void
    {
        $event = Audit::record(
            action: 'CERTIFICATE_REVOKE',
            subject: new AuditSubject(type: 'certificate', id: 55),
            outcome: AuditOutcome::Allowed,
            actorRef: 1,
            actorRole: 'admin',
            source: AuditSource::Panel,
            reason: 'Duplicate certificate issued in error.',
        );

        $this->assertSame('Duplicate certificate issued in error.', $event->reason);
    }

    public function test_a_non_sensitive_action_never_requires_a_reason(): void
    {
        $event = Audit::record(
            action: 'booking.updated',
            subject: new AuditSubject(type: 'booking', id: 1),
            outcome: AuditOutcome::Allowed,
            actorRef: 1,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->assertNull($event->reason);
    }

    public function test_a_metadata_key_outside_the_allowlist_throws_and_writes_no_row(): void
    {
        $this->expectException(AuditMetadataKeyNotAllowedException::class);

        try {
            Audit::record(
                action: 'booking.updated',
                subject: new AuditSubject(type: 'booking', id: 1),
                outcome: AuditOutcome::Allowed,
                actorRef: 1,
                actorRole: 'admin',
                source: AuditSource::Panel,
                metadata: ['ktp_number' => '3171xxxxxxxxxxxx'],
            );
        } finally {
            $this->assertSame(0, AuditEvent::query()->count());
        }
    }

    public function test_the_outcome_check_constraint_rejects_a_value_outside_the_closed_set_at_the_database(): void
    {
        // Bypasses the App\Platform\Audit\AuditOutcome PHP enum
        // entirely to prove the Postgres CHECK constraint added in
        // the migration is real, independent enforcement — not just
        // documentation of intent. See the migration's own doc block:
        // this is the one piece of AC1-adjacent enforcement that does
        // NOT wait on finding N-1's role split.
        //
        // The constraint is only added on the pgsql driver (see the
        // migration's own comment — SQLite's ALTER TABLE cannot add a
        // CHECK constraint at all), so this test is meaningful only
        // there. CI's test job always runs on pgsql
        // (.github/workflows/ci.yml); a local run against the
        // phpunit.xml sqlite default skips this one test rather than
        // failing for an environment reason unrelated to the code
        // under test.
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('audit_events_outcome_check is only added on the pgsql driver.');
        }

        $this->expectException(QueryException::class);

        AuditEvent::query()->create([
            'occurred_at' => now(),
            'actor_ref' => '1',
            'actor_role' => 'admin',
            'action' => 'booking.updated',
            'source' => 'panel',
            'subject_type' => 'booking',
            'subject_id' => '1',
            'outcome' => 'not_a_real_outcome',
        ]);
    }
}

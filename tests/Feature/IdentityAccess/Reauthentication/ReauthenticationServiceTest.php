<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Reauthentication;

use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\MetadataAllowlist;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationAuditActions;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `ReauthenticationService::challenge()`/`::satisfy()` — the dual-write
 * (`reauthentication_events` + `audit_events`) pattern mirrored from
 * `Mfa\MfaChallengeService`/`Mfa\MfaRecoveryService`, and the rate limiter
 * that bounds write volume (see the service's own class-level doc block for
 * why that reasoning differs from MFA's own use of the same
 * `MfaRateLimiter` class).
 */
final class ReauthenticationServiceTest extends TestCase
{
    use RefreshDatabase;

    private const string REASON = 'certificate_revoke';

    public function test_challenge_writes_a_reauthentication_event_and_a_denied_audit_event(): void
    {
        $service = new ReauthenticationService;

        $result = $service->challenge(
            actorRef: 42,
            actorRole: 'authenticated_actor',
            reason: self::REASON,
            source: AuditSource::Panel,
            ip: '203.0.113.10',
        );

        $this->assertTrue($result->recorded);
        $this->assertFalse($result->rateLimited);
        $this->assertNotNull($result->event);
        $this->assertSame(ReauthenticationOutcome::CHALLENGED, $result->event->outcome);
        $this->assertSame('42', $result->event->actor_ref);

        $auditEvent = AuditEvent::query()->where('action', ReauthenticationAuditActions::CHALLENGED)->latest('id')->first();
        $this->assertNotNull($auditEvent);
        $this->assertSame(AuditOutcome::Denied->value, $auditEvent->outcome);
        $this->assertSame(['note' => self::REASON], $auditEvent->metadata);
    }

    public function test_a_null_actor_ref_is_still_recorded(): void
    {
        $service = new ReauthenticationService;

        $result = $service->challenge(
            actorRef: null,
            actorRole: 'guest',
            reason: self::REASON,
            source: AuditSource::Panel,
        );

        $this->assertTrue($result->recorded);
        $this->assertNull($result->event->actor_ref);
    }

    public function test_satisfy_writes_a_reauthentication_event_and_an_allowed_audit_event(): void
    {
        $service = new ReauthenticationService;

        $event = $service->satisfy(
            actorRef: 7,
            actorRole: 'authenticated_actor',
            reason: self::REASON,
            source: AuditSource::Panel,
            ip: '203.0.113.20',
        );

        $this->assertSame(ReauthenticationOutcome::SATISFIED, $event->outcome);

        $auditEvent = AuditEvent::query()->where('action', ReauthenticationAuditActions::SATISFIED)->latest('id')->first();
        $this->assertNotNull($auditEvent);
        $this->assertSame(AuditOutcome::Allowed->value, $auditEvent->outcome);
        $this->assertSame((string) $event->id, $auditEvent->subject_id);
    }

    public function test_rate_limiter_blocks_challenge_writes_after_the_documented_threshold(): void
    {
        $service = new ReauthenticationService;
        $ip = '198.51.100.30';

        // MfaRateLimiter's documented 5-attempts-per-60-seconds threshold,
        // reused as-is by this service under its own
        // 'reauthentication-challenge' context.
        for ($i = 0; $i < 5; $i++) {
            $result = $service->challenge(1, 'authenticated_actor', self::REASON, AuditSource::Panel, $ip);
            $this->assertTrue($result->recorded, "Attempt {$i} should still be recorded.");
        }

        $sixth = $service->challenge(1, 'authenticated_actor', self::REASON, AuditSource::Panel, $ip);

        $this->assertFalse($sixth->recorded);
        $this->assertTrue($sixth->rateLimited);
        $this->assertSame(5, ReauthenticationEvent::query()->count(), 'The 6th call must not write a 7th row.');
    }

    public function test_rate_limit_is_scoped_per_actor_and_ip_so_a_different_actor_is_not_blocked(): void
    {
        $service = new ReauthenticationService;
        $ip = '198.51.100.31';

        for ($i = 0; $i < 5; $i++) {
            $service->challenge(1, 'authenticated_actor', self::REASON, AuditSource::Panel, $ip);
        }

        $result = $service->challenge(2, 'authenticated_actor', self::REASON, AuditSource::Panel, $ip);

        $this->assertTrue($result->recorded);
    }

    public function test_satisfy_clears_the_challenge_rate_limit_counter(): void
    {
        $service = new ReauthenticationService;
        $ip = '198.51.100.32';

        for ($i = 0; $i < 5; $i++) {
            $service->challenge(3, 'authenticated_actor', self::REASON, AuditSource::Panel, $ip);
        }

        $service->satisfy(3, 'authenticated_actor', self::REASON, AuditSource::Panel, $ip);

        // If the counter were not cleared, this 6th challenge call would
        // still be rate-limited immediately after satisfy().
        $result = $service->challenge(3, 'authenticated_actor', self::REASON, AuditSource::Panel, $ip);

        $this->assertTrue($result->recorded);
    }

    public function test_neither_write_ever_persists_a_credential_or_secret_shaped_value(): void
    {
        $service = new ReauthenticationService;
        $service->challenge(9, 'authenticated_actor', self::REASON, AuditSource::Panel);
        $service->satisfy(9, 'authenticated_actor', self::REASON, AuditSource::Panel);

        foreach (AuditEvent::query()->get() as $auditEvent) {
            foreach (array_keys($auditEvent->metadata ?? []) as $key) {
                $this->assertContains($key, MetadataAllowlist::ALLOWED_KEYS);
            }
        }
    }
}

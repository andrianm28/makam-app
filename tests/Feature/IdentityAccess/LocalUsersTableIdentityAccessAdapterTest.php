<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Models\User;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Adapters\LocalUsersTableIdentityAccessAdapter;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Models\ActorSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The MVP `IdentityAccessAdapter` implementation, backed by the `users` and
 * `actor_sessions` tables. Explicitly does NOT test anything about a real
 * K1/K2 contract — there isn't one to test against (see the class's own
 * doc block).
 */
final class LocalUsersTableIdentityAccessAdapterTest extends TestCase
{
    use RefreshDatabase;

    public function test_null_identity_resolves_to_guest_context(): void
    {
        $adapter = new LocalUsersTableIdentityAccessAdapter;

        $context = $adapter->resolveActorContext(null);

        $this->assertFalse($context->isAuthenticated());
        $this->assertSame(ActorContext::MFA_STATE_NOT_APPLICABLE, $context->mfaState);
    }

    public function test_authenticated_identity_resolves_with_identity_reference_set(): void
    {
        $user = User::factory()->create();
        $adapter = new LocalUsersTableIdentityAccessAdapter;

        $context = $adapter->resolveActorContext($user);

        $this->assertTrue($context->isAuthenticated());
        $this->assertSame($user->id, $context->identityReference);
    }

    public function test_authenticated_identity_with_no_actor_session_has_null_last_authenticated_at(): void
    {
        // The known, flagged gap: nothing has written an actor_sessions row
        // for this user (no login flow has run), so lastAuthenticatedAt
        // must be null rather than fabricated.
        $user = User::factory()->create();
        $adapter = new LocalUsersTableIdentityAccessAdapter;

        $context = $adapter->resolveActorContext($user);

        $this->assertNull($context->lastAuthenticatedAt);
    }

    public function test_last_authenticated_at_reflects_the_most_recent_non_revoked_actor_session(): void
    {
        $user = User::factory()->create();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'older-session',
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::parse('2026-07-20T09:00:00Z'),
        ]);
        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'newer-session',
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::parse('2026-07-24T09:00:00Z'),
        ]);

        $adapter = new LocalUsersTableIdentityAccessAdapter;
        $context = $adapter->resolveActorContext($user);

        $this->assertNotNull($context->lastAuthenticatedAt);
        $this->assertTrue(
            CarbonImmutable::parse('2026-07-24T09:00:00Z')->equalTo($context->lastAuthenticatedAt)
        );
    }

    public function test_revoked_actor_sessions_are_excluded_from_last_authenticated_at(): void
    {
        $user = User::factory()->create();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'revoked-session',
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::parse('2026-07-25T09:00:00Z'),
            'revoked_at' => CarbonImmutable::parse('2026-07-25T09:05:00Z'),
        ]);
        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'still-active-session',
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::parse('2026-07-23T09:00:00Z'),
        ]);

        $adapter = new LocalUsersTableIdentityAccessAdapter;
        $context = $adapter->resolveActorContext($user);

        $this->assertTrue(
            CarbonImmutable::parse('2026-07-23T09:00:00Z')->equalTo($context->lastAuthenticatedAt)
        );
    }

    public function test_authenticated_identity_always_has_empty_roles_and_scopes_today(): void
    {
        // Locks in the flagged gap documented on ActorContext/this adapter
        // — must not silently start returning fabricated roles/scopes.
        $user = User::factory()->create();
        $adapter = new LocalUsersTableIdentityAccessAdapter;

        $context = $adapter->resolveActorContext($user);

        $this->assertSame([], $context->roles);
        $this->assertSame([], $context->scopes);
    }

    /**
     * S3-T2: `mfaState` is no longer the permanent NOT_IMPLEMENTED
     * placeholder — this test is RENAMED (from
     * `test_authenticated_identity_mfa_state_is_not_implemented_not_satisfied`)
     * and its assertion FIXED to match, per this batch's explicit
     * instruction not to leave a now-incorrect test green by accident. An
     * authenticated actor with no `mfa_enrolments` row at all (the
     * everyday case for every actor until they enrol) reports
     * `MFA_STATE_NOT_ENROLLED` — still never "satisfied", just an honest,
     * queryable reason instead of "the subsystem does not exist."
     */
    public function test_authenticated_identity_with_no_mfa_enrolment_reports_not_enrolled(): void
    {
        $user = User::factory()->create();
        $adapter = new LocalUsersTableIdentityAccessAdapter;

        $context = $adapter->resolveActorContext($user);

        $this->assertSame(ActorContext::MFA_STATE_NOT_ENROLLED, $context->mfaState);
    }

    public function test_authenticated_identity_with_pending_enrolment_reports_enrolment_pending(): void
    {
        $user = User::factory()->create();
        MfaEnrolment::query()->create([
            'user_id' => $user->id,
            'secret' => 'JBSWY3DPEHPK3PXP',
            'status' => MfaEnrolmentStatus::PENDING,
        ]);

        $adapter = new LocalUsersTableIdentityAccessAdapter;
        $context = $adapter->resolveActorContext($user);

        $this->assertSame(ActorContext::MFA_STATE_ENROLMENT_PENDING, $context->mfaState);
    }

    public function test_authenticated_identity_with_confirmed_enrolment_reports_enrolled(): void
    {
        $user = User::factory()->create();
        MfaEnrolment::query()->create([
            'user_id' => $user->id,
            'secret' => 'JBSWY3DPEHPK3PXP',
            'status' => MfaEnrolmentStatus::CONFIRMED,
            'confirmed_at' => CarbonImmutable::now(),
        ]);

        $adapter = new LocalUsersTableIdentityAccessAdapter;
        $context = $adapter->resolveActorContext($user);

        $this->assertSame(ActorContext::MFA_STATE_ENROLLED, $context->mfaState);
    }

    public function test_authenticated_identity_with_only_a_revoked_enrolment_reports_not_enrolled(): void
    {
        // A revoked-only history must not be mistaken for an active
        // enrolment of any kind.
        $user = User::factory()->create();
        MfaEnrolment::query()->create([
            'user_id' => $user->id,
            'secret' => 'JBSWY3DPEHPK3PXP',
            'status' => MfaEnrolmentStatus::REVOKED,
            'revoked_at' => CarbonImmutable::now(),
        ]);

        $adapter = new LocalUsersTableIdentityAccessAdapter;
        $context = $adapter->resolveActorContext($user);

        $this->assertSame(ActorContext::MFA_STATE_NOT_ENROLLED, $context->mfaState);
    }

    public function test_authenticated_identity_with_revoked_then_confirmed_enrolment_reports_enrolled(): void
    {
        // Mirrors MfaEnrolmentService::startEnrolment()'s real supersede
        // history: an old revoked row plus a newer confirmed one must
        // resolve to the newer, active row — never the older, dead one.
        $user = User::factory()->create();
        MfaEnrolment::query()->create([
            'user_id' => $user->id,
            'secret' => 'JBSWY3DPEHPK3PXQ',
            'status' => MfaEnrolmentStatus::REVOKED,
            'revoked_at' => CarbonImmutable::now(),
        ]);
        MfaEnrolment::query()->create([
            'user_id' => $user->id,
            'secret' => 'JBSWY3DPEHPK3PXP',
            'status' => MfaEnrolmentStatus::CONFIRMED,
            'confirmed_at' => CarbonImmutable::now(),
        ]);

        $adapter = new LocalUsersTableIdentityAccessAdapter;
        $context = $adapter->resolveActorContext($user);

        $this->assertSame(ActorContext::MFA_STATE_ENROLLED, $context->mfaState);
    }
}

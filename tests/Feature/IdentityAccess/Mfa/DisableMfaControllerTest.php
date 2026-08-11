<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Mfa;

use App\Models\User;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentService;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Mfa\Totp\Base32;
use App\Platform\IdentityAccess\Mfa\Totp\Totp;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `DisableMfaController` — Task 6, `RequireRecentAuthentication`'s first
 * real attachment anywhere in this repo. Proves both halves of the gate:
 * a stale (or absent) `ActorContext::$lastAuthenticatedAt` redirects to
 * `MfaChallenge` without revoking anything, and a genuinely fresh session
 * lets the disable through.
 */
final class DisableMfaControllerTest extends TestCase
{
    use RefreshDatabase;

    private function confirmEnrolment(MfaEnrolment $enrolment, User $user): MfaEnrolment
    {
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);
        $code = $totp->generate(Base32::decode($enrolment->secret), time(), $enrolment->digits);

        $confirmation = app(MfaEnrolmentService::class)->confirm(
            $enrolment,
            $code,
            actorRef: $user->id,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        return $confirmation->enrolment;
    }

    public function test_disable_without_a_fresh_authentication_redirects_to_the_challenge(): void
    {
        $user = User::factory()->create();
        $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
        $enrolment = $this->confirmEnrolment($enrolment, $user);

        // Deliberately NOT creating an actor_sessions row with a recent
        // last_authenticated_at — RequireRecentAuthentication fails closed
        // on a null timestamp (its own doc block: "no timestamp should mean
        // STALE").
        $this->actingAs($user)
            ->post(route('admin.mfa.disable'))
            ->assertRedirect(route('filament.admin.pages.mfa-challenge'));

        $this->assertSame(MfaEnrolmentStatus::CONFIRMED, $enrolment->fresh()->status);
    }

    public function test_disable_with_a_fresh_authentication_actually_revokes(): void
    {
        $user = User::factory()->create();
        $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
        $enrolment = $this->confirmEnrolment($enrolment, $user);

        // Same fixture mechanism RequireRecentAuthenticationMiddlewareTest
        // established: a real actor_sessions row with a recent
        // last_authenticated_at is what ActorContext resolves as "fresh".
        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subSeconds(10),
        ]);

        $this->actingAs($user)
            ->post(route('admin.mfa.disable'))
            ->assertRedirect(route('filament.admin.pages.mfa-settings'));

        $this->assertSame(MfaEnrolmentStatus::REVOKED, $enrolment->fresh()->status);
    }

    /**
     * This controller used to call `ReauthenticationService::satisfy()` on
     * every invocation — minting, unconditionally, the exact proof a
     * sensitive action is supposed to REQUIRE. `FinancialLedger`'s own
     * export controller was corrected for the identical anti-pattern.
     * Re-proof is now recorded by the one surface that actually verifies
     * anything, `MfaChallenge::submit()`, and this controller records none.
     */
    public function test_the_controller_never_mints_its_own_proof_of_reauthentication(): void
    {
        $user = User::factory()->create();
        $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
        $this->confirmEnrolment($enrolment, $user);

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subSeconds(10),
        ]);

        $this->actingAs($user)
            ->post(route('admin.mfa.disable'))
            ->assertRedirect(route('filament.admin.pages.mfa-settings'));

        $this->assertSame(
            0,
            ReauthenticationEvent::query()->where('outcome', ReauthenticationOutcome::SATISFIED)->count(),
            'Passing the gate is not proof of re-authentication; only a completed challenge is.',
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Mfa;

use App\Filament\Admin\Pages\MfaChallenge;
use App\Models\User;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentService;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Mfa\Totp\Base32;
use App\Platform\IdentityAccess\Mfa\Totp\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `App\Filament\Admin\Pages\MfaChallenge` — the shared TOTP/recovery-code
 * challenge surface. Verifies both the TOTP and recovery-code paths satisfy
 * the exact session key `EnforceMfaChallenge` reads
 * (`EnforceMfaChallenge::SESSION_KEY`), and that a wrong code satisfies
 * neither.
 */
final class MfaChallengePageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Confirms via a real TOTP code, same pattern as
     * `EnforceMfaChallengeMiddlewareTest`/`MfaChallengeServiceTest`, and
     * returns the confirmation (carrying the fresh `MfaEnrolment` and the
     * plaintext recovery codes) rather than just the enrolment, so callers
     * that need a recovery code can read it off the result.
     */
    private function confirmEnrolment(MfaEnrolment $enrolment, User $user)
    {
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);
        $code = $totp->generate(Base32::decode($enrolment->secret), time(), $enrolment->digits);

        return app(MfaEnrolmentService::class)->confirm(
            $enrolment,
            $code,
            actorRef: $user->id,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );
    }

    /**
     * Same "one period ahead" reasoning `MfaChallengeServiceTest::
     * currentCodeFor()` documents: confirmation itself already consumed the
     * current time-step as `last_verified_counter`, so a code for that same
     * step would be rejected as a replay for a reason unrelated to this
     * test.
     */
    private function currentCodeFor(MfaEnrolment $enrolment): string
    {
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);

        return $totp->generate(Base32::decode($enrolment->secret), time() + $enrolment->period_seconds, $enrolment->digits);
    }

    public function test_submitting_a_valid_totp_code_satisfies_the_session_and_redirects(): void
    {
        $user = User::factory()->create();
        $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
        $confirmation = $this->confirmEnrolment($enrolment, $user);
        $code = $this->currentCodeFor($confirmation->enrolment);

        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', $code)
            ->call('submit')
            ->assertRedirect();

        $this->assertTrue(session()->has('mfa_challenge_satisfied_at'));
    }

    public function test_a_wrong_code_does_not_satisfy_the_session(): void
    {
        $user = User::factory()->create();
        $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
        $this->confirmEnrolment($enrolment, $user);

        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', '000000')
            ->call('submit')
            ->assertHasErrors(['code']);

        $this->assertFalse(session()->has('mfa_challenge_satisfied_at'));
    }

    public function test_a_recovery_code_also_satisfies_the_session(): void
    {
        $user = User::factory()->create();
        $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
        $confirmation = $this->confirmEnrolment($enrolment, $user);
        $recoveryCode = $confirmation->recoveryCodes[0];

        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', $recoveryCode)
            ->call('submit')
            ->assertRedirect();

        $this->assertTrue(session()->has('mfa_challenge_satisfied_at'));
    }

    public function test_a_used_recovery_code_cannot_be_redeemed_twice(): void
    {
        $user = User::factory()->create();
        $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
        $confirmation = $this->confirmEnrolment($enrolment, $user);
        $recoveryCode = $confirmation->recoveryCodes[0];

        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', $recoveryCode)
            ->call('submit')
            ->assertRedirect();

        session()->forget('mfa_challenge_satisfied_at');

        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', $recoveryCode)
            ->call('submit')
            ->assertHasErrors(['code']);

        $this->assertFalse(session()->has('mfa_challenge_satisfied_at'));
    }
}

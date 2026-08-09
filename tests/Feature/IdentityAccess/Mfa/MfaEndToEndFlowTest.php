<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Mfa;

use App\Filament\Admin\Pages\MfaChallenge;
use App\Filament\Admin\Pages\MfaSettings;
use App\Models\User;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Mfa\Totp\Base32;
use App\Platform\IdentityAccess\Mfa\Totp\Totp;
use App\Platform\IdentityAccess\Models\ActorSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The one test in this plan proving the full journey works end to end —
 * enroll (Task 5's `MfaSettings`) -> login-time challenge (Tasks 2/3/4's
 * `EnforceMfaChallenge` + `MfaChallenge`) -> disable gated by re-authentication
 * (Task 6's `DisableMfaController` + `RequireRecentAuthentication`) — rather
 * than each piece in isolation, which Tasks 1-6's own test files already
 * cover.
 *
 * TOTP-code generation and the `ActorSession` fresh-authentication fixture
 * are not reinvented here: they reuse the exact mechanisms
 * `EnforceMfaChallengeMiddlewareTest::confirmEnrolment()`,
 * `MfaChallengePageTest::currentCodeFor()` (the "one time-step ahead" reason
 * documented there — a confirm() call already consumes the current counter
 * as `last_verified_counter`, so a same-step code would be rejected as a
 * replay, not because of anything this test is trying to prove), and
 * `DisableMfaControllerTest::test_disable_with_a_fresh_authentication_
 * actually_revokes()`'s `ActorSession` fixture already established and
 * verified.
 */
final class MfaEndToEndFlowTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Same fix `AdminPanelHttpAccessTest`'s own doc block documents: this
     * test renders the real `/admin` dashboard view (not just a redirect)
     * three times, and no `public/build/manifest.json` exists in this test
     * environment — the frontend build is a separate `frontend` CI job with
     * no shared artifact. Without this, a genuinely working redirect/access
     * flow fails on a missing, unrelated Vite manifest, not a real bug.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_full_journey_enroll_login_challenge_disable(): void
    {
        $user = User::factory()->create();

        // 1. Not enrolled — visiting the dashboard is untouched.
        $this->actingAs($user)->get('/admin')->assertOk();

        // 2. Visit MfaSettings — a pending enrolment is auto-started.
        $settings = Livewire::actingAs($user)->test(MfaSettings::class);
        $pendingEnrolment = $settings->get('enrolment');

        // 3. Confirm with a real TOTP code for the current time-step.
        $code = $this->totpCodeFor($pendingEnrolment, time());
        $settings->set('confirmationCode', $code)->call('confirmEnrolment');
        $this->assertNotEmpty($settings->get('displayedRecoveryCodes'));

        // 4. Now enrolled — the dashboard redirects to the challenge.
        $this->actingAs($user)
            ->get('/admin')
            ->assertRedirect(route('filament.admin.pages.mfa-challenge'));

        // 5. Complete the challenge with a fresh code — one time-step ahead,
        // since confirmEnrolment() above already consumed the current
        // counter as last_verified_counter (MfaChallengePageTest's own
        // documented reasoning for the identical situation).
        $confirmedEnrolment = $this->latestEnrolmentFor($user);
        $challengeCode = $this->totpCodeFor($confirmedEnrolment, time() + $confirmedEnrolment->period_seconds);

        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', $challengeCode)
            ->call('submit');

        $this->assertTrue(session()->has('mfa_challenge_satisfied_at'));

        // 6. Now the dashboard is reachable.
        $this->actingAs($user)->get('/admin')->assertOk();

        // 7. Disable without a fresh reauth — redirected to challenge, NOT disabled.
        $this->actingAs($user)
            ->post(route('admin.mfa.disable'))
            ->assertRedirect(route('filament.admin.pages.mfa-challenge'));

        $this->assertSame(MfaEnrolmentStatus::CONFIRMED, $this->latestEnrolmentFor($user)->status);

        // 8. Build a genuinely fresh session (DisableMfaControllerTest's own
        // ActorSession fixture pattern — ActorContext resolves freshness by
        // user_id, not by matching the test client's real session id), then
        // disable succeeds.
        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subSeconds(10),
        ]);

        $this->actingAs($user)->post(route('admin.mfa.disable'))->assertRedirect();

        $this->assertSame(MfaEnrolmentStatus::REVOKED, $this->latestEnrolmentFor($user)->status);

        // 9. Post-disable, the dashboard no longer challenges.
        $this->actingAs($user)->get('/admin')->assertOk();
    }

    private function totpCodeFor(MfaEnrolment $enrolment, int $timestamp): string
    {
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);

        return $totp->generate(Base32::decode($enrolment->secret), $timestamp, $enrolment->digits);
    }

    private function latestEnrolmentFor(User $user): MfaEnrolment
    {
        return MfaEnrolment::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->firstOrFail();
    }
}

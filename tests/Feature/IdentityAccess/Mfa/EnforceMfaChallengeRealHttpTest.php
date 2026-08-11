<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Mfa;

use App\Filament\Admin\Pages\MfaChallenge;
use App\Models\User;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentService;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Mfa\Totp\Base32;
use App\Platform\IdentityAccess\Mfa\Totp\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The reference behavioural test for `EnforceMfaChallenge` over REAL HTTP,
 * through the real `AdminPanelProvider` middleware stack and real panel
 * routes. Deliberately broader than the one defect that prompted it, because
 * two separate lanes needed a case like this and neither could write it:
 *
 * - Every other test of this middleware
 *   (`EnforceMfaChallengeMiddlewareTest`, `RequireRecentAuthenticationMiddlewareTest`)
 *   uses an ad-hoc fixture route, and `MfaChallengePageTest` drives the page
 *   through `Livewire::test()`, which invokes the component directly. All
 *   three bypass the panel middleware array — which is why the redirect loop
 *   below survived unnoticed.
 * - The financial-ledger lane attached this middleware to its export route
 *   but found that none of its own tests exercised enforcement at all: every
 *   `ActorContext` bound in them resolved to `MFA_STATE_NOT_APPLICABLE`,
 *   which this middleware treats as pass-through, so those tests would have
 *   passed whether or not the middleware worked.
 *
 * That second point is why `test_the_fixture_actor_really_resolves_as_enrolled`
 * exists and why the pass-through case asserts `MFA_STATE_NOT_ENROLLED`
 * rather than merely observing a 200. A test that silently degrades into
 * "the middleware was never engaged" is worse than no test: it reports
 * green for a control it never touched. Assert the precondition, then the
 * behaviour.
 *
 * The defect this file was opened for: `EnforceMfaChallenge` guards every
 * panel route, including the challenge page it redirects to, and had no
 * exemption for it — so the page redirected to itself
 * (`ERR_TOO_MANY_REDIRECTS`, no enrolled actor able to reach the panel at
 * all) and overwrote `url.intended` with its own URL.
 */
final class EnforceMfaChallengeRealHttpTest extends TestCase
{
    use RefreshDatabase;

    private const string CHALLENGE_PATH = '/admin/mfa-challenge';

    /**
     * A real guarded panel route — the dashboard. Any panel route would do;
     * what matters is that it is a REAL one carrying the real middleware
     * array, not a fixture.
     */
    private const string GUARDED_PATH = '/admin';

    /**
     * Rendering a real panel page resolves Filament's base layout, which
     * reads the Vite manifest — a build artifact present on neither a dev
     * host nor CI's PHP job (the frontend build is a separate job, see
     * `ci-cd-and-release.md` §10). This stubs the asset tags only; every
     * request below still travels the real route and the real middleware
     * stack, which is the entire point of this file.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function enrolledUser(): User
    {
        $user = User::factory()->create();
        $this->confirm(app(MfaEnrolmentService::class)->startEnrolment($user->id), $user);

        return $user;
    }

    private function confirm(MfaEnrolment $enrolment, User $user): MfaEnrolment
    {
        return app(MfaEnrolmentService::class)->confirm(
            $enrolment,
            $this->codeFor($enrolment, stepsAhead: 0),
            actorRef: $user->id,
            actorRole: 'authenticated_actor',
            source: AuditSource::Panel,
        )->enrolment;
    }

    /**
     * `stepsAhead: 1` for a challenge, because confirmation already consumed
     * the current time step as `last_verified_counter` and a code for that
     * step is rejected as a replay.
     */
    private function codeFor(MfaEnrolment $enrolment, int $stepsAhead = 1): string
    {
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);

        return $totp->generate(
            Base32::decode($enrolment->secret),
            time() + ($stepsAhead * $enrolment->period_seconds),
            $enrolment->digits,
        );
    }

    /**
     * A real request boundary builds a fresh container, re-resolving the
     * `scoped()` `ActorContext`; Laravel's test harness reuses one container
     * for every request in a test method. See
     * `Tests\Feature\IdentityAccess\Reauthentication\MfaChallengeSatisfiesRecentAuthenticationTest`
     * for the same helper and the same reasoning.
     */
    private function crossRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
    }

    public function test_the_fixture_actor_really_resolves_as_enrolled(): void
    {
        $this->actingAs($this->enrolledUser());

        $this->assertSame(
            ActorContext::MFA_STATE_ENROLLED,
            app(ActorContext::class)->mfaState,
            'Every behavioural assertion below is vacuous unless the middleware actually sees an enrolled actor.',
        );
    }

    public function test_an_enrolled_actor_is_challenged_before_reaching_a_guarded_panel_route(): void
    {
        $response = $this->actingAs($this->enrolledUser())->get(self::GUARDED_PATH);

        $response->assertRedirect(route('filament.admin.pages.mfa-challenge'));
        $response->assertSessionHas('url.intended', url(self::GUARDED_PATH));
    }

    public function test_an_enrolled_actor_reaches_the_challenge_form_instead_of_a_redirect_loop(): void
    {
        $response = $this->actingAs($this->enrolledUser())->get(self::CHALLENGE_PATH);

        $response->assertOk();
        $response->assertSee('Kode verifikasi');
    }

    public function test_the_challenge_page_does_not_overwrite_the_intended_sensitive_action(): void
    {
        $intended = url('/admin/some-sensitive-action');
        session()->put('url.intended', $intended);

        $this->actingAs($this->enrolledUser())->get(self::CHALLENGE_PATH)->assertOk();

        $this->assertSame(
            $intended,
            session()->get('url.intended'),
            'The challenge page must send the actor back to the action they were attempting.',
        );
    }

    public function test_an_enrolled_actor_reaches_the_panel_after_completing_the_challenge(): void
    {
        $user = $this->enrolledUser();
        $enrolment = MfaEnrolment::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
        $this->actingAs($user);

        $this->get(self::GUARDED_PATH)->assertRedirect(route('filament.admin.pages.mfa-challenge'));

        $this->crossRequestBoundary();

        $this->get(self::CHALLENGE_PATH)->assertOk();

        $this->crossRequestBoundary();

        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', $this->codeFor($enrolment))
            ->call('submit')
            ->assertRedirect();

        $this->crossRequestBoundary();

        $this->get(self::GUARDED_PATH)->assertOk();
    }

    public function test_an_actor_with_no_enrolment_is_never_challenged(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $this->assertSame(
            ActorContext::MFA_STATE_NOT_ENROLLED,
            app(ActorContext::class)->mfaState,
            'Pass-through must be proven for a real non-enrolled actor, not for an actor the middleware never classified.',
        );

        $this->crossRequestBoundary();

        $this->get(self::GUARDED_PATH)->assertOk();
    }
}

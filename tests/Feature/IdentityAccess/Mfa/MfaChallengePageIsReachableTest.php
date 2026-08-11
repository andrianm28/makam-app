<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Mfa;

use App\Models\User;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentService;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Mfa\Totp\Base32;
use App\Platform\IdentityAccess\Mfa\Totp\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A REAL `GET /admin/mfa-challenge`, over HTTP, through the actual panel
 * middleware stack — the one thing no other test in this repo did, and
 * exactly why this defect survived: `EnforceMfaChallengeMiddlewareTest` and
 * `RequireRecentAuthenticationMiddlewareTest` use ad-hoc fixture routes, and
 * `MfaChallengePageTest` drives the page through `Livewire::test()`, which
 * invokes the component directly. All three bypass
 * `AdminPanelProvider`'s middleware array, where `EnforceMfaChallenge` lives.
 *
 * The defect: that middleware guards every panel route, including the
 * challenge page it redirects to, so an enrolled actor was redirected from
 * the challenge page to the challenge page — `ERR_TOO_MANY_REDIRECTS`, and
 * no enrolled actor could reach the panel at all. It also overwrote
 * `url.intended` with the challenge page's own URL, so even past the loop an
 * actor would have been returned to the challenge page instead of the
 * sensitive action they were attempting.
 *
 * Nothing here asserts that the gate still works — that is
 * `EnforceMfaChallengeMiddlewareTest`'s job, and those tests must keep
 * passing alongside these.
 */
final class MfaChallengePageIsReachableTest extends TestCase
{
    use RefreshDatabase;

    private const string CHALLENGE_PATH = '/admin/mfa-challenge';

    /**
     * Rendering a real panel page pulls in Filament's base layout, which
     * resolves the Vite manifest — a build artifact that exists on neither
     * this host nor CI's PHP job (`ci-cd-and-release.md` §10: the frontend
     * build is its own job). `withoutVite()` stubs only the asset tags; the
     * request still travels the real route and the real middleware stack,
     * which is the whole point of this file.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * A confirmed enrolment and NO `EnforceMfaChallenge::SESSION_KEY` in the
     * session: precisely the state the middleware acts on, and the state
     * every actor is in at the moment they are sent to this page.
     */
    private function enrolledUser(): User
    {
        $user = User::factory()->create();
        $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
        $this->confirm($enrolment, $user);

        return $user;
    }

    private function confirm(MfaEnrolment $enrolment, User $user): void
    {
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);

        app(MfaEnrolmentService::class)->confirm(
            $enrolment,
            $totp->generate(Base32::decode($enrolment->secret), time(), $enrolment->digits),
            actorRef: $user->id,
            actorRole: 'authenticated_actor',
            source: AuditSource::Panel,
        );
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
}

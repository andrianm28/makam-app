<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Mfa;

use App\Http\Middleware\EnforceMfaChallenge;
use App\Models\User;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentService;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Mfa\Totp\Base32;
use App\Platform\IdentityAccess\Mfa\Totp\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Ad-hoc fixture route, same pattern `RequireRecentAuthenticationMiddlewareTest`
 * already established — this middleware is not attached to any real route in
 * this test file's own registration; Task 3 attaches it to
 * `AdminPanelProvider` for real.
 */
final class EnforceMfaChallengeMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', EnforceMfaChallenge::class])
            ->get('/__test/mfa-guarded', fn () => 'ok');

        Route::get('/__test/mfa-challenge-fixture', fn () => 'challenge-page')
            ->name('filament.admin.pages.mfa-challenge');

        // Same fix `RequireRecentAuthenticationMiddlewareTest` documents:
        // ->name() chained after ->get() does not retroactively index the
        // route in the router's name-lookup table when registered this late
        // (inside a test's own setUp(), long after the one-time boot-time
        // refreshNameLookups() call already fired). Without this, route()
        // throws RouteNotFoundException even though the route above exists.
        app('router')->getRoutes()->refreshNameLookups();
    }

    public function test_a_user_with_no_enrolment_passes_through_untouched(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/__test/mfa-guarded')
            ->assertOk()
            ->assertSee('ok');
    }

    public function test_an_enrolled_user_without_a_session_challenge_is_redirected(): void
    {
        $user = User::factory()->create();
        $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
        $this->confirmEnrolment($enrolment, $user);

        $this->actingAs($user)
            ->get('/__test/mfa-guarded')
            ->assertRedirect(route('filament.admin.pages.mfa-challenge'));
    }

    public function test_a_satisfied_session_flag_lets_an_enrolled_user_through(): void
    {
        $user = User::factory()->create();
        $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
        $this->confirmEnrolment($enrolment, $user);

        session()->put('mfa_challenge_satisfied_at', now()->toIso8601String());

        $this->actingAs($user)
            ->get('/__test/mfa-guarded')
            ->assertOk();
    }

    public function test_a_guest_is_not_redirected_by_this_middleware(): void
    {
        // No auth at all — Filament's own Authenticate middleware is what
        // would normally intercept a guest first; this middleware must not
        // itself assume an authenticated user.
        $this->get('/__test/mfa-guarded')->assertOk();
    }

    private function confirmEnrolment(MfaEnrolment $enrolment, User $user): void
    {
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);
        $code = $totp->generate(Base32::decode($enrolment->secret), time(), $enrolment->digits);

        app(MfaEnrolmentService::class)->confirm(
            $enrolment,
            $code,
            actorRef: $user->id,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );
    }
}

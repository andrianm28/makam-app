<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\PaymentVerification;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentVerificationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The HTTP half of Task 5's AC9 — `RequireRecentAuthentication`'s SECOND
 * real attachment anywhere in this repo, following
 * `DisableMfaControllerTest`'s exact precedent (route name changes only).
 * `VerifyManualPaymentTest` covers the action's own decision/audit/
 * structural behaviour; this file proves the route is genuinely gated by
 * the middleware, not merely that the underlying action works when called
 * directly.
 */
final class VerifyManualPaymentRouteTest extends TestCase
{
    use RefreshDatabase;

    private function submittedVerification(): PaymentVerification
    {
        return PaymentVerification::createSubmitted([
            'reference' => 'order-route-1',
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'TRX-route-1',
            'instructions' => null,
            'submitted_at' => CarbonImmutable::now(),
        ]);
    }

    private function verifyUrl(PaymentVerification $verification): string
    {
        return route('admin.payments.manual-verifications.verify', $verification);
    }

    public function test_verification_without_a_fresh_authentication_redirects_to_the_challenge_and_changes_nothing(): void
    {
        $user = User::factory()->create();
        $verification = $this->submittedVerification();

        // Deliberately NOT creating an actor_sessions row with a recent
        // last_authenticated_at — RequireRecentAuthentication fails closed
        // on a null timestamp, same as DisableMfaControllerTest's own
        // precedent.
        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'approve',
                'reason' => 'Proof matched provider statement',
            ])
            ->assertRedirect(route('filament.admin.pages.mfa-challenge'));

        $verification->refresh();
        $this->assertSame(PaymentVerificationStatus::Submitted, $verification->status());
        $this->assertSame(0, AuditEvent::query()->where('action', PaymentAuditActions::MANUAL_VERIFICATION)->count());
    }

    public function test_verification_with_a_fresh_authentication_actually_verifies(): void
    {
        $user = User::factory()->create();
        $verification = $this->submittedVerification();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subSeconds(10),
        ]);

        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'approve',
                'reason' => 'Proof matched provider statement',
            ])
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $verification->refresh();
        $this->assertSame(PaymentVerificationStatus::Verified, $verification->status());
        $this->assertSame(1, AuditEvent::query()->where('action', PaymentAuditActions::MANUAL_VERIFICATION)->count());
    }

    public function test_a_missing_reason_is_rejected_at_the_http_layer(): void
    {
        $user = User::factory()->create();
        $verification = $this->submittedVerification();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subSeconds(10),
        ]);

        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'approve',
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');

        $verification->refresh();
        $this->assertSame(PaymentVerificationStatus::Submitted, $verification->status());
    }

    public function test_an_invalid_decision_value_is_rejected_at_the_http_layer(): void
    {
        $user = User::factory()->create();
        $verification = $this->submittedVerification();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subSeconds(10),
        ]);

        $this->actingAs($user)
            ->post($this->verifyUrl($verification), [
                'decision' => 'definitely-approve',
                'reason' => 'Some reason',
            ])
            ->assertSessionHasErrors('decision');
    }

    public function test_an_unauthenticated_request_is_refused_by_the_auth_guard(): void
    {
        // No `login` named route exists anywhere in this repo yet (no
        // login UI has been built — same gap DisableMfaControllerTest's
        // suite never exercises either). Laravel's default `Authenticate`
        // middleware's `redirectTo()` calls `route('login')`
        // unconditionally on an unauthenticated request, which throws
        // RouteNotFoundException rather than silently letting the request
        // through — an honest failure, not a bypass. Whichever future task
        // builds the login UI turns this into a real redirect; until then
        // this test pins that the `auth` guard is genuinely evaluated
        // BEFORE this route's own logic ever runs.
        $verification = $this->submittedVerification();

        // Laravel's exception handler renders the RouteNotFoundException as
        // a server error response rather than letting it bubble out of the
        // HTTP kernel — asserted as "definitely not a 2xx success" rather
        // than a specific status, since the exact rendering of a missing
        // named route is a framework/environment detail this test does not
        // own.
        $response = $this->post($this->verifyUrl($verification), [
            'decision' => 'approve',
            'reason' => 'Proof matched provider statement',
        ]);

        $response->assertStatus(500);
        $this->assertSame(PaymentVerificationStatus::Submitted, $verification->fresh()->status());
    }

    public function test_the_route_is_registered_with_the_reauthentication_middleware(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($route) => $route->getName() === 'admin.payments.manual-verifications.verify');

        $this->assertNotNull($route);
        $this->assertTrue(
            collect($route->gatherMiddleware())->contains(
                fn (string $middleware): bool => str_starts_with($middleware, RequireRecentAuthentication::class.':')
            ),
            'admin.payments.manual-verifications.verify must be gated by RequireRecentAuthentication.'
        );
    }

    public function test_deciding_via_http_leaves_payment_sessions_untouched(): void
    {
        $user = User::factory()->create();
        $verification = $this->submittedVerification();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subSeconds(10),
        ]);

        $this->actingAs($user)->post($this->verifyUrl($verification), [
            'decision' => 'approve',
            'reason' => 'Proof matched provider statement',
        ]);

        $this->assertSame(0, PaymentSession::query()->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\Payment\Models\PaymentReversal;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\PaymentAuditActions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The HTTP half of Task 6's safe slice — `RequireRecentAuthentication`'s
 * THIRD real attachment anywhere in this repo, following
 * `VerifyManualPaymentRouteTest`'s exact precedent (route name/shape
 * changes only). `ReversalServiceTest` covers the action's own
 * write/audit/structural behaviour; this file proves the route is
 * genuinely gated by the middleware, not merely that
 * `ReversalService::record()` works when called directly.
 */
final class RecordPaymentReversalRouteTest extends TestCase
{
    use RefreshDatabase;

    private function url(string $reversalType): string
    {
        return route('admin.payments.reversals.record', ['reversalType' => $reversalType]);
    }

    public function test_recording_without_a_fresh_authentication_redirects_to_the_challenge_and_changes_nothing(): void
    {
        $user = User::factory()->create();

        // Deliberately NOT creating an actor_sessions row with a recent
        // last_authenticated_at — RequireRecentAuthentication fails closed
        // on a null timestamp, same as DisableMfaControllerTest's and
        // VerifyManualPaymentRouteTest's own precedent.
        $this->actingAs($user)
            ->post($this->url('refund'), [
                'reference' => 'TRX-route-1',
                'reason' => 'Customer requested a refund',
            ])
            ->assertRedirect(route('filament.admin.pages.mfa-challenge'));

        $this->assertSame(0, PaymentReversal::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', PaymentAuditActions::REFUND)->count());
    }

    public function test_recording_a_refund_with_a_fresh_authentication_actually_records_it(): void
    {
        $user = User::factory()->create();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subSeconds(10),
        ]);

        $this->actingAs($user)
            ->post($this->url('refund'), [
                'reference' => 'TRX-route-2',
                'amount_minor' => 10_000_00,
                'reason' => 'Customer requested a refund',
            ])
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $reversal = PaymentReversal::query()->where('reference', 'TRX-route-2')->first();
        $this->assertNotNull($reversal);
        $this->assertSame(10_000_00, $reversal->amount_minor);
        $this->assertSame(1, AuditEvent::query()->where('action', PaymentAuditActions::REFUND)->count());
    }

    public function test_recording_a_chargeback_with_a_fresh_authentication_actually_records_it(): void
    {
        $user = User::factory()->create();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subSeconds(10),
        ]);

        $this->actingAs($user)
            ->post($this->url('chargeback'), [
                'reference' => 'TRX-route-3',
                'reason' => 'Card issuer disputed the transaction',
            ])
            ->assertRedirect(route('filament.admin.pages.dashboard'));

        $reversal = PaymentReversal::query()->where('reference', 'TRX-route-3')->first();
        $this->assertNotNull($reversal);
        $this->assertSame(1, AuditEvent::query()->where('action', PaymentAuditActions::CHARGEBACK)->count());
    }

    public function test_a_missing_reason_is_rejected_at_the_http_layer(): void
    {
        $user = User::factory()->create();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subSeconds(10),
        ]);

        $this->actingAs($user)
            ->post($this->url('refund'), [
                'reference' => 'TRX-route-4',
                'reason' => '',
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, PaymentReversal::query()->where('reference', 'TRX-route-4')->count());
    }

    public function test_a_missing_reference_is_rejected_at_the_http_layer(): void
    {
        $user = User::factory()->create();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subSeconds(10),
        ]);

        $this->actingAs($user)
            ->post($this->url('refund'), [
                'reason' => 'Some reason',
            ])
            ->assertSessionHasErrors('reference');
    }

    public function test_an_invalid_reversal_type_segment_is_rejected_by_the_route_itself(): void
    {
        $user = User::factory()->create();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subSeconds(10),
        ]);

        $this->actingAs($user)
            ->post('/admin/payments/reversals/reversal', [
                'reference' => 'TRX-route-5',
                'reason' => 'Some reason',
            ])
            ->assertNotFound();
    }

    public function test_a_second_refund_for_the_same_reference_via_http_fails_and_leaves_one_row(): void
    {
        $user = User::factory()->create();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subSeconds(10),
        ]);

        $this->actingAs($user)->post($this->url('refund'), [
            'reference' => 'TRX-route-duplicate',
            'reason' => 'First refund',
        ])->assertRedirect(route('filament.admin.pages.dashboard'));

        $response = $this->actingAs($user)->post($this->url('refund'), [
            'reference' => 'TRX-route-duplicate',
            'reason' => 'Second refund attempt, must not be allowed',
        ]);

        $response->assertStatus(500);
        $this->assertSame(1, PaymentReversal::query()->where('reference', 'TRX-route-duplicate')->count());
    }

    public function test_an_unauthenticated_request_is_refused_by_the_auth_guard(): void
    {
        // Same gap `VerifyManualPaymentRouteTest`'s own precedent
        // documents — no `login` named route exists anywhere in this repo
        // yet, so Laravel's default `Authenticate` middleware's
        // `redirectTo()` throws `RouteNotFoundException` rather than
        // silently letting the request through. Asserted as "definitely
        // not a 2xx success" rather than a specific status, since the
        // exact rendering of a missing named route is a
        // framework/environment detail this test does not own.
        $response = $this->post($this->url('refund'), [
            'reference' => 'TRX-route-6',
            'reason' => 'Some reason',
        ]);

        $response->assertStatus(500);
        $this->assertSame(0, PaymentReversal::query()->where('reference', 'TRX-route-6')->count());
    }

    public function test_the_route_is_registered_with_the_reauthentication_middleware(): void
    {
        $route = collect(app('router')->getRoutes())
            ->first(fn ($route) => $route->getName() === 'admin.payments.reversals.record');

        $this->assertNotNull($route);
        $this->assertTrue(
            collect($route->gatherMiddleware())->contains(
                fn (string $middleware): bool => str_starts_with($middleware, RequireRecentAuthentication::class.':')
            ),
            'admin.payments.reversals.record must be gated by RequireRecentAuthentication.'
        );
    }

    public function test_recording_via_http_leaves_payment_sessions_untouched(): void
    {
        $user = User::factory()->create();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subSeconds(10),
        ]);

        $this->actingAs($user)->post($this->url('refund'), [
            'reference' => 'TRX-route-7',
            'reason' => 'Some reason',
        ]);

        $this->assertSame(0, PaymentSession::query()->count());
    }
}

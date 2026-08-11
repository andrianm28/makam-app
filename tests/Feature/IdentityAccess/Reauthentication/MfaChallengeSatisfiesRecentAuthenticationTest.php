<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Reauthentication;

use App\Filament\Admin\Pages\MfaChallenge;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\User;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentService;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Mfa\Totp\Base32;
use App\Platform\IdentityAccess\Mfa\Totp\Totp;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The regression this file exists for: completing a real step-up challenge
 * used to leave `actor_sessions.last_authenticated_at` untouched, so
 * `RequireRecentAuthentication` re-challenged a stale actor forever — the
 * gate could only be satisfied by a fresh LOGIN, never by re-proving
 * identity. `MfaChallenge::submit()` now refreshes that timestamp and calls
 * `ReauthenticationService::satisfy()` on success.
 *
 * The gated fixture route mirrors
 * `RequireRecentAuthenticationMiddlewareTest`'s setUp (including its
 * `refreshNameLookups()` note) rather than a real sensitive-action route,
 * so this test exercises the middleware contract itself, not one
 * particular caller of it.
 */
final class MfaChallengeSatisfiesRecentAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Stands in for a real per-action reason such as
     * `FinancialLedger\Actions\BulkFinancialExport::REAUTHENTICATION_REASON`
     * — those actions query `reauthentication_events` for a satisfied row
     * carrying their own reason, so the exact string has to survive the trip
     * from the middleware to the challenge page.
     */
    private const string SENSITIVE_REASON = 'bank_account_change';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')
            ->get('/__test/sensitive-action', function () {
                return response()->json(['ok' => true]);
            })
            ->middleware(RequireRecentAuthentication::class.':'.self::SENSITIVE_REASON.',test.reauth.challenge');

        Route::middleware('web')
            ->get('/__test/reauth-challenge', function () {
                return response('challenge-page', 200);
            })
            ->name('test.reauth.challenge');

        app('router')->getRoutes()->refreshNameLookups();
    }

    private function confirmedEnrolmentFor(User $user): MfaEnrolment
    {
        $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);

        return $this->confirm($enrolment, $user)->enrolment;
    }

    private function confirm(MfaEnrolment $enrolment, User $user)
    {
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);
        $code = $totp->generate(Base32::decode($enrolment->secret), time(), $enrolment->digits);

        return app(MfaEnrolmentService::class)->confirm(
            $enrolment,
            $code,
            actorRef: $user->id,
            actorRole: 'authenticated_actor',
            source: AuditSource::Panel,
        );
    }

    /**
     * Same "one period ahead" reasoning `MfaChallengePageTest` documents:
     * confirmation already consumed the current time-step as
     * `last_verified_counter`, so a code for that step is a replay.
     */
    private function currentCodeFor(MfaEnrolment $enrolment, int $stepsAhead = 1): string
    {
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);

        // Based on the Carbon clock, not `time()`, because
        // `MfaChallengeService` verifies against
        // `CarbonImmutable::now()->getTimestamp()` — so a test that travels
        // in time gets a code the service will actually accept.
        return $totp->generate(
            Base32::decode($enrolment->secret),
            CarbonImmutable::now()->getTimestamp() + ($stepsAhead * $enrolment->period_seconds),
            $enrolment->digits,
        );
    }

    private function staleSessionFor(User $user): ActorSession
    {
        return ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'stale-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subHour(),
        ]);
    }

    /**
     * A real request boundary gives every request a brand-new container, so
     * the `scoped()` `ActorContext` binding is re-resolved from the database
     * each time. Laravel's test harness reuses ONE container across every
     * request in a test method, so without this the retry below would read
     * the `ActorContext` cached during the first (stale) request and the
     * assertion would prove nothing about the freshness write.
     */
    private function crossRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
    }

    public function test_a_stale_actor_passes_the_same_gate_after_completing_a_totp_challenge(): void
    {
        $user = User::factory()->create();
        $enrolment = $this->confirmedEnrolmentFor($user);
        $this->staleSessionFor($user);
        $this->actingAs($user);

        $this->get('/__test/sensitive-action')->assertRedirect(route('test.reauth.challenge'));

        $this->crossRequestBoundary();

        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', $this->currentCodeFor($enrolment))
            ->call('submit')
            ->assertRedirect();

        $this->crossRequestBoundary();

        $this->get('/__test/sensitive-action')
            ->assertOk()
            ->assertJson(['ok' => true]);

        // The step-up row must name the panel's guard, the same value the
        // login listener writes from `$event->guard` — the two writers of
        // this column must not drift.
        $this->assertSame(
            'web',
            ActorSession::query()->where('user_id', $user->id)->latest('id')->value('guard'),
        );
    }

    public function test_a_stale_actor_passes_the_same_gate_after_redeeming_a_recovery_code(): void
    {
        $user = User::factory()->create();
        $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
        $recoveryCode = $this->confirm($enrolment, $user)->recoveryCodes[0];
        $this->staleSessionFor($user);
        $this->actingAs($user);

        $this->get('/__test/sensitive-action')->assertRedirect(route('test.reauth.challenge'));

        $this->crossRequestBoundary();

        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', $recoveryCode)
            ->call('submit')
            ->assertRedirect();

        $this->crossRequestBoundary();

        $this->get('/__test/sensitive-action')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_a_successful_challenge_writes_a_satisfied_reauthentication_event(): void
    {
        $user = User::factory()->create();
        $enrolment = $this->confirmedEnrolmentFor($user);
        $this->staleSessionFor($user);

        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', $this->currentCodeFor($enrolment))
            ->call('submit')
            ->assertRedirect();

        $satisfied = ReauthenticationEvent::query()
            ->where('outcome', ReauthenticationOutcome::SATISFIED)
            ->latest('id')
            ->first();

        $this->assertNotNull($satisfied, 'A completed challenge must record a satisfied reauthentication event.');
        $this->assertSame((string) $user->id, $satisfied->actor_ref);
        $this->assertSame(MfaChallenge::REAUTHENTICATION_REASON, $satisfied->reason);
    }

    public function test_the_satisfied_event_carries_the_sensitive_action_that_raised_the_challenge(): void
    {
        $user = User::factory()->create();
        $enrolment = $this->confirmedEnrolmentFor($user);
        $this->staleSessionFor($user);
        $this->actingAs($user);

        $this->get('/__test/sensitive-action')->assertRedirect(route('test.reauth.challenge'));

        $this->crossRequestBoundary();

        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', $this->currentCodeFor($enrolment))
            ->call('submit')
            ->assertRedirect();

        $satisfied = ReauthenticationEvent::query()
            ->where('outcome', ReauthenticationOutcome::SATISFIED)
            ->latest('id')
            ->first();

        $this->assertNotNull($satisfied);
        $this->assertSame(
            self::SENSITIVE_REASON,
            $satisfied->reason,
            'A sensitive action checks reauthentication_events for its OWN reason; a generic one leaves it refused forever.',
        );
    }

    public function test_the_reason_is_single_use_so_one_challenge_proves_one_action(): void
    {
        $user = User::factory()->create();
        $enrolment = $this->confirmedEnrolmentFor($user);
        $this->staleSessionFor($user);
        $this->actingAs($user);

        $this->get('/__test/sensitive-action')->assertRedirect(route('test.reauth.challenge'));

        $this->crossRequestBoundary();

        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', $this->currentCodeFor($enrolment))
            ->call('submit')
            ->assertRedirect();

        // A second challenge nobody was redirected into: the sensitive
        // action's reason must NOT be minted again. Time travel past the
        // step the submission above consumed as `last_verified_counter`,
        // rather than reaching for a code outside the service's own
        // acceptance window.
        $this->travel(2 * $enrolment->period_seconds)->seconds();

        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', $this->currentCodeFor($enrolment, stepsAhead: 0))
            ->call('submit')
            ->assertRedirect();

        $this->assertSame(
            1,
            ReauthenticationEvent::query()
                ->where('outcome', ReauthenticationOutcome::SATISFIED)
                ->where('reason', self::SENSITIVE_REASON)
                ->count(),
            'One challenge must prove exactly one sensitive action.',
        );
        $this->assertSame(
            1,
            ReauthenticationEvent::query()
                ->where('outcome', ReauthenticationOutcome::SATISFIED)
                ->where('reason', MfaChallenge::REAUTHENTICATION_REASON)
                ->count(),
            'The unchallenged second completion falls back to the generic reason.',
        );
    }

    public function test_a_challenge_with_no_sensitive_action_behind_it_records_the_generic_reason(): void
    {
        $user = User::factory()->create();
        $enrolment = $this->confirmedEnrolmentFor($user);
        $this->staleSessionFor($user);

        // No middleware redirect happened — this is the login-time
        // `EnforceMfaChallenge` shape, which guards panel access, not an
        // action.
        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', $this->currentCodeFor($enrolment))
            ->call('submit')
            ->assertRedirect();

        $satisfied = ReauthenticationEvent::query()
            ->where('outcome', ReauthenticationOutcome::SATISFIED)
            ->latest('id')
            ->first();

        $this->assertNotNull($satisfied);
        $this->assertSame(MfaChallenge::REAUTHENTICATION_REASON, $satisfied->reason);
    }

    public function test_a_mistyped_code_leaves_the_reason_intact_for_the_retry(): void
    {
        $user = User::factory()->create();
        $enrolment = $this->confirmedEnrolmentFor($user);
        $this->staleSessionFor($user);
        $this->actingAs($user);

        $this->get('/__test/sensitive-action')->assertRedirect(route('test.reauth.challenge'));

        $this->crossRequestBoundary();

        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', '000000')
            ->call('submit')
            ->assertHasErrors(['code']);

        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', $this->currentCodeFor($enrolment))
            ->call('submit')
            ->assertRedirect();

        $satisfied = ReauthenticationEvent::query()
            ->where('outcome', ReauthenticationOutcome::SATISFIED)
            ->latest('id')
            ->first();

        $this->assertNotNull($satisfied);
        $this->assertSame(self::SENSITIVE_REASON, $satisfied->reason);
    }

    /**
     * KNOWN LIMIT, pinned deliberately rather than described in prose: the
     * reason threading reaches the STALE actor only.
     *
     * `RequireRecentAuthentication::handle()` returns early when the actor is
     * already fresh, before it writes the reason — so no challenge is raised
     * and no per-action `satisfied` row is ever written for a fresh actor. A
     * sensitive action that enforces its own reason-scoped check (L4's
     * `BulkFinancialExport` queries `reauthentication_events` for
     * `reason = 'bulk_financial_export'`) therefore refuses a
     * freshly-logged-in actor, with no way to satisfy it — visiting the
     * challenge page voluntarily mints only the generic reason.
     *
     * Fails closed, so not a security hole; a real functional gap. This test
     * documents the boundary and is EXPECTED TO FAIL, loudly and by design,
     * whoever closes it — see the report's D3 follow-up. Do not "fix" it by
     * relaxing the assertion.
     */
    public function test_a_fresh_actor_is_waved_through_and_therefore_gets_no_per_action_proof(): void
    {
        $user = User::factory()->create();
        $this->confirmedEnrolmentFor($user);

        // Fresh, not stale: the middleware's early return is the path here.
        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'fresh-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subSeconds(10),
        ]);
        $this->actingAs($user);

        $this->get('/__test/sensitive-action')->assertOk();

        $this->assertNull(
            session()->get(RequireRecentAuthentication::REASON_SESSION_KEY),
            'No challenge was raised, so no reason is threaded.',
        );
        $this->assertSame(
            0,
            ReauthenticationEvent::query()->where('reason', self::SENSITIVE_REASON)->count(),
            'A fresh actor produces neither a challenged nor a satisfied row for the sensitive action.',
        );
    }

    public function test_an_invalid_code_refreshes_no_timestamp_and_satisfies_nothing(): void
    {
        $user = User::factory()->create();
        $this->confirmedEnrolmentFor($user);
        $staleSession = $this->staleSessionFor($user);
        $staleAt = $staleSession->last_authenticated_at;
        $this->actingAs($user);

        Livewire::actingAs($user)
            ->test(MfaChallenge::class)
            ->set('code', '000000')
            ->call('submit')
            ->assertHasErrors(['code']);

        $this->assertSame(
            0,
            ReauthenticationEvent::query()->where('outcome', ReauthenticationOutcome::SATISFIED)->count(),
            'A failed challenge must never record a satisfied reauthentication event.',
        );
        $this->assertSame(
            1,
            ActorSession::query()->where('user_id', $user->id)->count(),
            'A failed challenge must not write an actor_sessions row.',
        );
        $this->assertTrue(
            $staleSession->refresh()->last_authenticated_at->equalTo($staleAt),
            'A failed challenge must leave last_authenticated_at untouched.',
        );

        $this->crossRequestBoundary();

        $this->get('/__test/sensitive-action')->assertRedirect(route('test.reauth.challenge'));
    }
}

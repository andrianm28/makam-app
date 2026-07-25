<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Reauthentication;

use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationAuditActions;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Proves `RequireRecentAuthentication` — S3-T3,
 * `platform-identity-and-access` requirements.md AC3 / design.md's
 * "Middleware compares `ActorContext.lastAuthenticatedAt` and challenges
 * when stale."
 *
 * Registers ad-hoc test routes inline, same pattern
 * `AssignCorrelationIdTest` already established, EXCEPT this middleware is
 * deliberately NOT attached to the real `web` group anywhere in this repo
 * (S3-T3's own human-gate constraint) — these routes attach it explicitly,
 * via Laravel's own middleware-parameter syntax
 * (`RequireRecentAuthentication::class.':reason,routeName'`), which is
 * exactly how a future real caller would attach it too.
 */
final class RequireRecentAuthenticationMiddlewareTest extends TestCase
{
    use RefreshDatabase;

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
    }

    private function actorSessionAuthenticatedAt(User $user, CarbonImmutable $lastAuthenticatedAt): ActorSession
    {
        return ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => $lastAuthenticatedAt,
        ]);
    }

    public function test_a_recently_authenticated_actor_passes_through_untouched(): void
    {
        $user = User::factory()->create();
        $this->actorSessionAuthenticatedAt($user, CarbonImmutable::now()->subSeconds(10));
        $this->actingAs($user);

        $response = $this->get('/__test/sensitive-action');

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $this->assertSame(0, ReauthenticationEvent::query()->count(), 'A fresh actor must not raise a reauthentication_events row.');
    }

    public function test_a_null_last_authenticated_at_is_treated_as_stale_not_never_expiring(): void
    {
        $user = User::factory()->create();
        // Deliberately NOT creating an actor_sessions row — the actor has
        // no lastAuthenticatedAt at all.
        $this->actingAs($user);

        $response = $this->get('/__test/sensitive-action');

        $response->assertRedirect(route('test.reauth.challenge'));
        $response->assertSessionHas('url.intended', url('/__test/sensitive-action'));
    }

    public function test_a_stale_last_authenticated_at_redirects_and_preserves_the_intended_url(): void
    {
        $user = User::factory()->create();
        // 900 seconds is the documented default freshness window — 1 hour
        // ago is unambiguously stale under it.
        $this->actorSessionAuthenticatedAt($user, CarbonImmutable::now()->subHour());
        $this->actingAs($user);

        $response = $this->get('/__test/sensitive-action');

        $response->assertRedirect(route('test.reauth.challenge'));
        $response->assertSessionHas('url.intended', url('/__test/sensitive-action'));
    }

    public function test_a_challenge_writes_both_a_reauthentication_event_and_an_audit_event(): void
    {
        $user = User::factory()->create();
        $this->actorSessionAuthenticatedAt($user, CarbonImmutable::now()->subHour());
        $this->actingAs($user);

        $this->get('/__test/sensitive-action');

        $event = ReauthenticationEvent::query()->latest('id')->first();
        $this->assertNotNull($event);
        $this->assertSame(ReauthenticationOutcome::CHALLENGED, $event->outcome);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame(self::SENSITIVE_REASON, $event->reason);

        $auditEvent = AuditEvent::query()->where('action', ReauthenticationAuditActions::CHALLENGED)->latest('id')->first();
        $this->assertNotNull($auditEvent);
        $this->assertSame((string) $event->id, $auditEvent->subject_id);
        $this->assertSame('reauthentication_event', $auditEvent->subject_type);
    }

    public function test_the_freshness_window_is_genuinely_read_from_config_not_hardcoded(): void
    {
        $user = User::factory()->create();
        // 2 minutes ago: fresh under the default 900s window, stale under a
        // tightened 60s window — same underlying timestamp both times, only
        // the config value changes.
        $this->actorSessionAuthenticatedAt($user, CarbonImmutable::now()->subSeconds(120));
        $this->actingAs($user);

        $withDefaultConfig = $this->get('/__test/sensitive-action');
        $withDefaultConfig->assertOk();

        config(['reauthentication.freshness_seconds' => 60]);

        $withTightenedConfig = $this->get('/__test/sensitive-action');
        $withTightenedConfig->assertRedirect(route('test.reauth.challenge'));
    }

    public function test_middleware_is_not_registered_on_the_real_web_group(): void
    {
        // Locks in the human-gate constraint: this class must not appear on
        // the real `web` middleware group, unlike AssignCorrelationId, which
        // deliberately IS registered there.
        $group = app('router')->getMiddlewareGroups()['web'] ?? [];

        $this->assertNotContains(RequireRecentAuthentication::class, $group);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Reauthentication;

use App\Filament\Admin\Pages\PasswordReauthentication;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\User;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\Support\EstablishesFreshActorSession;
use Tests\TestCase;

final class PasswordReauthenticationSatisfiesRecentAuthenticationTest extends TestCase
{
    use EstablishesFreshActorSession;
    use RefreshDatabase;

    private const string SENSITIVE_REASON = 'bank_account_change';

    private const string PASSWORD = 'correct-horse-battery-staple';

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

    /**
     * `forgetScopedInstances()` drops the previous HTTP-test call's `request`
     * binding, matching the "brand new request" boundary these tests are
     * pinning. `Livewire::test()`, unlike a real `->get()`/`->post()`, never
     * runs `StartSession` to re-bind the current session onto whatever
     * `request()` resolves to next, so without the explicit rebind below,
     * `RecordActorSessionAuthentication`'s `$request->hasSession()` check
     * (see that class's own doc block) sees a sessionless request and falls
     * back to a random UUID `session_id` — a real row, but one no
     * subsequent `->get()` call's session id can ever match. In production
     * this never happens: a real Livewire AJAX request runs through the
     * same `web` middleware group as any other request, session included.
     */
    private function crossRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
        $this->app['request']->setLaravelSession($this->app['session.store']);
    }

    public function test_a_stale_actor_passes_the_same_gate_after_a_correct_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt(self::PASSWORD)]);
        $this->actingAsWithSessionAuthenticatedAt($user, CarbonImmutable::now()->subHour())
            ->get('/__test/sensitive-action')
            ->assertRedirect(route('test.reauth.challenge'));

        $this->crossRequestBoundary();

        Livewire::actingAs($user)
            ->test(PasswordReauthentication::class)
            ->set('password', self::PASSWORD)
            ->call('submit')
            ->assertRedirect();

        $this->crossRequestBoundary();

        $this->get('/__test/sensitive-action')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_the_satisfied_event_carries_the_sensitive_action_that_raised_the_challenge(): void
    {
        $user = User::factory()->create(['password' => bcrypt(self::PASSWORD)]);
        $this->actingAsWithSessionAuthenticatedAt($user, CarbonImmutable::now()->subHour())
            ->get('/__test/sensitive-action')
            ->assertRedirect(route('test.reauth.challenge'));

        $this->crossRequestBoundary();

        Livewire::actingAs($user)
            ->test(PasswordReauthentication::class)
            ->set('password', self::PASSWORD)
            ->call('submit')
            ->assertRedirect();

        $satisfied = ReauthenticationEvent::query()
            ->where('outcome', ReauthenticationOutcome::SATISFIED)
            ->latest('id')
            ->first();

        $this->assertNotNull($satisfied);
        $this->assertSame(self::SENSITIVE_REASON, $satisfied->reason);
    }

    public function test_a_wrong_password_writes_no_satisfied_event_and_leaves_the_actor_stale(): void
    {
        $user = User::factory()->create(['password' => bcrypt(self::PASSWORD)]);
        $this->actingAsWithSessionAuthenticatedAt($user, CarbonImmutable::now()->subHour());
        $staleSession = ActorSession::query()->where('session_id', $this->app['session']->getId())->sole();
        $staleAt = $staleSession->last_authenticated_at;

        Livewire::actingAs($user)
            ->test(PasswordReauthentication::class)
            ->set('password', 'not-the-password')
            ->call('submit')
            ->assertHasErrors(['password']);

        $this->assertSame(
            0,
            ReauthenticationEvent::query()->where('outcome', ReauthenticationOutcome::SATISFIED)->count(),
        );
        $this->assertTrue(
            $staleSession->refresh()->last_authenticated_at->equalTo($staleAt),
            'A failed challenge must leave last_authenticated_at untouched.',
        );

        $this->crossRequestBoundary();

        $this->get('/__test/sensitive-action')->assertRedirect(route('test.reauth.challenge'));
    }
}

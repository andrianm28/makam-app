<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Models\User;
use App\Platform\IdentityAccess\Listeners\RecordActorSessionOnLogin;
use App\Platform\IdentityAccess\Models\ActorSession;
use Illuminate\Auth\Events\Login;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * `RecordActorSessionOnLogin` is what makes `actor_sessions` real data
 * instead of an empty table with no writer — see its own doc block. The
 * listener is invoked directly here (bypassing global `event()` dispatch)
 * so the test can control exactly what `Request` the listener sees,
 * including the "no bound session" fallback path documented on the class.
 */
final class RecordActorSessionOnLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_an_actor_session_row_correlated_to_the_frameworks_session_id(): void
    {
        $user = User::factory()->create();

        $store = $this->app->make('session')->driver('array');
        $store->start();

        $request = Request::create('/admin/login');
        $request->setLaravelSession($store);
        $this->app->instance(Request::class, $request);

        (new RecordActorSessionOnLogin($this->app))->handle(new Login('web', $user, false));

        $this->assertDatabaseHas('actor_sessions', [
            'user_id' => $user->id,
            'session_id' => $store->getId(),
            'guard' => 'web',
        ]);

        $row = ActorSession::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNotNull($row->last_authenticated_at);
        $this->assertNull($row->revoked_at);
    }

    public function test_falls_back_to_a_generated_session_id_when_no_http_session_is_bound(): void
    {
        // Documents the deliberate degraded behaviour: a Login event fired
        // outside an HTTP request with a started session (e.g. console
        // context) must not throw and must still produce a row.
        $user = User::factory()->create();

        $request = Request::create('/');
        $this->app->instance(Request::class, $request);

        $this->assertFalse($request->hasSession());

        (new RecordActorSessionOnLogin($this->app))->handle(new Login('web', $user, false));

        $this->assertDatabaseHas('actor_sessions', [
            'user_id' => $user->id,
            'guard' => 'web',
        ]);

        $row = ActorSession::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNotEmpty($row->session_id);
    }

    public function test_repeated_login_for_the_same_session_updates_rather_than_duplicates(): void
    {
        $user = User::factory()->create();

        $store = $this->app->make('session')->driver('array');
        $store->start();

        $request = Request::create('/admin/login');
        $request->setLaravelSession($store);
        $this->app->instance(Request::class, $request);

        $listener = new RecordActorSessionOnLogin($this->app);
        $listener->handle(new Login('web', $user, false));
        $listener->handle(new Login('web', $user, false));

        $this->assertSame(
            1,
            ActorSession::query()
                ->where('user_id', $user->id)
                ->where('session_id', $store->getId())
                ->count()
        );
    }
}

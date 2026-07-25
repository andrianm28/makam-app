<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Models\User;
use App\Platform\IdentityAccess\Listeners\RecordActorSessionOnLogout;
use App\Platform\IdentityAccess\Models\ActorSession;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Logout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * `RecordActorSessionOnLogout` — self-logout bookkeeping only. See the
 * class's own doc block for exactly why this is not AC7 (revoke every
 * session for the actor).
 */
final class RecordActorSessionOnLogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_marks_the_current_sessions_row_revoked(): void
    {
        $user = User::factory()->create();

        $store = $this->app->make('session')->driver('array');
        $store->start();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => $store->getId(),
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now(),
        ]);

        $request = Request::create('/admin/logout');
        $request->setLaravelSession($store);
        $this->app->instance(Request::class, $request);

        (new RecordActorSessionOnLogout($this->app))->handle(new Logout('web', $user));

        $row = ActorSession::query()
            ->where('user_id', $user->id)
            ->where('session_id', $store->getId())
            ->firstOrFail();

        $this->assertNotNull($row->revoked_at);
    }

    public function test_does_not_touch_a_different_users_session(): void
    {
        $loggingOutUser = User::factory()->create();
        $otherUser = User::factory()->create();

        $store = $this->app->make('session')->driver('array');
        $store->start();

        ActorSession::query()->create([
            'user_id' => $otherUser->id,
            'session_id' => 'other-users-session',
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now(),
        ]);

        $request = Request::create('/admin/logout');
        $request->setLaravelSession($store);
        $this->app->instance(Request::class, $request);

        (new RecordActorSessionOnLogout($this->app))->handle(new Logout('web', $loggingOutUser));

        $row = ActorSession::query()->where('user_id', $otherUser->id)->firstOrFail();
        $this->assertNull($row->revoked_at);
    }

    public function test_null_user_on_the_event_is_a_no_op(): void
    {
        $request = Request::create('/admin/logout');
        $this->app->instance(Request::class, $request);

        // Must not throw when $event->user is null (Laravel's Logout event
        // allows this — e.g. logging out an already-guest request).
        (new RecordActorSessionOnLogout($this->app))->handle(new Logout('web', null));

        $this->assertTrue(true);
    }

    public function test_no_bound_session_is_a_no_op(): void
    {
        $user = User::factory()->create();

        ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'some-session',
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now(),
        ]);

        $request = Request::create('/admin/logout');
        $this->app->instance(Request::class, $request);
        $this->assertFalse($request->hasSession());

        (new RecordActorSessionOnLogout($this->app))->handle(new Logout('web', $user));

        $row = ActorSession::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNull($row->revoked_at);
    }
}

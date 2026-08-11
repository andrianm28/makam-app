<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Listeners;

use App\Platform\IdentityAccess\Actions\RecordActorSessionAuthentication;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * Populates `actor_sessions` (this batch's migration) by listening for
 * Laravel's standard `Illuminate\Auth\Events\Login` event, which the
 * session guard dispatches on every successful login — including through
 * Filament's built-in `/admin` login page, since that panel authenticates
 * through the same `web` guard (no distinct `->authGuard()` declared in
 * `AdminPanelProvider`). This batch does not need to own or build a login
 * controller for `actor_sessions` to become real data once someone actually
 * logs in.
 *
 * Registered by `Providers\IdentityAccessServiceProvider`.
 *
 * `session_id` best-effort: if the `Login` event fires without an HTTP
 * request carrying a started session (e.g. a console-context
 * `Auth::login()` call, or this listener under test), there is no framework
 * session id to correlate against, so a random UUID is used instead purely
 * to keep the row identifiable/unique. That means `actor_sessions.session_id`
 * is "best correlation available to the framework `sessions` table", not a
 * guaranteed join key — documented on the migration's `session_id` column
 * too.
 *
 * The write itself lives in `Actions\RecordActorSessionAuthentication`,
 * shared with the step-up challenge path — see that class for why the two
 * must not drift.
 */
final class RecordActorSessionOnLogin
{
    public function __construct(private readonly Application $app) {}

    public function handle(Login $event): void
    {
        $this->app->make(RecordActorSessionAuthentication::class)(
            $event->user->getAuthIdentifier(),
            $event->guard,
            $this->app->make(Request::class),
        );
    }
}

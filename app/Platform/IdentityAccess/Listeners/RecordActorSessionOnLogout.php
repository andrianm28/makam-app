<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Listeners;

use App\Platform\IdentityAccess\Models\ActorSession;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * Marks the current session's `actor_sessions` row revoked on the standard
 * `Illuminate\Auth\Events\Logout` event.
 *
 * IMPORTANT SCOPE NOTE: this is self-logout bookkeeping for ONE row only —
 * the session the actor is actively logging out of. It is deliberately NOT
 * requirements.md AC7 ("WHEN a session is revoked THE SYSTEM SHALL
 * immediately revoke all active sessions for the actor"), which is a later,
 * separately-scoped task line. This listener exists so that:
 *
 * 1. `LocalUsersTableIdentityAccessAdapter` does not report a stale
 *    `lastAuthenticatedAt` from a session the actor already logged out of
 *    (it filters `whereNull('revoked_at')`); and
 * 2. AC7's future revoke-all implementation has real, already-flowing data
 *    in `revoked_at` to build on, rather than a column nothing ever writes.
 */
final class RecordActorSessionOnLogout
{
    public function __construct(private readonly Application $app) {}

    public function handle(Logout $event): void
    {
        if ($event->user === null) {
            return;
        }

        $request = $this->app->make(Request::class);

        if (! $request->hasSession()) {
            return;
        }

        ActorSession::query()
            ->where('user_id', $event->user->getAuthIdentifier())
            ->where('session_id', $request->session()->getId())
            ->whereNull('revoked_at')
            ->update(['revoked_at' => CarbonImmutable::now()]);
    }
}

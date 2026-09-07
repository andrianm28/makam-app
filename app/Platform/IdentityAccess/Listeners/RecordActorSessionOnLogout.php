<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Listeners;

use App\Platform\IdentityAccess\Models\ActorSession;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Logout;

/**
 * Marks this actor's `actor_sessions` rows for `$event->guard` revoked on
 * the standard `Illuminate\Auth\Events\Logout` event.
 *
 * ---------------------------------------------------------------------------
 * SEC-06 — why this revokes by `user_id` + `guard`, not by session id
 * ---------------------------------------------------------------------------
 * This listener used to match on `$request->session()->getId()`, the exact
 * shape `Actions\RecordActorSessionAuthentication` writes. That never
 * worked in the real login flow: `LoginPage::login()` calls
 * `auth()->attempt()` — which fires `Login`, and therefore
 * `RecordActorSessionOnLogin`, BEFORE `session()->regenerate()` runs — so
 * the `actor_sessions` row this batch is trying to revoke was written under
 * the PRE-regeneration session id. By the time `/keluar` fires `Logout`,
 * `$request->session()->getId()` is the POST-regeneration id, which never
 * matches that row. The `where('session_id', ...)` clause below silently
 * matched nothing, `revoked_at` was never set, and
 * `LocalUsersTableIdentityAccessAdapter::resolveLastAuthenticatedAt()` kept
 * treating the logged-out session as live until it aged out of the
 * freshness window on its own — SEC-06.
 *
 * Revoking by `user_id` + `guard` instead sidesteps session-id churn
 * entirely and matches what `LogoutController` actually implements:
 * "logout ends this user's session," not "logout ends the one row that
 * happens to still carry a matching session id." This is also now AC7's
 * ("WHEN a session is revoked THE SYSTEM SHALL immediately revoke all
 * active sessions for the actor") full implementation for the `web` guard
 * the actor is actually logging out of — deliberately not "every guard,"
 * since no cross-guard logout semantics exist in this codebase.
 */
final class RecordActorSessionOnLogout
{
    public function handle(Logout $event): void
    {
        if ($event->user === null) {
            return;
        }

        ActorSession::query()
            ->where('user_id', $event->user->getAuthIdentifier())
            ->where('guard', $event->guard)
            ->whereNull('revoked_at')
            ->update(['revoked_at' => CarbonImmutable::now()]);
    }
}

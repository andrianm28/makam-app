<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Actions;

use App\Platform\IdentityAccess\Models\ActorSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The single source of truth for what an `actor_sessions` freshness write
 * looks like. Two callers need the identical shape and must never drift
 * apart, because `Adapters\LocalUsersTableIdentityAccessAdapter
 * ::resolveLastAuthenticatedAt()` — and therefore
 * `App\Http\Middleware\RequireRecentAuthentication` — reads whatever they
 * write:
 *
 * - `Listeners\RecordActorSessionOnLogin`, on Laravel's `Login` event.
 * - `App\Filament\Admin\Pages\MfaChallenge`, once an actor has actually
 *   re-proved their identity on a step-up challenge.
 *
 * `session_id` best-effort, matching the migration's own column note: when
 * the caller has no started HTTP session (a console-context `Auth::login()`,
 * or a listener under test) a random UUID keeps the row unique rather than
 * pretending to a framework session id it cannot know. One consequence
 * worth stating: a step-up challenge completed AFTER Laravel regenerated
 * the session id at login writes a second row for the same login rather
 * than updating the first. That is harmless — the adapter reads the most
 * recent non-revoked row by `last_authenticated_at`, not a single canonical
 * row per login — but it is why this method is `updateOrCreate` rather than
 * an `update` that would silently write nothing when no row matches.
 */
final class RecordActorSessionAuthentication
{
    public function __invoke(int|string $userId, string $guard, Request $request): ActorSession
    {
        return ActorSession::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'session_id' => $request->hasSession()
                    ? $request->session()->getId()
                    : (string) Str::uuid(),
            ],
            [
                'guard' => $guard,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'last_authenticated_at' => CarbonImmutable::now(),
                'revoked_at' => null,
            ]
        );
    }
}

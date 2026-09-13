<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Actions;

use App\Platform\IdentityAccess\Models\ActorSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * The single source of truth for what an `actor_sessions` freshness write
 * looks like. Two callers need the identical shape and must never drift
 * apart, because `Adapters\LocalUsersTableIdentityAccessAdapter
 * ::resolveLastAuthenticatedAt()` — and therefore
 * `App\Http\Middleware\RequireRecentAuthentication` — reads whatever they
 * write:
 *
 * - `Listeners\RecordActorSessionOnLogin`, on Laravel's `Login` event.
 * - A reauthentication challenge controller, once an actor has actually
 *   re-proved their identity on a step-up challenge.
 *
 * `session_id` best-effort, matching the migration's own column note: even
 * a console-context `Auth::login()` with no real HTTP request anywhere in
 * its lifetime still gets a real (if arbitrary) session driver id — see
 * below. One consequence worth stating: a step-up challenge completed AFTER
 * Laravel regenerated the session id at login writes a second row for the
 * same login rather than updating the first. That is harmless — the
 * adapter reads the most recent non-revoked row by `last_authenticated_at`,
 * not a single canonical row per login — but it is why this method is
 * `updateOrCreate` rather than an `update` that would silently write
 * nothing when no row matches.
 *
 * Reads the session id from the global `session()` helper (`app('session')`
 * ->driver()->getId()`), NOT from `$request->hasSession()`/
 * `$request->session()`, even though the two agree in every real
 * full-stack HTTP request (`StartSession` binds the same driver onto both).
 * They diverge under `Livewire::test()`: Livewire's internal `RequestBroker`
 * dispatches the component update through `withoutMiddleware()`
 * (`Features\SupportTesting\RequestBroker`), so the fresh `Request` object
 * built for that one internal call never runs `StartSession` and
 * `hasSession()` is false on it — even though the SAME underlying session
 * driver (a container singleton, untouched by Livewire's request-swap) is
 * still bound and still carries the real id. A session driver always holds
 * a real id from the moment it is first constructed (`SessionManager
 * ::buildSession()` passes `$id = null` to `Store`'s constructor, which
 * `Store::setId()` turns into a freshly generated one immediately — session
 * `start()` only loads DATA, it does not assign the id), so calling
 * `getId()` unconditionally is always safe: a genuine console/job context
 * with no HTTP request anywhere in its lifetime still gets a real,
 * consistent, if arbitrary, id the first time the driver is resolved,
 * exactly as useful for uniqueness as a random UUID would be. This is what
 * closes finding SEC-05 (6 Sep 2026 audit) end-to-end: a
 * Livewire-driven step-up write (this class's second caller,
 * `PasswordReauthentication::submit()`) now lands on the SAME `session_id`
 * `RequireRecentAuthentication`'s subsequent real HTTP request reads back.
 */
final class RecordActorSessionAuthentication
{
    public function __invoke(int|string $userId, string $guard, Request $request): ActorSession
    {
        return ActorSession::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'session_id' => session()->getId(),
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

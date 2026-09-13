<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Platform\IdentityAccess\Models\ActorSession;
use Carbon\CarbonImmutable;

/**
 * Finding SEC-05 (6 Sep 2026 audit) made `ReauthenticationGuard`/
 * `RequireRecentAuthentication` freshness genuinely session-scoped —
 * `LocalUsersTableIdentityAccessAdapter::resolveLastAuthenticatedAt()` now
 * matches `actor_sessions.session_id` against the REQUEST's real session id,
 * not just the actor's newest row across every session. A raw-HTTP feature
 * test (`$this->get(...)`/`$this->post(...)`) that seeds an `ActorSession`
 * with a made-up `session_id` string no longer satisfies that check, because
 * Laravel's own HTTP test client generates a brand-new session id for every
 * request unless the SAME session cookie is carried forward explicitly —
 * confirmed empirically: `$this->actingAs($user)` alone does not make a
 * subsequent `$this->get()` reuse any particular session id.
 *
 * `Livewire::test(...)` calls are NOT affected by this — they run within
 * the test's already-bound `$this->app['session']` instance directly,
 * without generating a fresh HTTP request/session each call, so the older
 * `ActorSession::create(['session_id' => 'test-session-'.$user->id, ...])`
 * fixture (seeded from the SAME test method) already worked, and keeps
 * working, without using this trait — see `CertificateAdminTest` for that
 * still-valid pattern.
 *
 * Use this trait ONLY for tests that make a real HTTP request through the
 * `web` middleware group and need `ReauthenticationGuard`/
 * `RequireRecentAuthentication` to see a genuinely fresh (or, when given a
 * past timestamp, genuinely stale) session for that exact request.
 */
trait EstablishesFreshActorSession
{
    /**
     * Logs `$user` in, starts and captures a real session id, seeds an
     * `actor_sessions` row keyed to THAT id, and returns `$this` primed
     * with the matching session cookie so the next `$this->get()`/
     * `$this->post()`/etc. call in the chain reuses it. Pass a past
     * `$lastAuthenticatedAt` to test the stale-session path instead.
     */
    protected function actingAsWithSessionAuthenticatedAt(User $user, ?CarbonImmutable $lastAuthenticatedAt = null): static
    {
        $this->actingAs($user);
        $this->startSession();

        $sessionId = $this->app['session']->getId();

        ActorSession::query()->updateOrCreate(
            ['user_id' => $user->id, 'session_id' => $sessionId],
            [
                'guard' => 'web',
                'last_authenticated_at' => $lastAuthenticatedAt ?? CarbonImmutable::now(),
                'revoked_at' => null,
            ],
        );

        return $this->withCookie(config('session.cookie'), $sessionId);
    }
}

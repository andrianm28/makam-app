<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Adapters;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Contracts\IdentityAccessAdapter;
use App\Platform\IdentityAccess\Models\ActorSession;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * The MVP/local-auth `IdentityAccessAdapter` implementation — explicitly
 * NOT a claim about what the real K1/K2 contract looks like. Backed by:
 *
 * - the existing `users` table (Laravel's stock Authenticatable / session
 *   guard) for identity presence, matching AC1 ("same-origin session
 *   authentication for MVP ... SHALL NOT issue a token to a browser for
 *   first-party use");
 * - the `actor_sessions` table (this batch's migration) for
 *   `lastAuthenticatedAt`.
 *
 * `roles` and `scopes` are always returned empty — see `ActorContext`'s
 * class-level doc for exactly why (no owning table exists for local roles;
 * scope assignment is Batch 3.2 Agent C's job). This is a real, flagged gap
 * in this batch's output, not a silent omission.
 *
 * When a real K1/K2 contract exists, replace the container binding for
 * `IdentityAccessAdapter` in `Providers\IdentityAccessServiceProvider` with
 * a new implementation. Every consumer that depends on the interface (not
 * this class) is unaffected.
 */
final class LocalUsersTableIdentityAccessAdapter implements IdentityAccessAdapter
{
    public function resolveActorContext(?Authenticatable $identity): ActorContext
    {
        if ($identity === null) {
            return ActorContext::guest();
        }

        return new ActorContext(
            identityReference: $this->normalizeIdentifier($identity->getAuthIdentifier()),
            roles: [],
            scopes: [],
            mfaState: ActorContext::MFA_STATE_NOT_IMPLEMENTED,
            lastAuthenticatedAt: $this->resolveLastAuthenticatedAt($identity),
        );
    }

    private function normalizeIdentifier(mixed $identifier): int|string
    {
        return is_int($identifier) ? $identifier : (string) $identifier;
    }

    /**
     * Most recent non-revoked `actor_sessions` row for this identity.
     *
     * This will be `null` for every actor until whatever future batch adds
     * a login controller/flow that runs inside a real HTTP request — this
     * batch's own `Listeners\RecordActorSessionOnLogin` populates the table
     * on the standard `Illuminate\Auth\Events\Login` event (which Filament's
     * built-in `/admin` login page already dispatches, since it
     * authenticates through the same `web` guard), so it does become
     * populated as soon as anyone actually logs in through that panel — but
     * no login flow has been exercised by this batch itself. Flagged in the
     * batch report as NOT TESTED for that reason.
     */
    private function resolveLastAuthenticatedAt(Authenticatable $identity): ?CarbonImmutable
    {
        $timestamp = ActorSession::query()
            ->where('user_id', $identity->getAuthIdentifier())
            ->whereNull('revoked_at')
            ->orderByDesc('last_authenticated_at')
            ->value('last_authenticated_at');

        if ($timestamp === null) {
            return null;
        }

        return $timestamp instanceof CarbonImmutable
            ? $timestamp
            : CarbonImmutable::parse($timestamp);
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Adapters;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Contracts\IdentityAccessAdapter;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Roles\ActorRoleReader;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentReader;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

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
 * - the `actor_role_assignments` table, via `Roles\ActorRoleReader`, for
 *   `roles`.
 * - the `scope_assignments` table, via `Scopes\ScopeAssignmentReader`, for
 *   `scopes`.
 *
 * `roles` and `scopes` now resolve to REAL, live grant data — lane L5
 * (`docs/superpowers/plans/2026-08-11-platform-identity-seam.md`, Task 3)
 * replaced the permanent `roles: []` / `scopes: []` placeholders this class
 * used to hardcode unconditionally. This is the change that flips five
 * previously-inert authorizers (`FinancialLedger`'s four authorizers,
 * `DocumentVault\Policies\DocumentAccessPolicy`) from unconditionally
 * denying to actually enforcing — see the design doc's "Blast radius"
 * section. An empty roles/scopes list is still a fully legitimate result:
 * it means "this actor holds no grants today," never "no roles required."
 *
 * Both readers are constructor-injected with **zero dependencies of their
 * own**, and neither may ever depend on `ActorContext`. This class's own
 * dependency graph feeds `ActorContextResolver`, which resolves
 * `ActorContext` itself — anything in that graph depending back on
 * `ActorContext` would close a container cycle. That was verified
 * empirically to recurse unboundedly (~1GB RSS) rather than raise
 * `CircularDependencyException`; see the design doc, decision 4, and
 * `Scopes\ScopeAssignmentReader`'s own doc block. `Scopes
 * \ScopeAssignmentResolver` is NOT usable here for exactly that reason — it
 * takes an `ActorContext`.
 *
 * When a real K1/K2 contract exists, replace the container binding for
 * `IdentityAccessAdapter` in `Providers\IdentityAccessServiceProvider` with
 * a new implementation. Every consumer that depends on the interface (not
 * this class) is unaffected.
 */
final class LocalUsersTableIdentityAccessAdapter implements IdentityAccessAdapter
{
    public function __construct(
        private readonly ActorRoleReader $roles = new ActorRoleReader,
        private readonly ScopeAssignmentReader $scopes = new ScopeAssignmentReader,
        private readonly Request $request = new Request,
    ) {}

    public function resolveActorContext(?Authenticatable $identity): ActorContext
    {
        if ($identity === null) {
            return ActorContext::guest();
        }

        $identifier = $this->normalizeIdentifier($identity->getAuthIdentifier());
        $sessionId = $this->currentSessionId();

        return new ActorContext(
            identityReference: $identifier,
            roles: $this->roles->rolesForActor($identifier),
            scopes: $this->scopes->scopeStringsForActor($identifier),
            lastAuthenticatedAt: $this->resolveLastAuthenticatedAt($identity, $sessionId),
            sessionId: $sessionId,
        );
    }

    /**
     * `null` when there is no real, started HTTP session to scope against
     * (a console/job context, or a plain unit test constructing this
     * adapter directly with its default bare `Request`) — matching the
     * same "best-effort, framework session id or nothing" convention
     * `Actions\RecordActorSessionAuthentication` already established for
     * WRITING this same column.
     */
    private function currentSessionId(): ?string
    {
        return $this->request->hasSession() ? $this->request->session()->getId() : null;
    }

    private function normalizeIdentifier(mixed $identifier): int|string
    {
        return is_int($identifier) ? $identifier : (string) $identifier;
    }

    /**
     * Most recent non-revoked `actor_sessions` row for this identity —
     * scoped to THIS session when one is known (finding SEC-05, 6 Sep 2026
     * audit).
     *
     * Before this fix, the query took the max `last_authenticated_at`
     * across EVERY non-revoked session the actor holds, so a step-up
     * challenge completed on one browser/device silently re-armed the
     * 15-minute freshness window for a DIFFERENT, older, unattended session
     * belonging to the same admin — the freshness proof and the session
     * making the request were different things. Scoping by `session_id`
     * (the same column `Actions\RecordActorSessionAuthentication` writes,
     * keyed off the identical `$request->session()->getId()`) closes that
     * gap for any real HTTP request, where a session id is always known.
     *
     * The `$sessionId === null` branch preserves the original cross-session
     * lookup for the one legitimate case it still applies to: a
     * console/job context with no HTTP session at all, where "which
     * session" has no meaning — including this adapter's own pre-existing
     * unit tests, which deliberately exercise cross-session precedence with
     * no session in play.
     */
    private function resolveLastAuthenticatedAt(Authenticatable $identity, ?string $sessionId): ?CarbonImmutable
    {
        $query = ActorSession::query()
            ->where('user_id', $identity->getAuthIdentifier())
            ->whereNull('revoked_at');

        if ($sessionId !== null) {
            $query->where('session_id', $sessionId);
        }

        $timestamp = $query->orderByDesc('last_authenticated_at')->value('last_authenticated_at');

        if ($timestamp === null) {
            return null;
        }

        return $timestamp instanceof CarbonImmutable
            ? $timestamp
            : CarbonImmutable::parse($timestamp);
    }
}

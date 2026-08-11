<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Adapters;

use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Contracts\IdentityAccessAdapter;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Roles\ActorRoleReader;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentReader;
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
 * `mfaState` (S3-T2 addition, `resolveMfaState()` below) IS now real,
 * queried from `Mfa\Models\MfaEnrolment` — the one field this class no
 * longer hardcodes. See `ActorContext`'s class-level doc block for the
 * exact vocabulary and, just as importantly, for what this field still
 * does NOT claim (enrolment status, not per-session verification).
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
    ) {}

    public function resolveActorContext(?Authenticatable $identity): ActorContext
    {
        if ($identity === null) {
            return ActorContext::guest();
        }

        $identifier = $this->normalizeIdentifier($identity->getAuthIdentifier());

        return new ActorContext(
            identityReference: $identifier,
            roles: $this->roles->rolesForActor($identifier),
            scopes: $this->scopes->scopeStringsForActor($identifier),
            mfaState: $this->resolveMfaState($identity),
            lastAuthenticatedAt: $this->resolveLastAuthenticatedAt($identity),
        );
    }

    /**
     * S3-T2 addition: the most recent NON-REVOKED `mfa_enrolments` row for
     * this identity determines the reported state — `ActorContext`'s own
     * class-level doc block has the full vocabulary and, importantly, what
     * it does NOT claim (enrolment status only, never "verified this
     * session"). `Mfa\MfaEnrolmentService::startEnrolment()` guarantees at
     * most one non-revoked row per user at a time (it revokes any prior
     * one before creating a new pending row), so `latest('id')->first()`
     * here is a defensive tie-breaker, not load-bearing for correctness.
     *
     * This method ONLY reads; it never creates, confirms, or revokes an
     * enrolment, and nothing here changes what `canAccessPanel()` or any
     * other authorization decision does today — see this batch's own
     * safety constraint.
     */
    private function resolveMfaState(Authenticatable $identity): string
    {
        $enrolment = MfaEnrolment::query()
            ->where('user_id', $identity->getAuthIdentifier())
            ->where('status', '!=', MfaEnrolmentStatus::REVOKED)
            ->latest('id')
            ->first();

        if ($enrolment === null) {
            return ActorContext::MFA_STATE_NOT_ENROLLED;
        }

        return $enrolment->isConfirmed()
            ? ActorContext::MFA_STATE_ENROLLED
            : ActorContext::MFA_STATE_ENROLMENT_PENDING;
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

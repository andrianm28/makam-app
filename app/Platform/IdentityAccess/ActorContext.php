<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess;

use Carbon\CarbonImmutable;

/**
 * The single per-request source of truth for "who is acting and what do
 * they carry" — `platform-identity-and-access` design.md's `ActorContext`
 * shape, resolving requirements.md AC8: "resolve actor context (identity,
 * roles, scopes) once per request as the single source consumers read."
 *
 * This is a plain immutable value object, not an Eloquent model and not a
 * cache of the identity master. AC12 / design.md: "Identity itself is NOT
 * mastered here; only references and platform-local authorization state" —
 * `$identityReference` is a pointer into wherever identity actually lives
 * (the local `users` table for this batch's MVP adapter; K1/K2 for a future
 * one), never a duplicated identity record.
 *
 * Construct this ONLY via an `IdentityAccessAdapter` implementation, called
 * through `ActorContextResolver` (which caches the result for the rest of
 * the request/job — see that class for the exact mechanism). Never
 * construct it ad hoc in a controller, Livewire component, or Filament
 * Resource, and never re-derive an authorization decision from raw request
 * state instead of reading this object — requirements.md's Negative
 * criteria: "No authorization decision taken in a Blade view or Filament
 * Resource; policies and scopes only."
 *
 * ---------------------------------------------------------------------------
 * What each field is backed by today
 * ---------------------------------------------------------------------------
 * - `$identityReference`, `$lastAuthenticatedAt` — populated for real, from
 *   the local `users` table and the `actor_sessions` table respectively.
 * - `$roles` — populated for real as of lane L5
 *   (`docs/superpowers/plans/2026-08-11-platform-identity-seam.md`, Task 3):
 *   `Adapters\LocalUsersTableIdentityAccessAdapter` resolves this actor's
 *   active (non-revoked) `actor_role_assignments` rows via
 *   `Roles\ActorRoleReader`, ordered by `Roles\ActorRole::KNOWN_ROLES`
 *   declaration order (most privileged first) rather than database
 *   insertion order — see that reader's own doc block for why the order is
 *   deterministic on purpose. **An empty list is a fully legitimate,
 *   common result — it means "this actor holds no role grants" and must
 *   NEVER be read by a caller as "no roles required."** Nothing in this
 *   module grants a role through any application surface yet; today the
 *   only way an `actor_role_assignments` row exists is a hand-written
 *   insert. A later lane task adds an audited console grant path.
 * - `$scopes` — populated for real, from `scope_assignments` via
 *   `Scopes\ScopeAssignmentReader::scopeStringsForActor()`, formatted as
 *   `"entity_type:entity_id"` strings. Same "empty is legitimate, not an
 *   error" caveat as `$roles` above applies here too.
 * - `$mfaState` — S3-T2 (`app/Platform/IdentityAccess/Mfa/**`) wired this
 *   to a real value read from `Mfa\Models\MfaEnrolment`, replacing the
 *   permanent `MFA_STATE_NOT_IMPLEMENTED` placeholder this doc block used
 *   to describe. See the four `MFA_STATE_*` constants below for the exact
 *   vocabulary and `Adapters\LocalUsersTableIdentityAccessAdapter
 *   ::resolveMfaState()` for how it is derived.
 *
 *   IMPORTANT — what this field still does NOT mean, even now that it is
 *   real: it reports ENROLMENT STATUS ("does this actor have a confirmed
 *   TOTP enrolment on file"), never "has this actor completed a
 *   challenge THIS SESSION." Tracking a per-session verified-this-request
 *   marker would require a login-time challenge step wired into the
 *   authentication flow — exactly the enforcement mechanism S3-T2's own
 *   safety constraint forbids this batch from building (a human enables
 *   mandatory MFA and the challenge-at-login flow later). Reporting a
 *   fabricated "verified this session" value with no real mechanism behind
 *   it would be worse than not reporting it at all — the same reasoning
 *   this doc block already applied to `$roles`/`$scopes` above. A future
 *   batch that adds a real login-time challenge is expected to extend this
 *   vocabulary (or add a separate field) once that mechanism exists, not
 *   before.
 */
final class ActorContext
{
    /**
     * No identity reference at all — an unauthenticated (guest) request.
     */
    public const string MFA_STATE_NOT_APPLICABLE = 'not_applicable';

    /**
     * An authenticated actor with no non-revoked `mfa_enrolments` row at
     * all. The honest default for every actor until they start enrolling —
     * NEVER treat this as "MFA satisfied."
     */
    public const string MFA_STATE_NOT_ENROLLED = 'not_enrolled';

    /**
     * A pending `mfa_enrolments` row exists (a secret was generated) but
     * the actor has not yet proven possession of it with one valid TOTP
     * code (`Mfa\MfaEnrolmentService::confirm()`). Not yet a trusted
     * enrolment — equivalent in spirit to the old NOT_IMPLEMENTED
     * placeholder's "do not treat as satisfied" warning, but now backed by
     * a real, queryable reason rather than "the subsystem does not exist."
     */
    public const string MFA_STATE_ENROLMENT_PENDING = 'enrolment_pending';

    /**
     * A confirmed `mfa_enrolments` row exists. See the class-level note
     * above for exactly what this does and does not claim — it is
     * enrolment status, not per-session verification.
     */
    public const string MFA_STATE_ENROLLED = 'enrolled';

    /**
     * @param  int|string|null  $identityReference  Reference to the actor's
     *                                              identity — the local `users.id` for this batch's MVP
     *                                              adapter. `null` means an unauthenticated (guest) request.
     *                                              Typed `int|string` rather than plain `int` because a future
     *                                              K1/K2-backed `IdentityAccessAdapter` implementation may
     *                                              reference identity by an external string id rather than the
     *                                              local autoincrement primary key; widening the type now avoids
     *                                              a breaking change to every consumer later.
     * @param  list<string>  $roles  This actor's active `actor_role_assignments` roles — see the
     *                               class-level note. An empty array means "no roles granted", not
     *                               "no roles required."
     * @param  list<string>  $scopes  This actor's active `scope_assignments` grants, as
     *                                `"entity_type:entity_id"` strings — see the class-level note.
     *                                An empty array means "no scopes granted", not "no scope
     *                                required."
     */
    public function __construct(
        public readonly int|string|null $identityReference,
        public readonly array $roles = [],
        public readonly array $scopes = [],
        public readonly string $mfaState = self::MFA_STATE_NOT_APPLICABLE,
        public readonly ?CarbonImmutable $lastAuthenticatedAt = null,
    ) {}

    /**
     * The unauthenticated actor context — every field at its "nothing to
     * report" value. Used for public/guest requests and as the safe
     * fallback when no user is resolved on the guard.
     */
    public static function guest(): self
    {
        return new self(identityReference: null);
    }

    public function isAuthenticated(): bool
    {
        return $this->identityReference !== null;
    }

    /**
     * `true` iff `$role` is present in `$roles` — see the class-level note
     * on what an empty `$roles` list does and does not mean.
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * `true` iff `$scope` is present in `$scopes` — see the class-level
     * note on what an empty `$scopes` list does and does not mean.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}

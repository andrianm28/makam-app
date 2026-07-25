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
 * What is and is not populated by THIS batch (S3-T1, AC1 + AC8 only)
 * ---------------------------------------------------------------------------
 * - `$identityReference`, `$lastAuthenticatedAt` — populated for real, from
 *   the local `users` table and the `actor_sessions` table respectively.
 * - `$scopes` — always `[]`. Batch 3.2 Agent C builds the scope-assignment
 *   model (requirements.md AC5); this field exists now so nothing consuming
 *   `ActorContext` today has to change shape when that lands.
 * - `$roles` — always `[]` too, for a reason worth flagging explicitly
 *   rather than leaving implicit: design.md's own data list for this spec
 *   names no local "roles" table at all (`overview.md` §5 places roles
 *   inside "K1/K2 identity, actor context, roles, record scopes" — i.e.
 *   role membership is expected to come from the external identity
 *   contract, AC12: "map roles to K1/K2"). K1/K2's real interface has not
 *   been seen (tasks.md's own NOT TESTED note), and this repo has no local
 *   substitute table for roles that any spec has actually authorized this
 *   batch to build. Rather than invent an unspecified `users.role` column
 *   or a parallel roles table with no owning spec, this batch leaves
 *   `$roles` empty and reports the gap. See this repo's Batch 3.1 report
 *   for the explicit callout — any consumer that needs role-based checks
 *   before that gap is closed must not silently treat an empty roles list
 *   as "no roles required."
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
     * @param  list<string>  $roles  See the class-level note: always empty
     *                               today, not yet backed by any table this batch is authorized
     *                               to build.
     * @param  list<string>  $scopes  Always empty today — Batch 3.2 Agent C
     *                                builds real scope assignment (AC5). The field exists so the
     *                                shape does not change when that lands.
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
     * Always `false` today because `$roles` is always empty — see the
     * class-level note. Exists so callers can be written against the final
     * shape now rather than rewritten once role resolution exists.
     */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->roles, true);
    }

    /**
     * Always `false` today because `$scopes` is always empty until Batch
     * 3.2 Agent C. Exists for the same forward-compatibility reason as
     * `hasRole()`.
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }
}

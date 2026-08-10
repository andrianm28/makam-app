<?php

declare(strict_types=1);

namespace App\Platform\DocumentVault\Policies;

use App\Platform\DocumentVault\Models\Document;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

/**
 * AC9: "role AND record relationship both required." Neither half alone ever
 * returns `true`, and there is no branch that short-circuits the relationship
 * check for a privileged role — an `admin` with no grant on the record is
 * refused exactly like a customer with no grant.
 *
 * ---------------------------------------------------------------------------
 * Fail-closed on an empty `ActorContext::$roles`
 * ---------------------------------------------------------------------------
 * `ActorContext::$roles` is still always `[]` in production today (that class
 * documents why: no local roles table exists and the K1/K2 role contract has
 * not been seen), and its own doc block warns that "any consumer that needs
 * role-based checks before that gap is closed must not silently treat an
 * empty roles list as 'no roles required.'" This class honours that: an empty
 * roles list fails `hasPermittedRole()` and therefore denies. The practical
 * consequence — which the coordinator should read as a real, deliberate gate,
 * not an oversight — is that until role resolution is wired, this policy
 * denies EVERY document access in production. That is the correct direction
 * to fail for restricted files.
 *
 * ---------------------------------------------------------------------------
 * Where "record relationship" comes from
 * ---------------------------------------------------------------------------
 * `documents.owner_type`/`owner_id` is a polymorphic record reference, not a
 * foreign key. Two relationship sources are recognised, and nothing else:
 *
 *  1. `owner_type === 'actor'` — the document belongs to the acting identity
 *     itself (a customer's own KTP). The relationship is the identity match.
 *  2. `owner_type` is one of `ScopeEntityType::KNOWN_TYPES` ('order', 'case',
 *     'cemetery', 'vendor', 'grave', 'business_entity') — the relationship is
 *     an ACTIVE (non-revoked) `scope_assignments` row for that exact entity
 *     type and id, read through `ScopeAssignmentResolver::grantedEntityIds()`.
 *     That resolver is the only reverse scope lookup that exists on this
 *     branch.
 *
 * Any other `owner_type` (for example the `booking_draft` value the upload
 * tests use) has NO relationship source this module can consult and is
 * therefore denied. That is deliberate: inventing a cross-module query into
 * `app/Domain/Booking/**` from a platform module would both break the layering
 * and guess at an ownership rule no spec has stated. A consuming spec that
 * needs its own owner type readable must either store the document against
 * the scoped entity or land its relationship source here explicitly — flagged
 * in `task-6-report.md`.
 */
final readonly class DocumentAccessPolicy
{
    /**
     * `documents.owner_type` value meaning "owned by the acting identity
     * itself", matched against `ActorContext::$identityReference`.
     */
    public const string OWNER_TYPE_ACTOR = 'actor';

    /**
     * The roles `rbac-matrix.md`'s "Restricted documents" row gives any path
     * to a restricted document at all, in the precedence order
     * `auditRoleFor()` reports.
     *
     * Every one of them is qualified in that row rather than unconditional
     * ("Own/purpose", "Explicit need only", "No default", "Authorized"), which
     * is exactly why membership here is only the FIRST half of the check — the
     * record relationship supplies the "own"/"assigned"/"explicit need" part.
     *
     * Deliberately absent, and both are one-line additions once a real role
     * vocabulary exists: `case_manager` (the matrix gives it
     * "Assigned/purpose", so it arguably belongs, but no role vocabulary in
     * this repository defines the string and this task's brief names only
     * these four) and `finance`/`issuer`/`auditor` (the matrix gives that
     * column "No default"). Under-permitting is the safe direction to guess.
     *
     * @var list<string>
     */
    public const array ROLES_WITH_RESTRICTED_DOCUMENT_ACCESS = [
        'admin',
        'operator',
        'vendor',
        'customer',
    ];

    /**
     * `document_access_events.actor_role`/`audit_events.actor_role` value for
     * an authenticated actor carrying no role this module recognises. Reuses
     * the string `Http\Controllers\Admin\DisableMfaController` already
     * established for the same "authenticated, role not otherwise known"
     * situation.
     */
    private const string ROLE_AUTHENTICATED_ACTOR = 'authenticated_actor';

    /**
     * Value for an unauthenticated actor — the string
     * `Domain\Booking\Actions\StartBookingDraft` already uses for a guest.
     */
    private const string ROLE_GUEST = 'guest';

    public function __construct(
        private ScopeAssignmentResolver $scopeAssignments,
    ) {}

    public function canView(ActorContext $actor, Document $document): bool
    {
        if (! $actor->isAuthenticated()) {
            return false;
        }

        if (! $this->hasPermittedRole($actor)) {
            return false;
        }

        return $this->hasRecordRelationship($actor, $document);
    }

    /**
     * The single deterministic `actor_role` for audit and access rows.
     * `document_access_events.actor_role` is NOT NULL and `ActorContext` has
     * no single "the actor's role" field, so one is derived here.
     *
     * Determinism matters for evidence review: the same actor must always
     * produce the same recorded role. Reading `$roles[0]` would not — the
     * array's order is whatever the identity adapter happened to emit. This
     * walks `ROLES_WITH_RESTRICTED_DOCUMENT_ACCESS` in its own fixed
     * precedence order and reports the first match (most privileged first),
     * falling back to `authenticated_actor`/`guest`.
     */
    public static function auditRoleFor(ActorContext $actor): string
    {
        if (! $actor->isAuthenticated()) {
            return self::ROLE_GUEST;
        }

        foreach (self::ROLES_WITH_RESTRICTED_DOCUMENT_ACCESS as $role) {
            if ($actor->hasRole($role)) {
                return $role;
            }
        }

        return self::ROLE_AUTHENTICATED_ACTOR;
    }

    private function hasPermittedRole(ActorContext $actor): bool
    {
        foreach (self::ROLES_WITH_RESTRICTED_DOCUMENT_ACCESS as $role) {
            if ($actor->hasRole($role)) {
                return true;
            }
        }

        return false;
    }

    private function hasRecordRelationship(ActorContext $actor, Document $document): bool
    {
        $ownerType = (string) $document->owner_type;
        $ownerId = (string) $document->owner_id;
        $actorIdentifier = $actor->identityReference;

        if ($actorIdentifier === null || $ownerId === '') {
            return false;
        }

        if ($ownerType === self::OWNER_TYPE_ACTOR) {
            return hash_equals((string) $actorIdentifier, $ownerId);
        }

        if (! ScopeEntityType::isKnown($ownerType)) {
            return false;
        }

        $grantedEntityIds = array_map(
            static fn (mixed $entityId): string => (string) $entityId,
            $this->scopeAssignments->grantedEntityIds($actorIdentifier, $ownerType),
        );

        return in_array($ownerId, $grantedEntityIds, true);
    }
}

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
     * The roles permitted any path to a restricted document at all, in the
     * precedence order `auditRoleFor()` reports.
     *
     * ONE RULE, applied consistently (user ruling, 10 Aug 2026):
     * **membership requires an affirmative or qualified grant in
     * `docs/security/rbac-matrix.md`'s "Restricted documents" row
     * (`rbac-matrix.md:12`); a cell reading "No default" means EXCLUDED from
     * this allow-list.** Both consequences of that single rule:
     *
     *  - `vendor` is EXCLUDED. That row gives the Vendor column "No default"
     *    — the identical value it gives Finance/Issuer/Auditor. Admitting one
     *    while excluding the other on the same cell value was the
     *    inconsistency this list previously carried.
     *  - `finance`/`issuer`/`auditor` stay EXCLUDED, on exactly that same
     *    "No default" basis rather than a separate judgement.
     *  - `case_manager` is INCLUDED. That row gives Case Manager
     *    "Assigned/purpose", an affirmative grant, and the "assigned" half is
     *    supplied by the record-relationship check below.
     *  - `admin` ("Authorized"), `operator` ("Explicit need only") and
     *    `customer` ("Own/purpose") are INCLUDED as affirmative or qualified
     *    grants.
     *
     * Membership here is only ever the FIRST half of the check. Every
     * included cell is qualified rather than unconditional
     * ("Own/purpose", "Assigned/purpose", "Explicit need only"), and the
     * record relationship supplies the "own"/"assigned"/"explicit need" part
     * — which is why no role, `admin` included, reaches a document without
     * one.
     *
     * KNOWN ACCEPTED SIMPLIFICATION (ruled, do not build machinery for it):
     * the row's finer per-role distinctions are deliberately FLATTENED into
     * this one role+relationship gate. Operator's "Explicit need only" and
     * Admin's "Authorized" are different strengths of grant in the matrix but
     * are treated identically here. Distinguishing them needs a real
     * authorization model (per-purpose grants, need-to-know justification
     * capture) that neither K1/K2 nor this repository provides yet. Revisit
     * when it does; do not approximate it in the meantime.
     *
     * Least-privilege was chosen deliberately where the matrix is ambiguous:
     * it is v0.2 and states outright "Exact roles depend on K1/K2", so this
     * list is meant to be WIDENED once real roles land, never quietly relied
     * on as complete.
     *
     * @var list<string>
     */
    public const array ROLES_WITH_RESTRICTED_DOCUMENT_ACCESS = [
        'admin',
        'operator',
        'case_manager',
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

    /**
     * Purpose is deliberately NOT enforced here — the issuing Action owns it;
     * see `Actions\IssueSignedUrl`, which scopes each grant to a single
     * `DocumentAccessPurpose`. This policy answers only "may this actor reach
     * this record at all"; the split across two layers is intentional, not an
     * omission.
     */
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

<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

/**
 * A plain value object identifying WHAT `RecipientResolver::resolve()`
 * should resolve recipients for — mirrors `App\Platform\Audit\AuditSubject`
 * deliberately: it names the record's owner and scope entity/entities,
 * never carries the record's own field values.
 *
 * `Notification` is a Tier 2 platform foundation and `app/Platform/README.md`
 * states the dependency rule the other way around — "a feature module
 * consumes a platform foundation and must never redefine one" — so
 * `RecipientResolver` must never accept an `app/Domain/**` Eloquent model
 * (e.g. `BookingDraft`) directly; that would make a platform foundation
 * depend on a feature module. Callers in `app/Domain/**` build one of these
 * from their own record instead (e.g. `new RecipientResolutionSubject(
 * ownerRef: $draft->user_id, scopeEntityType: ScopeEntityType::CEMETERY,
 * scopeEntityId: $draft->cemetery_id)`); wiring that construction into an
 * actual outbox consumer is Task 3's job, not this one's.
 *
 * ---------------------------------------------------------------------------
 * Multiple scope entities — NOTIF-01, 07 Sep 2026
 * ---------------------------------------------------------------------------
 * A subject originally carried at most one scope entity — `RecipientResolver`
 * could therefore only ever derive ONE recipient class from it, so no
 * in-app notification was ever written for a platform admin or a vendor: an
 * order carries a cemetery scope (-> cemetery operator) but had no way to
 * ALSO carry the platform's own `business_entity` scope (-> platform
 * admin). The primary constructor arguments (`$scopeEntityType`/
 * `$scopeEntityId`) are kept unchanged for every existing single-scope
 * caller; `$additionalScopeEntities` is new and additive. `scopeEntities()`
 * exposes the combined, deduplication-ready list `RecipientResolver` now
 * iterates — see that class's own doc block for how it resolves each one
 * independently and dedupes the resulting recipients.
 *
 * Both `ownerRef` and the scope entity/entities are independently nullable
 * or empty, because a record may have none, either, or both: an anonymous
 * `booking_drafts` row has `user_id === null` (no customer recipient) but
 * still has `cemetery_id`; a record with no scope entity at all (e.g. no
 * `OrderWorkflow`/`FuneralCase` model exists yet — ruling 6) yields no
 * scope-based recipients, only (if any) a customer one.
 */
final class RecipientResolutionSubject
{
    /**
     * @var list<ScopeEntityReference>
     */
    public readonly array $scopeEntities;

    /**
     * @param  int|string|null  $ownerRef  The record owner's identity
     *                                     reference (`scope_assignments.actor_identifier`'s shape) —
     *                                     e.g. `booking_drafts.user_id`. `null` when the record has no
     *                                     owner (anonymous draft) or no owner concept at all.
     * @param  string|null  $scopeEntityType  One of `ScopeEntityType::KNOWN_TYPES`,
     *                                        or `null` when the record carries no PRIMARY scope entity
     *                                        reference. A subject with no primary scope entity may still
     *                                        carry one or more via `$additionalScopeEntities`.
     * @param  int|string|null  $scopeEntityId  The primary scope entity's own id. Must
     *                                          be non-null whenever `$scopeEntityType` is non-null (enforced
     *                                          below) — a type without an id cannot be queried.
     * @param  list<ScopeEntityReference>  $additionalScopeEntities  Extra scope entities beyond the
     *                                                               primary pair above — NOTIF-01. Each is
     *                                                               resolved independently by `RecipientResolver`,
     *                                                               exactly like the primary one.
     *
     * @throws \InvalidArgumentException when `$scopeEntityType` is not one
     *                                   of `ScopeEntityType::KNOWN_TYPES`, or is given without a
     *                                   `$scopeEntityId`.
     */
    public function __construct(
        public readonly int|string|null $ownerRef,
        public readonly ?string $scopeEntityType,
        public readonly int|string|null $scopeEntityId,
        array $additionalScopeEntities = [],
    ) {
        if ($this->scopeEntityType !== null) {
            ScopeEntityType::assertKnown($this->scopeEntityType);

            if ($this->scopeEntityId === null) {
                throw new \InvalidArgumentException('A scope entity type was given without a scope entity id.');
            }
        }

        $entities = [];

        if ($this->scopeEntityType !== null) {
            /** @var int|string $scopeEntityId */
            $scopeEntityId = $this->scopeEntityId;
            $entities[] = new ScopeEntityReference($this->scopeEntityType, $scopeEntityId);
        }

        foreach ($additionalScopeEntities as $reference) {
            $entities[] = $reference;
        }

        $this->scopeEntities = $entities;
    }

    public function hasScopeEntity(): bool
    {
        return $this->scopeEntities !== [];
    }
}

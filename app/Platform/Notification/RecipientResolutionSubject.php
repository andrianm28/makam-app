<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

/**
 * A plain value object identifying WHAT `RecipientResolver::resolve()`
 * should resolve recipients for — mirrors `App\Platform\Audit\AuditSubject`
 * deliberately: it names the record's owner and scope entity, never
 * carries the record's own field values.
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
 * Both `ownerRef` and the scope entity are independently nullable, because
 * a record may have neither, either, or both: an anonymous
 * `booking_drafts` row has `user_id === null` (no customer recipient) but
 * still has `cemetery_id`; a record with no scope entity at all (e.g. no
 * `OrderWorkflow`/`FuneralCase` model exists yet — ruling 6) yields no
 * scope-based recipients, only (if any) a customer one.
 */
final class RecipientResolutionSubject
{
    /**
     * @param  int|string|null  $ownerRef  The record owner's identity
     *                                     reference (`scope_assignments.actor_identifier`'s shape) —
     *                                     e.g. `booking_drafts.user_id`. `null` when the record has no
     *                                     owner (anonymous draft) or no owner concept at all.
     * @param  string|null  $scopeEntityType  One of `ScopeEntityType::KNOWN_TYPES`,
     *                                        or `null` when the record carries no scope entity reference.
     * @param  int|string|null  $scopeEntityId  The scope entity's own id. Must
     *                                          be non-null whenever `$scopeEntityType` is non-null (enforced
     *                                          below) — a type without an id cannot be queried.
     *
     * @throws \InvalidArgumentException when `$scopeEntityType` is not one
     *                                   of `ScopeEntityType::KNOWN_TYPES`, or is given without a
     *                                   `$scopeEntityId`.
     */
    public function __construct(
        public readonly int|string|null $ownerRef,
        public readonly ?string $scopeEntityType,
        public readonly int|string|null $scopeEntityId,
    ) {
        if ($this->scopeEntityType !== null) {
            ScopeEntityType::assertKnown($this->scopeEntityType);

            if ($this->scopeEntityId === null) {
                throw new \InvalidArgumentException('A scope entity type was given without a scope entity id.');
            }
        }
    }

    public function hasScopeEntity(): bool
    {
        return $this->scopeEntityType !== null;
    }
}

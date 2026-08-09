<?php

declare(strict_types=1);

namespace App\Platform\Notification;

/**
 * One resolved recipient — ruling 5, `docs/superpowers/plans/2026-08-10-
 * wave1a-notifications-decisions.md`. A plain immutable value object, not
 * an Eloquent model: `RecipientResolver` builds these from `ScopeAssignment`
 * rows and the record owner reference, never persists them directly.
 *
 * `scopeEntityType`/`scopeEntityId` are the scope entity this recipient was
 * resolved FROM (e.g. `cemetery`/`10`) — the brief's cross-scope-leakage
 * requirement is auditable directly off this pair. A customer recipient
 * carries the record owner reference on `actorRef` and `null` for both
 * scope fields, per ruling 5: "customer recipients carry the owner
 * reference rather than a scope entity" — a customer's standing to receive
 * the notification is ownership, not a `scope_assignments` grant.
 */
final class Recipient
{
    public function __construct(
        public readonly int|string $actorRef,
        public readonly string $actorRole,
        public readonly ?string $scopeEntityType,
        public readonly int|string|null $scopeEntityId,
    ) {
        RecipientRole::assertKnown($this->actorRole);
    }
}

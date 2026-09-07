<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\IdentityAccess\Scopes\ScopeEntityType;

/**
 * One (type, id) scope entity pair carried by a `RecipientResolutionSubject`
 * — NOTIF-01 (`docs/superpowers/plans/2026-09-07-batchm8b-notification-
 * completeness.md`). Extracted as its own value object, rather than a bare
 * `['type' => ..., 'id' => ...]` array, so `RecipientResolutionSubject`'s
 * `scopeEntities` list has the same "validated at construction, never a
 * loosely-shaped array" discipline the rest of this module already applies
 * (`RecipientResolutionSubject` itself, `Recipient`).
 *
 * A subject may carry more than one of these — e.g. an order's own
 * cemetery scope AND the platform's `business_entity` scope — because a
 * single record can legitimately be relevant to more than one recipient
 * class at once. See `RecipientResolutionSubject`'s own doc block for why
 * this replaced a single scope-entity pair.
 */
final class ScopeEntityReference
{
    /**
     * @throws \InvalidArgumentException when `$type` is not one of
     *                                   `ScopeEntityType::KNOWN_TYPES`.
     */
    public function __construct(
        public readonly string $type,
        public readonly int|string $id,
    ) {
        ScopeEntityType::assertKnown($this->type);
    }
}

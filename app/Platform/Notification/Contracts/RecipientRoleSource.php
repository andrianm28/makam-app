<?php

declare(strict_types=1);

namespace App\Platform\Notification\Contracts;

/**
 * The swappable seam ruling 2 requires
 * (`docs/superpowers/plans/2026-08-10-wave1a-notifications-decisions.md`):
 * `RecipientResolver` asks this contract "what recipient role does a grant
 * on this scope entity type imply", never a concrete implementation
 * directly, so the provisional implementation can be replaced by a real
 * K1/K2-backed one later without touching `RecipientResolver` itself.
 */
interface RecipientRoleSource
{
    /**
     * The recipient role (`RecipientRole::KNOWN_ROLES`) a grant on
     * `$scopeEntityType` implies, or `null` when this entity type carries
     * no derivable role (see the implementation's own doc block for which
     * types those are and why).
     *
     * @throws \InvalidArgumentException when `$scopeEntityType` is not one
     *                                   of `ScopeEntityType::KNOWN_TYPES`.
     */
    public function roleForScopeEntityType(string $scopeEntityType): ?string;
}

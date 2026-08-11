<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\Contracts\RecipientRoleSource;
use App\Platform\Notification\ProvisionalScopeEntityRecipientRoleSource;
use App\Platform\Notification\RecipientRole;
use Tests\TestCase;

/**
 * Ruling 2, `docs/superpowers/plans/2026-08-10-wave1a-notifications-
 * decisions.md`: the provisional role seam derives a recipient role from a
 * scope grant's `entity_type` only. This class covers the literal mapping
 * and the two deliberate non-mappings (`grave`, `order` carry no derivable
 * role; finance is never derivable from `business_entity` alone).
 */
final class ProvisionalScopeEntityRecipientRoleSourceTest extends TestCase
{
    public function test_it_implements_the_recipient_role_source_contract(): void
    {
        $this->assertInstanceOf(RecipientRoleSource::class, new ProvisionalScopeEntityRecipientRoleSource);
    }

    public function test_cemetery_maps_to_cemetery_operator(): void
    {
        $source = new ProvisionalScopeEntityRecipientRoleSource;

        $this->assertSame(RecipientRole::CEMETERY_OPERATOR, $source->roleForScopeEntityType(ScopeEntityType::CEMETERY));
    }

    public function test_vendor_maps_to_vendor(): void
    {
        $source = new ProvisionalScopeEntityRecipientRoleSource;

        $this->assertSame(RecipientRole::VENDOR, $source->roleForScopeEntityType(ScopeEntityType::VENDOR));
    }

    public function test_case_maps_to_case_manager(): void
    {
        $source = new ProvisionalScopeEntityRecipientRoleSource;

        $this->assertSame(RecipientRole::CASE_MANAGER, $source->roleForScopeEntityType(ScopeEntityType::CASE_RECORD));
    }

    public function test_business_entity_maps_to_platform_admin(): void
    {
        $source = new ProvisionalScopeEntityRecipientRoleSource;

        $this->assertSame(RecipientRole::PLATFORM_ADMIN, $source->roleForScopeEntityType(ScopeEntityType::BUSINESS_ENTITY));
    }

    public function test_grave_has_no_derivable_role(): void
    {
        $source = new ProvisionalScopeEntityRecipientRoleSource;

        $this->assertNull($source->roleForScopeEntityType(ScopeEntityType::GRAVE));
    }

    public function test_order_has_no_derivable_role(): void
    {
        $source = new ProvisionalScopeEntityRecipientRoleSource;

        $this->assertNull($source->roleForScopeEntityType(ScopeEntityType::ORDER));
    }

    public function test_it_rejects_an_unknown_entity_type(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ProvisionalScopeEntityRecipientRoleSource)->roleForScopeEntityType('spaceship');
    }
}

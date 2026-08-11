<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Notification\Recipient;
use App\Platform\Notification\RecipientRole;
use App\Platform\Notification\RecipientSet;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Ruling 5, `docs/superpowers/plans/2026-08-10-wave1a-notifications-
 * decisions.md`: "not a policy question. The lane defines the concrete
 * field list from what channel resolution and role/scope resolution
 * actually need." Covers the constraints the brief does fix: each item
 * carries `actor_ref`, `actor_role`, and the scope entity it was resolved
 * from; the set is iterable and can be empty; customer recipients carry no
 * scope entity.
 */
final class RecipientSetTest extends TestCase
{
    public function test_an_empty_set_has_no_recipients_and_is_countable(): void
    {
        $set = RecipientSet::empty();

        $this->assertTrue($set->isEmpty());
        $this->assertCount(0, $set);
        $this->assertSame([], $set->all());
    }

    public function test_it_is_iterable_over_its_recipients(): void
    {
        $customer = new Recipient(actorRef: '1', actorRole: RecipientRole::CUSTOMER, scopeEntityType: null, scopeEntityId: null);
        $operator = new Recipient(actorRef: '2', actorRole: RecipientRole::CEMETERY_OPERATOR, scopeEntityType: ScopeEntityType::CEMETERY, scopeEntityId: '10');

        $set = new RecipientSet([$customer, $operator]);

        $this->assertFalse($set->isEmpty());
        $this->assertCount(2, $set);
        $this->assertSame([$customer, $operator], iterator_to_array($set));
    }

    public function test_a_customer_recipient_carries_no_scope_entity(): void
    {
        $customer = new Recipient(actorRef: '1', actorRole: RecipientRole::CUSTOMER, scopeEntityType: null, scopeEntityId: null);

        $this->assertNull($customer->scopeEntityType);
        $this->assertNull($customer->scopeEntityId);
    }

    public function test_a_scoped_recipient_carries_the_entity_it_was_resolved_from(): void
    {
        $operator = new Recipient(actorRef: '2', actorRole: RecipientRole::CEMETERY_OPERATOR, scopeEntityType: ScopeEntityType::CEMETERY, scopeEntityId: '10');

        $this->assertSame(ScopeEntityType::CEMETERY, $operator->scopeEntityType);
        $this->assertSame('10', $operator->scopeEntityId);
    }

    public function test_a_recipient_rejects_an_unknown_role(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Recipient(actorRef: '1', actorRole: 'spaceship-pilot', scopeEntityType: null, scopeEntityId: null);
    }
}

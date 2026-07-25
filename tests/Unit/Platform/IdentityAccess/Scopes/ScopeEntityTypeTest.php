<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess\Scopes;

use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Pure unit coverage — no database, no container. `ScopeEntityType` is the
 * application-level validation requirements.md AC5's "cemetery, vendor,
 * order, case, grave, and business entity" list is enforced through, since
 * the schema column itself is a plain string (see the migration's doc
 * block).
 */
final class ScopeEntityTypeTest extends TestCase
{
    public function test_all_six_ac5_entity_types_are_known(): void
    {
        foreach (['cemetery', 'vendor', 'order', 'case', 'grave', 'business_entity'] as $type) {
            $this->assertTrue(ScopeEntityType::isKnown($type), "Expected [{$type}] to be known.");
        }
    }

    public function test_an_arbitrary_unknown_type_is_not_known(): void
    {
        $this->assertFalse(ScopeEntityType::isKnown('spaceship'));
    }

    public function test_known_types_list_has_exactly_six_entries(): void
    {
        // Locks the count in explicitly so an accidental duplicate or a
        // silently dropped entry fails loudly here.
        $this->assertCount(6, ScopeEntityType::KNOWN_TYPES);
    }

    public function test_assert_known_does_not_throw_for_a_known_type(): void
    {
        ScopeEntityType::assertKnown(ScopeEntityType::CEMETERY);

        $this->addToAssertionCount(1);
    }

    public function test_assert_known_throws_for_an_unknown_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScopeEntityType::assertKnown('spaceship');
    }

    public function test_case_record_constant_value_is_the_literal_string_case(): void
    {
        // The constant is named CASE_RECORD (not CASE — a PHP reserved
        // word) purely to satisfy PHP's own grammar; its *value* must still
        // be the literal 'case' requirements.md AC5 and rbac-matrix.md use.
        $this->assertSame('case', ScopeEntityType::CASE_RECORD);
    }
}

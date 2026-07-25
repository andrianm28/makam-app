<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\IdentityAccess\Scopes;

use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Pure unit coverage — no database, no container. See `ScopeGrantLevel`'s
 * own doc block for why this metadata exists but is not read by
 * `ScopeAssignmentGlobalScope`.
 */
final class ScopeGrantLevelTest extends TestCase
{
    public function test_own_assigned_read_and_privileged_are_known(): void
    {
        foreach (['own', 'assigned', 'read', 'privileged'] as $level) {
            $this->assertTrue(ScopeGrantLevel::isKnown($level), "Expected [{$level}] to be known.");
        }
    }

    public function test_an_arbitrary_unknown_level_is_not_known(): void
    {
        $this->assertFalse(ScopeGrantLevel::isKnown('super-admin'));
    }

    public function test_assert_known_does_not_throw_for_a_known_level(): void
    {
        ScopeGrantLevel::assertKnown(ScopeGrantLevel::OWN);

        $this->addToAssertionCount(1);
    }

    public function test_assert_known_throws_for_an_unknown_level(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ScopeGrantLevel::assertKnown('super-admin');
    }
}

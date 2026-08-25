<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Support\ExampleData\CemeteryExampleData;

/**
 * Shared test lookup for seeded example cemeteries. `RefreshDatabase`
 * rebuilds the database per test with fresh UUIDs, so tests can never hold
 * a cemetery id across tests — they must resolve by role at assertion time.
 * This helper is the single place that lookup happens, replacing the
 * identical private `cemeteryId()` methods that three suites each
 * duplicated.
 *
 * Roles are the positions `CemeteryExampleData::roleCemetery()` resolves:
 * `draft`, `all-restricted`, `package` (index 0 = Jakarta TPU, 1 = Depok
 * TPU), and `open` (the plain published example cemetery). Tests reference
 * roles, never slug or display-name literals, so a generator change cannot
 * silently orphan an assertion.
 */
final class CemeteryFixture
{
    public static function id(string $role, ?int $index = null): string
    {
        return (string) self::cemetery($role, $index)->id;
    }

    public static function cemetery(string $role, ?int $index = null): Cemetery
    {
        return Cemetery::query()->where('slug', self::slug($role, $index))->sole();
    }

    public static function name(string $role, ?int $index = null): string
    {
        return self::cemetery($role, $index)->name;
    }

    public static function slug(string $role, ?int $index = null): string
    {
        return CemeteryExampleData::roleCemetery($role, $index)[2];
    }
}

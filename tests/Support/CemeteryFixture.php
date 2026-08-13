<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\CemeteryDirectory\Models\Cemetery;

/**
 * Shared test lookup for seeded example cemeteries. `RefreshDatabase`
 * rebuilds the database per test with fresh UUIDs, so tests can never hold
 * a cemetery id across tests — they must resolve by the deterministic
 * example-data slug at assertion time. This helper is the single place that
 * lookup happens, replacing the identical private `cemeteryId()` methods
 * that three suites each duplicated.
 *
 * Slugs come from `App\Support\ExampleData\CemeteryExampleData` role
 * constants, never literals scattered in tests.
 */
final class CemeteryFixture
{
    public static function id(string $slug): string
    {
        return (string) self::cemetery($slug)->id;
    }

    public static function cemetery(string $slug): Cemetery
    {
        return Cemetery::query()->where('slug', $slug)->sole();
    }
}

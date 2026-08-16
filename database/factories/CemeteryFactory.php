<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-fixture factory for `cemeteries` — none existed before this one:
 * `GraveRecordFactory`'s doc block records that it deliberately picked a
 * seeded cemetery because "no `Cemetery` factory exists", and the P2
 * `CemeteryResource` tests create cemeteries inline with `Cemetery::create`.
 * Added for the P3 plot-inventory tests (`Cemetery::factory()->create()`),
 * shaped after the P2 `CemeteryForm` fields: `type`, `name`, `slug`, `city`,
 * `address` (+ `publication_status`, which the form also sets). All
 * required columns are populated with values that satisfy
 * `Cemetery::booted()`'s own guards (`CemeteryType::assertKnown`,
 * `CemeteryPublicationStatus::assertKnown`,
 * `LaunchCityQuery::isKnown`).
 *
 * @extends Factory<Cemetery>
 */
final class CemeteryFactory extends Factory
{
    protected $model = Cemetery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => fake()->company().' Pemakaman',
            'slug' => fake()->unique()->slug(),
            'city' => LaunchCityCode::JAKARTA,
            'address' => fake()->streetAddress(),
        ];
    }
}

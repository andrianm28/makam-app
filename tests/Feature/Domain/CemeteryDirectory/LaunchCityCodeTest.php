<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\LaunchCityCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * requirements.md AC1: "THE SYSTEM SHALL include Jakarta, Bogor, Depok,
 * Tangerang, and Bekasi in the initial launch city/regency list," and
 * negative criterion "No hidden omission of a required MVP city." This
 * test asserts the closed list is exactly, and only, those five —
 * matching `docs/product/booking-wizard-fields.md`'s own listed codes.
 */
final class LaunchCityCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_the_five_launch_cities_in_the_documented_order(): void
    {
        $this->assertSame([
            'JAKARTA', 'BOGOR', 'DEPOK', 'TANGERANG', 'BEKASI',
        ], LaunchCityCode::KNOWN_CODES);
        $this->assertCount(5, LaunchCityCode::KNOWN_CODES);
    }

    public function test_no_required_mvp_city_is_missing(): void
    {
        $required = ['JAKARTA', 'BOGOR', 'DEPOK', 'TANGERANG', 'BEKASI'];

        foreach ($required as $city) {
            $this->assertTrue(
                LaunchCityCode::isKnown($city),
                "AC1/negative-criteria violation: required launch city [{$city}] is missing."
            );
        }
    }

    public function test_assert_known_throws_for_a_city_outside_the_launch_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        LaunchCityCode::assertKnown('SURABAYA');
    }
}

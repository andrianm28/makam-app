<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Booking;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Models\LaunchCity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `BookingDraft`'s city validation reads `LaunchCityQuery::isKnown()`, so
 * an admin-added `launch_cities` row is accepted while a code in neither
 * the table nor the canonical constants is rejected.
 */
final class LaunchCityValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_draft_with_an_admin_added_launch_city_saves(): void
    {
        LaunchCity::query()->create(['code' => 'SUKABUMI', 'label' => 'Sukabumi']);

        $draft = BookingDraft::create(['city_code' => 'SUKABUMI']);

        $this->assertSame('SUKABUMI', $draft->city_code);
    }

    public function test_a_draft_with_an_unknown_city_code_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown launch city code [NONEXISTENT].');

        BookingDraft::create(['city_code' => 'NONEXISTENT']);
    }
}

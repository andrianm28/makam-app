<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryCapability;

use App\Domain\CemeteryCapability\AvailabilityMode;
use App\Domain\CemeteryCapability\BookingMode;
use App\Domain\CemeteryCapability\CertificateMode;
use App\Domain\CemeteryCapability\MapMode;
use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryCapability\RegistryMode;
use App\Domain\CemeteryCapability\VisitationMode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The six mode closed lists `docs/architecture/overview.md` §6 names are
 * each exactly three cases, and `CemeteryCapabilityProfile::booted()`
 * rejects an unknown value on any one of the six columns — requirements.md
 * AC4/AC7.
 */
final class CemeteryCapabilityModeClosedListTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_mode_has_exactly_the_three_documented_cases(): void
    {
        $this->assertSame(['INDICATIVE', 'PACKAGE_CLASS', 'SPECIFIC_PLOT'], AvailabilityMode::KNOWN_MODES);
        $this->assertSame(['REQUEST_CONFIRMATION', 'RESERVE_PLOT', 'DIRECT_PURCHASE'], BookingMode::KNOWN_MODES);
        $this->assertSame(['LOCATION_ONLY', 'BLOCK_MAP', 'PLOT_MAP'], MapMode::KNOWN_MODES);
        $this->assertSame(['NONE', 'BASIC', 'AUTHORITATIVE'], RegistryMode::KNOWN_MODES);
        $this->assertSame(['NONE', 'MANUAL', 'PLATFORM_MANAGED'], CertificateMode::KNOWN_MODES);
        $this->assertSame(['NONE', 'INFORMATION_ONLY', 'BOOKABLE'], VisitationMode::KNOWN_MODES);
    }

    public function test_each_mode_default_is_the_documented_safe_default(): void
    {
        $this->assertSame(AvailabilityMode::INDICATIVE, AvailabilityMode::DEFAULT);
        $this->assertSame(BookingMode::REQUEST_CONFIRMATION, BookingMode::DEFAULT);
        $this->assertSame(MapMode::LOCATION_ONLY, MapMode::DEFAULT);
        $this->assertSame(RegistryMode::NONE, RegistryMode::DEFAULT);
        $this->assertSame(CertificateMode::NONE, CertificateMode::DEFAULT);
        $this->assertSame(VisitationMode::NONE, VisitationMode::DEFAULT);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function modeColumnProvider(): iterable
    {
        yield 'availability_mode' => ['availability_mode'];
        yield 'booking_mode' => ['booking_mode'];
        yield 'map_mode' => ['map_mode'];
        yield 'registry_mode' => ['registry_mode'];
        yield 'certificate_mode' => ['certificate_mode'];
        yield 'visitation_mode' => ['visitation_mode'];
    }

    #[DataProvider('modeColumnProvider')]
    public function test_the_model_rejects_an_unknown_value_on_each_mode_column(string $column): void
    {
        $cemetery = Cemetery::query()->firstOrFail();
        $defaults = CemeteryCapabilityProfile::safeDefaults();
        $defaults[$column] = 'NOT_A_REAL_MODE';

        $this->expectException(InvalidArgumentException::class);

        CemeteryCapabilityProfile::create(array_merge($defaults, [
            'cemetery_id' => $cemetery->id,
            'version_number' => 99,
            'source' => 'test',
            'effective_at' => now(),
        ]));
    }
}

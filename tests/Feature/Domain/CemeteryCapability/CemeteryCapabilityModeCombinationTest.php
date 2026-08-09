<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryCapability;

use App\Domain\CemeteryCapability\AvailabilityMode;
use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryCapability\RegistryMode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `CemeteryCapabilityModeClosedListTest` proves each of the six mode columns
 * is independently guarded against an unknown value. This file covers the
 * gap that leaves: a row whose six values are each individually legal but
 * whose COMBINATION is not, per `docs/domain/cemetery-capability-model.md`
 * "Valid combinations" and requirements.md AC7 ("THE SYSTEM SHALL NOT enable
 * `SPECIFIC_PLOT` unless an authoritative registry, freshness evidence, and
 * a reservation contract are present").
 */
final class CemeteryCapabilityModeCombinationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, string>  $modes
     */
    private function createProfile(array $modes, int $versionNumber): CemeteryCapabilityProfile
    {
        $cemetery = Cemetery::query()->firstOrFail();

        return CemeteryCapabilityProfile::create(array_merge(
            CemeteryCapabilityProfile::safeDefaults(),
            $modes,
            [
                'cemetery_id' => $cemetery->id,
                'version_number' => $versionNumber,
                'source' => 'test',
                'effective_at' => now(),
            ],
        ));
    }

    public function test_specific_plot_availability_without_an_authoritative_registry_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(AvailabilityMode::SPECIFIC_PLOT);

        $this->createProfile([
            'availability_mode' => AvailabilityMode::SPECIFIC_PLOT,
            'registry_mode' => RegistryMode::NONE,
        ], 91);
    }

    public function test_specific_plot_availability_with_an_authoritative_registry_saves(): void
    {
        $profile = $this->createProfile([
            'availability_mode' => AvailabilityMode::SPECIFIC_PLOT,
            'registry_mode' => RegistryMode::AUTHORITATIVE,
        ], 92);

        $this->assertTrue($profile->exists);
        $this->assertSame(AvailabilityMode::SPECIFIC_PLOT, $profile->availability_mode);
        $this->assertSame(RegistryMode::AUTHORITATIVE, $profile->registry_mode);
    }

    /**
     * Regression guard: the combination check must not break the safe-default
     * combination every seeded cemetery already carries (`INDICATIVE` +
     * `NONE`), which is the one combination this module writes today.
     */
    public function test_the_safe_default_combination_still_saves(): void
    {
        $profile = $this->createProfile([], 93);

        $this->assertTrue($profile->exists);
        $this->assertTrue($profile->matchesSafeDefaults());
        $this->assertSame(AvailabilityMode::INDICATIVE, $profile->availability_mode);
        $this->assertSame(RegistryMode::NONE, $profile->registry_mode);
    }
}

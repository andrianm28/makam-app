<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryCapability;

use App\Domain\CemeteryCapability\Actions\ResolveCemeteryCapabilityProfile;
use App\Domain\CemeteryCapability\AvailabilityMode;
use App\Domain\CemeteryCapability\BookingMode;
use App\Domain\CemeteryCapability\CertificateMode;
use App\Domain\CemeteryCapability\MapMode;
use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryCapability\RegistryMode;
use App\Domain\CemeteryCapability\VisitationMode;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * requirements.md AC4: "THE SYSTEM SHALL maintain an explicit versioned
 * capability profile for every cemetery. WHEN a capability profile is
 * missing THE SYSTEM SHALL use safe defaults." Proves both halves: every
 * seeded cemetery really does have a real, correctly-defaulted profile
 * row, AND the fallback resolver produces the identical safe defaults for
 * a cemetery that has none, without persisting anything.
 */
final class CemeteryCapabilityProfileSafeDefaultsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_seeded_cemetery_has_exactly_one_current_profile_at_version_one(): void
    {
        $cemeteryIds = Cemetery::query()->pluck('id');

        $this->assertCount(10, $cemeteryIds);

        foreach ($cemeteryIds as $cemeteryId) {
            $current = CemeteryCapabilityProfile::query()
                ->where('cemetery_id', $cemeteryId)
                ->current()
                ->get();

            $this->assertCount(1, $current, "Cemetery [{$cemeteryId}] must have exactly one current profile.");
            $this->assertSame(1, $current->first()->version_number);
        }
    }

    public function test_every_seeded_profile_matches_the_documented_safe_defaults(): void
    {
        $profiles = CemeteryCapabilityProfile::query()->current()->get();

        $this->assertCount(10, $profiles);

        foreach ($profiles as $profile) {
            $this->assertTrue($profile->matchesSafeDefaults());
            $this->assertSame(AvailabilityMode::INDICATIVE, $profile->availability_mode);
            $this->assertSame(BookingMode::REQUEST_CONFIRMATION, $profile->booking_mode);
            $this->assertSame(MapMode::LOCATION_ONLY, $profile->map_mode);
            $this->assertSame(RegistryMode::NONE, $profile->registry_mode);
            $this->assertSame(CertificateMode::NONE, $profile->certificate_mode);
            $this->assertSame(VisitationMode::NONE, $profile->visitation_mode);
        }
    }

    public function test_resolver_returns_the_persisted_current_profile_when_one_exists(): void
    {
        $cemetery = Cemetery::query()->firstOrFail();

        $resolved = (new ResolveCemeteryCapabilityProfile)($cemetery);

        $this->assertTrue($resolved->exists);
        $this->assertSame(1, $resolved->version_number);
        $this->assertTrue($resolved->isCurrent());
    }

    public function test_resolver_falls_back_to_an_unsaved_safe_default_profile_when_none_exists(): void
    {
        $cemetery = Cemetery::create([
            'type' => CemeteryType::TPS,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'Cemetery With No Capability Profile',
            'slug' => 'cemetery-with-no-capability-profile',
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Uji Coba No. 2',
        ]);

        $this->assertDatabaseCount('cemetery_capability_profiles', 10); // seeded rows only, none for this cemetery

        $resolved = (new ResolveCemeteryCapabilityProfile)($cemetery);

        $this->assertFalse($resolved->exists, 'AC4 fallback must not silently persist a profile.');
        $this->assertTrue($resolved->matchesSafeDefaults());
        $this->assertSame($cemetery->id, $resolved->cemetery_id);

        // Still exactly 10 — the fallback call above wrote nothing.
        $this->assertDatabaseCount('cemetery_capability_profiles', 10);
    }
}

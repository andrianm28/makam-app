<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Directory;

use App\Domain\CemeteryCapability\Actions\ResolveCemeteryCapabilityProfile;
use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Livewire\Public\Directory\Support\CemeteryDirectoryQuery;
use App\Livewire\Public\Directory\Support\PublicCapabilityProjection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

/**
 * AC12 ("THE SYSTEM SHALL render only active capabilities in the public
 * UI") and the negative criterion "No public exposure of restricted plot
 * data" — Sprint 4 S4-T6.
 *
 * The thing under test is a NEGATIVE: that `registry_mode` and
 * `certificate_mode` cannot reach a public surface. A test that only
 * asserts the four allowed keys are present would pass just as happily if
 * two extra ones were added later, so this file asserts the absence
 * directly, three different ways:
 *
 *   1. against the real `docs/contracts/openapi.yaml` contract, so the
 *      allowlist cannot drift from the schema it claims to implement;
 *   2. against the class's own structure via reflection, so a future
 *      property cannot be added without failing here;
 *   3. against real seeded data through the actual query path.
 */
final class PublicCapabilityProjectionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The two modes that must never reach a public surface.
     *
     * @var list<string>
     */
    private const array RESTRICTED_KEYS = ['registry_mode', 'certificate_mode'];

    public function test_projection_exposes_exactly_the_four_openapi_public_capability_keys(): void
    {
        $this->assertSame(
            ['availability_mode', 'booking_mode', 'map_mode', 'visitation_mode'],
            PublicCapabilityProjection::PUBLIC_KEYS,
        );
    }

    /**
     * The contract test. `PublicCapabilityProjection::PUBLIC_KEYS` claims to
     * be a transcription of `openapi.yaml`'s `PublicCapabilityProfile`
     * schema; this parses that schema out of the real file and proves it.
     *
     * Without this, the constant and the contract could silently diverge —
     * and the direction that matters is a mode being ADDED to the contract
     * that this class never learns to project, or a restricted mode being
     * added to the contract by mistake.
     */
    public function test_public_keys_match_the_openapi_public_capability_profile_schema(): void
    {
        $keys = $this->openApiPublicCapabilityProfileKeys();

        $this->assertSame(
            PublicCapabilityProjection::PUBLIC_KEYS,
            $keys,
            'PublicCapabilityProjection::PUBLIC_KEYS has drifted from docs/contracts/openapi.yaml.',
        );
    }

    public function test_openapi_public_capability_profile_schema_does_not_contain_restricted_modes(): void
    {
        $keys = $this->openApiPublicCapabilityProfileKeys();

        foreach (self::RESTRICTED_KEYS as $restricted) {
            $this->assertNotContains(
                $restricted,
                $keys,
                "openapi.yaml's PublicCapabilityProfile must never expose [{$restricted}].",
            );
        }
    }

    /**
     * Structural, not behavioural — the same instinct as
     * `tests/Feature/FeatureGate/ClientSideTamperingCannotOpenAGateTest.php`'s
     * reflection walk proving the gate resolver has no request-shaped
     * dependency. Here: the projection has no property whose name could
     * carry a restricted mode, so no code path exists by which one could
     * leak, regardless of what a future view tries to render.
     */
    public function test_projection_class_has_no_property_for_a_restricted_mode(): void
    {
        $properties = array_map(
            static fn (\ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(PublicCapabilityProjection::class))->getProperties(),
        );

        $this->assertSame(
            ['availabilityMode', 'bookingMode', 'mapMode', 'visitationMode'],
            $properties,
        );

        foreach ($properties as $property) {
            $this->assertStringNotContainsStringIgnoringCase('registry', $property);
            $this->assertStringNotContainsStringIgnoringCase('certificate', $property);
        }
    }

    /**
     * The profile handed in carries all six modes with distinctive
     * restricted values; the projection must retain neither.
     */
    public function test_projection_drops_registry_and_certificate_modes_from_a_full_profile(): void
    {
        $profile = new CemeteryCapabilityProfile(array_merge(
            CemeteryCapabilityProfile::safeDefaults(),
            [
                'registry_mode' => 'AUTHORITATIVE',
                'certificate_mode' => 'PLATFORM_MANAGED',
            ],
        ));

        $projected = PublicCapabilityProjection::from($profile)->toArray();

        $this->assertSame(PublicCapabilityProjection::PUBLIC_KEYS, array_keys($projected));

        foreach (self::RESTRICTED_KEYS as $restricted) {
            $this->assertArrayNotHasKey($restricted, $projected);
        }

        // Also assert on the VALUES, not just the keys — a future refactor
        // that renamed a key while still carrying the restricted value
        // through would pass a key-only assertion.
        $this->assertNotContains('AUTHORITATIVE', $projected);
        $this->assertNotContains('PLATFORM_MANAGED', $projected);
    }

    /**
     * End to end through the real query path, against the real seeded
     * cemeteries — no fabricated fixture.
     */
    public function test_every_seeded_published_cemetery_projects_only_public_modes(): void
    {
        $cemeteries = Cemetery::query()->published()->get();

        $this->assertGreaterThan(0, $cemeteries->count());

        foreach ($cemeteries as $cemetery) {
            $projected = CemeteryDirectoryQuery::publicCapabilities($cemetery)->toArray();

            $this->assertSame(PublicCapabilityProjection::PUBLIC_KEYS, array_keys($projected));
        }
    }

    /**
     * AC4's safe-default fallback must go through the same allowlist — a
     * degraded path must not become a hole in the projection.
     */
    public function test_safe_default_fallback_profile_projects_only_public_modes(): void
    {
        $cemetery = Cemetery::query()->published()->firstOrFail();

        // Remove every profile row for this cemetery so the resolver takes
        // its documented "no current profile" branch and returns an UNSAVED
        // safe-default instance.
        CemeteryCapabilityProfile::query()->where('cemetery_id', $cemetery->id)->delete();

        $resolved = (new ResolveCemeteryCapabilityProfile)($cemetery);

        $this->assertTrue($resolved->matchesSafeDefaults());

        $projected = PublicCapabilityProjection::from($resolved)->toArray();

        $this->assertSame(PublicCapabilityProjection::PUBLIC_KEYS, array_keys($projected));
    }

    /**
     * Parses the `properties:` key list out of `openapi.yaml`'s
     * `PublicCapabilityProfile` schema.
     *
     * A plain line scan rather than a YAML parser: `ext-yaml` is not a
     * declared dependency of this project (checked in `composer.json`
     * before writing this), and adding one for a single test would be a
     * dependency change this batch has no mandate for. The scan is
     * deterministic for this file's fixed two-space-per-level indentation —
     * schemas sit at 4 spaces, their keys at 6, property names at 8.
     *
     * @return list<string>
     */
    private function openApiPublicCapabilityProfileKeys(): array
    {
        $path = base_path('docs/contracts/openapi.yaml');

        $this->assertFileExists($path);

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $this->assertIsArray($lines);

        $keys = [];
        $inSchema = false;
        $inProperties = false;

        foreach ($lines as $line) {
            if ($line === '    PublicCapabilityProfile:') {
                $inSchema = true;

                continue;
            }

            if (! $inSchema) {
                continue;
            }

            // A new sibling schema at the same indent ends this one.
            if (preg_match('/^    \S/', $line) === 1) {
                break;
            }

            if ($line === '      properties:') {
                $inProperties = true;

                continue;
            }

            if (! $inProperties) {
                continue;
            }

            if (preg_match('/^        ([a-z_]+):$/', $line, $matches) === 1) {
                $keys[] = $matches[1];
            }
        }

        // Guards the parser itself: if the scan silently matched nothing,
        // every assertion built on it would be vacuously true.
        $this->assertNotEmpty($keys, 'Failed to parse PublicCapabilityProfile properties from openapi.yaml.');

        return $keys;
    }
}

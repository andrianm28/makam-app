<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Migrations;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `2026_08_24_100000_backfill_photo_and_maps_url_for_real_cemeteries.php`
 * targets 4 REAL cemetery rows (`tpu-karet-bivak`, `tpu-petamburan`,
 * `tpu-pondok-kelapa`, `tpu-semper-budi-dharma`) that exist only on
 * dev/staging/beta — NOT the 10 fictional rows
 * `2026_07_26_190300_seed_cemeteries_and_capability_profiles.php` seeds into
 * every environment including CI. So a fresh `RefreshDatabase` test database
 * has zero rows this migration's `WHERE slug = ...` can match — the
 * migration is a safe no-op there by construction, not a gap in coverage.
 *
 * These tests prove the migration's real logic two ways: (1) direct
 * instantiation with a manually-inserted fixture row carrying one of the 4
 * real slugs (same pattern as
 * `FixCustomerAndUploaderIdentityColumnsRollbackTest`), confirming `up()`
 * genuinely sets the right values and `down()` genuinely reverses them; (2)
 * a no-op-safety test proving `up()` does not touch or error against the 10
 * fictional seeded rows the standard test database always has.
 */
final class BackfillPhotoAndMapsUrlForRealCemeteriesTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION_PATH = 'database/migrations/2026_08_24_100000_backfill_photo_and_maps_url_for_real_cemeteries.php';

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION_PATH);
    }

    private function makeCemetery(string $slug, string $name): Cemetery
    {
        return Cemetery::create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => $name,
            'slug' => $slug,
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh Fixture No. 1',
        ]);
    }

    public function test_up_sets_a_real_svg_photo_path_for_each_of_the_4_real_slugs(): void
    {
        $this->makeCemetery('tpu-karet-bivak', 'TPU Karet Bivak');
        $this->makeCemetery('tpu-petamburan', 'TPU Petamburan');
        $this->makeCemetery('tpu-pondok-kelapa', 'TPU Pondok Kelapa');
        $this->makeCemetery('tpu-semper-budi-dharma', 'TPU Semper (Budi Dharma)');

        $this->migration()->up();

        foreach (['tpu-karet-bivak', 'tpu-petamburan', 'tpu-pondok-kelapa', 'tpu-semper-budi-dharma'] as $slug) {
            $cemetery = Cemetery::query()->where('slug', $slug)->firstOrFail();

            $this->assertNotNull($cemetery->primary_photo_path, "slug [{$slug}] missing primary_photo_path after backfill");
            $this->assertStringStartsWith('images/cemeteries/', (string) $cemetery->primary_photo_path);
            $this->assertStringEndsWith('.svg', (string) $cemetery->primary_photo_path);
            $this->assertFileExists(public_path((string) $cemetery->primary_photo_path));
        }
    }

    public function test_up_sets_an_address_based_maps_url_for_petamburan_only(): void
    {
        $this->makeCemetery('tpu-petamburan', 'TPU Petamburan');
        $this->makeCemetery('tpu-pondok-kelapa', 'TPU Pondok Kelapa');

        $this->migration()->up();

        $petamburan = Cemetery::query()->where('slug', 'tpu-petamburan')->firstOrFail();
        $this->assertNotNull($petamburan->google_maps_url);
        $this->assertStringContainsString('google.com/maps/search', (string) $petamburan->google_maps_url);
        $this->assertStringContainsString('Petamburan', (string) $petamburan->google_maps_url);

        // Pondok Kelapa deliberately gets NO map presence — its operating
        // status has an unresolved discrepancy (see the migration's own doc
        // block) — this is the load-bearing assertion for that decision.
        $pondokKelapa = Cemetery::query()->where('slug', 'tpu-pondok-kelapa')->firstOrFail();
        $this->assertNull($pondokKelapa->google_maps_url, 'Pondok Kelapa must not get a maps URL until operating status is verified');
        $this->assertNull($pondokKelapa->latitude);
        $this->assertNull($pondokKelapa->longitude);
    }

    public function test_up_does_not_overwrite_an_existing_google_maps_url(): void
    {
        $karetBivak = $this->makeCemetery('tpu-karet-bivak', 'TPU Karet Bivak');
        $karetBivak->forceFill([
            'latitude' => '-6.2033000',
            'longitude' => '106.8153500',
            'google_maps_url' => 'https://www.google.com/maps/search/?api=1&query=-6.20330,106.81535',
        ])->save();

        $this->migration()->up();

        $karetBivak->refresh();
        $this->assertSame('https://www.google.com/maps/search/?api=1&query=-6.20330,106.81535', $karetBivak->google_maps_url);
        $this->assertSame('-6.2033000', $karetBivak->latitude);
    }

    public function test_down_reverses_photo_and_the_petamburan_maps_url(): void
    {
        $this->makeCemetery('tpu-karet-bivak', 'TPU Karet Bivak');
        $this->makeCemetery('tpu-petamburan', 'TPU Petamburan');

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        foreach (['tpu-karet-bivak', 'tpu-petamburan'] as $slug) {
            $cemetery = Cemetery::query()->where('slug', $slug)->firstOrFail();
            $this->assertNull($cemetery->primary_photo_path, "slug [{$slug}] photo not reversed by down()");
        }

        $petamburan = Cemetery::query()->where('slug', 'tpu-petamburan')->firstOrFail();
        $this->assertNull($petamburan->google_maps_url, 'Petamburan maps URL not reversed by down()');
    }

    public function test_up_is_a_safe_no_op_against_the_standard_fictional_seeded_dataset(): void
    {
        $before = Cemetery::query()->get(['id', 'slug', 'primary_photo_path', 'google_maps_url'])->keyBy('id');

        $this->migration()->up();

        $after = Cemetery::query()->get(['id', 'slug', 'primary_photo_path', 'google_maps_url'])->keyBy('id');

        $this->assertSame($before->count(), $after->count(), 'no fictional seeded row should be inserted or deleted');

        foreach ($before as $id => $cemeteryBefore) {
            $cemeteryAfter = $after->get($id);
            $this->assertNotNull($cemeteryAfter, "fictional seeded cemetery [{$id}] disappeared");
            $this->assertSame(
                $cemeteryBefore->primary_photo_path,
                $cemeteryAfter->primary_photo_path,
                "fictional seeded cemetery [{$cemeteryBefore->slug}] should be untouched by the real-cemetery backfill"
            );
            $this->assertSame($cemeteryBefore->google_maps_url, $cemeteryAfter->google_maps_url);
        }
    }
}

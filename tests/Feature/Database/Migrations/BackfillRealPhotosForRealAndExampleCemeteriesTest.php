<?php

declare(strict_types=1);

namespace Tests\Feature\Database\Migrations;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `2026_09_08_100000_backfill_real_photos_for_real_and_example_cemeteries.php`
 * — unlike the migration it follows
 * (`BackfillPhotoAndMapsUrlForRealCemeteriesTest`'s own subject, which is
 * deliberately a no-op against the 10 fictional seeded rows), THIS
 * migration intentionally touches BOTH the 4 real named cemeteries and the
 * 10 fictional example rows: it is the migration that reverses the
 * "illustrations only" stance for both groups at once, on explicit product
 * owner direction (see the migration's own doc block).
 */
final class BackfillRealPhotosForRealAndExampleCemeteriesTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION_PATH = 'database/migrations/2026_09_08_100000_backfill_real_photos_for_real_and_example_cemeteries.php';

    private const array REAL_SLUGS = [
        'tpu-karet-bivak',
        'tpu-petamburan',
        'tpu-pondok-kelapa',
        'tpu-semper-budi-dharma',
    ];

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION_PATH);
    }

    private function makeRealCemetery(string $slug, string $name): Cemetery
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

    public function test_up_sets_a_real_jpg_photo_path_for_each_of_the_4_real_slugs(): void
    {
        foreach (self::REAL_SLUGS as $slug) {
            $this->makeRealCemetery($slug, 'Contoh '.$slug);
        }

        $this->migration()->up();

        foreach (self::REAL_SLUGS as $slug) {
            $cemetery = Cemetery::query()->where('slug', $slug)->firstOrFail();

            $this->assertNotNull($cemetery->primary_photo_path, "slug [{$slug}] missing primary_photo_path after backfill");
            $this->assertStringStartsWith('images/cemeteries/', (string) $cemetery->primary_photo_path);
            $this->assertStringEndsWith('.jpg', (string) $cemetery->primary_photo_path);
            $this->assertFileExists(public_path((string) $cemetery->primary_photo_path));
        }
    }

    /**
     * The 10 fictional rows are seeded into every environment including
     * CI — a standard `RefreshDatabase` test already has them with a
     * `.jpg` path, because the migration that originally seeds them
     * (`2026_07_26_210000_...`) calls the SAME, now-updated
     * `CemeteryExampleData::applyBackfill()`. That makes a bare "is it a
     * .jpg after up()" assertion pass even if THIS migration's own up()
     * body did nothing — so every fictional row is force-reset to an old
     * illustration SVG first, isolating what up() itself does: this is the
     * scenario an already-migrated production database is actually in
     * (seeded months ago against the old SVG-cycling constant), which is
     * the entire reason this migration re-runs applyBackfill() instead of
     * relying on the original seed migration alone.
     */
    public function test_up_gives_every_fictional_seeded_row_a_real_jpg_photo_path_too(): void
    {
        Cemetery::query()->whereIn('slug', CemeteryExampleData::slugs())
            ->update(['primary_photo_path' => 'images/cemeteries/illustration-01-gate.svg']);

        $this->migration()->up();

        $cemeteries = Cemetery::query()->whereIn('slug', CemeteryExampleData::slugs())->get();

        $this->assertSame(10, $cemeteries->count());

        foreach ($cemeteries as $cemetery) {
            $this->assertNotNull($cemetery->primary_photo_path, "slug [{$cemetery->slug}] missing primary_photo_path after backfill");
            $this->assertStringStartsWith('images/cemeteries/', (string) $cemetery->primary_photo_path);
            $this->assertStringEndsWith('.jpg', (string) $cemetery->primary_photo_path);
            $this->assertFileExists(public_path((string) $cemetery->primary_photo_path));
        }
    }

    public function test_down_restores_the_original_round_robin_illustration_for_both_groups(): void
    {
        $this->makeRealCemetery('tpu-karet-bivak', 'TPU Karet Bivak');
        $this->makeRealCemetery('tpu-petamburan', 'TPU Petamburan');

        $migration = $this->migration();
        $migration->up();
        $migration->down();

        $karetBivak = Cemetery::query()->where('slug', 'tpu-karet-bivak')->firstOrFail();
        $petamburan = Cemetery::query()->where('slug', 'tpu-petamburan')->firstOrFail();

        $this->assertStringEndsWith('.svg', (string) $karetBivak->primary_photo_path, 'down() must restore an illustration, not leave a photo behind');
        $this->assertStringEndsWith('.svg', (string) $petamburan->primary_photo_path);
        $this->assertNotSame(
            $karetBivak->primary_photo_path,
            $petamburan->primary_photo_path,
            'down() must restore the original per-index round-robin, not the same illustration for every row'
        );

        $exampleCemetery = Cemetery::query()->where('slug', CemeteryExampleData::slugs()[0])->firstOrFail();
        $this->assertStringEndsWith('.svg', (string) $exampleCemetery->primary_photo_path);
    }
}

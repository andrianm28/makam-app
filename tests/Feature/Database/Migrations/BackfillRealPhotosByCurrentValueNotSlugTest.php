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
 * `2026_09_13_100000_backfill_real_photos_by_current_value_not_slug.php`.
 *
 * ---------------------------------------------------------------------------
 * The gap this file exists to close
 * ---------------------------------------------------------------------------
 * `BackfillRealPhotosForRealAndExampleCemeteriesTest` — the sibling covering
 * the 08 Sep migration — is green, and was green while that migration
 * attached zero photos on the dev environment. It is not a bad test; it is a
 * test that builds the world its subject expects. Every fixture it creates
 * carries one of the four hardcoded real slugs or a
 * `CemeteryExampleData::slugs()` slug, so the slug predicate always matches
 * and the failure mode "no row in this database has any of those slugs" is
 * structurally unreachable from inside it.
 *
 * So the assertion that matters most here is the one whose fixtures use
 * slugs belonging to NEITHER list:
 * `test_it_backfills_rows_whose_slugs_are_in_neither_hardcoded_list`. That is
 * the real dev data shape, and it is what nothing previously asserted.
 */
final class BackfillRealPhotosByCurrentValueNotSlugTest extends TestCase
{
    use RefreshDatabase;

    private const string MIGRATION_PATH = 'database/migrations/2026_09_13_100000_backfill_real_photos_by_current_value_not_slug.php';

    /** The real dev-environment shape: descriptive slugs from an older seeder. */
    private const array SLUGS_IN_NEITHER_LIST = [
        'tpu-bekasi-jatiasih',
        'tps-bogor-cimanggu',
        'tpu-depok-sawangan',
        'tps-jakarta-kemang',
        'tpu-tangerang-cipondoh',
    ];

    private function migration(): Migration
    {
        return require base_path(self::MIGRATION_PATH);
    }

    private function makeCemetery(string $slug, ?string $photoPath): Cemetery
    {
        return Cemetery::create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'name' => 'Contoh '.$slug,
            'slug' => $slug,
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh Fixture No. 1',
            'primary_photo_path' => $photoPath,
        ]);
    }

    private function isRealPhoto(?string $path): bool
    {
        return is_string($path) && str_ends_with($path, '.jpg');
    }

    /**
     * THE regression assertion. Slugs here appear in neither hardcoded list,
     * so the 08 Sep migration is a guaranteed no-op against them — which is
     * exactly what happened on dev.
     */
    public function test_it_backfills_rows_whose_slugs_are_in_neither_hardcoded_list(): void
    {
        foreach (self::SLUGS_IN_NEITHER_LIST as $index => $slug) {
            $this->makeCemetery($slug, 'images/cemeteries/illustration-0'.($index % 4 + 1).'-gate.svg');
        }

        $this->migration()->up();

        foreach (self::SLUGS_IN_NEITHER_LIST as $slug) {
            $path = Cemetery::query()->where('slug', $slug)->value('primary_photo_path');

            $this->assertTrue(
                $this->isRealPhoto($path),
                "Cemetery [{$slug}] still shows [{$path}] — a slug-keyed backfill would have skipped it entirely."
            );
        }
    }

    public function test_it_backfills_a_row_whose_photo_path_is_null(): void
    {
        $this->makeCemetery('tpu-tanpa-foto', null);

        $this->migration()->up();

        $this->assertTrue($this->isRealPhoto(
            Cemetery::query()->where('slug', 'tpu-tanpa-foto')->value('primary_photo_path')
        ));
    }

    /**
     * The guard that makes this migration safe to keep re-running: a genuine
     * photograph an operator uploaded must never be replaced by a stock one.
     */
    public function test_it_leaves_a_row_that_already_carries_a_real_photo_untouched(): void
    {
        $operatorPhoto = 'images/cemeteries/foto-asli-operator.jpg';
        $this->makeCemetery('tpu-foto-operator', $operatorPhoto);

        $this->migration()->up();

        $this->assertSame(
            $operatorPhoto,
            Cemetery::query()->where('slug', 'tpu-foto-operator')->value('primary_photo_path'),
            'An operator-uploaded photograph must survive the backfill.'
        );
    }

    public function test_it_is_idempotent(): void
    {
        $this->makeCemetery('tpu-idempoten', 'images/cemeteries/illustration-01-gate.svg');

        $this->migration()->up();
        $first = Cemetery::query()->where('slug', 'tpu-idempoten')->value('primary_photo_path');

        $this->migration()->up();
        $second = Cemetery::query()->where('slug', 'tpu-idempoten')->value('primary_photo_path');

        $this->assertSame($first, $second);
    }

    /**
     * Deterministic by SLUG order, not by insertion order — so the same
     * database always renders the same photograph for the same cemetery,
     * including after a restore from a dump that re-inserted rows in a
     * different physical order.
     *
     * This assertion pins the whole sequence rather than merely checking
     * that neighbours differ. An earlier version of this test only asserted
     * "adjacent slugs get different photos", and that version PASSED when
     * the migration was mutated to `orderBy('id')` — the mutation still
     * produced distinct neighbours, just the wrong ones. Distinctness is not
     * determinism; the cycle position is what has to be pinned.
     */
    public function test_assignment_follows_slug_order_not_insertion_order(): void
    {
        // Every pre-existing seeded row is forced into the target set too, so
        // this asserts over the COMPLETE ordered set the migration processes,
        // not over an island of fixtures whose absolute cycle positions would
        // depend on how many other rows happened to be seeded.
        Cemetery::query()->update(['primary_photo_path' => 'images/cemeteries/illustration-01-gate.svg']);

        // Inserted in an order deliberately unlike their alphabetical order,
        // so `orderBy('id')` and `orderBy('slug')` cannot agree by accident.
        foreach (['zzz-tpu-terakhir', 'aaa-tpu-pertama', 'mmm-tpu-tengah'] as $slug) {
            $this->makeCemetery($slug, 'images/cemeteries/illustration-01-gate.svg');
        }

        $this->migration()->up();

        $expectedCycle = [
            'images/cemeteries/photo-01-jakarta-memorial.jpg',
            'images/cemeteries/photo-02-grid-colorful.jpg',
            'images/cemeteries/photo-03-river-divide.jpg',
            'images/cemeteries/photo-04-red-green-grid.jpg',
        ];

        $actual = Cemetery::query()->orderBy('slug')->pluck('primary_photo_path', 'slug');

        $position = 0;
        foreach ($actual as $slug => $path) {
            $this->assertSame(
                $expectedCycle[$position % 4],
                $path,
                "Cemetery [{$slug}] is at slug-sorted position {$position} and must therefore carry "
                ."[{$expectedCycle[$position % 4]}]. Getting a different photo here means the "
                .'migration is not ordering by slug.'
            );
            $position++;
        }

        $this->assertGreaterThan(4, $position, 'Precondition: more rows than photos, so the cycle actually wraps.');
    }

    /**
     * The canonical example slugs must ALSO be covered — this migration
     * widens the net, it does not move it off the rows the 08 Sep migration
     * already targeted.
     */
    public function test_it_also_covers_the_canonical_example_slugs(): void
    {
        // Seeded by the example-data migrations already, so this UPDATES the
        // existing row rather than creating one — which is also the realistic
        // shape: the canonical rows exist, they are simply still showing an
        // illustration.
        $slug = CemeteryExampleData::slugs()[0];
        Cemetery::query()->where('slug', $slug)
            ->update(['primary_photo_path' => 'images/cemeteries/illustration-02-grove.svg']);

        $this->assertNotNull(
            Cemetery::query()->where('slug', $slug)->first(),
            "Fixture precondition: the canonical example cemetery [{$slug}] must already be seeded."
        );

        $this->migration()->up();

        $this->assertTrue($this->isRealPhoto(
            Cemetery::query()->where('slug', $slug)->value('primary_photo_path')
        ));
    }
}

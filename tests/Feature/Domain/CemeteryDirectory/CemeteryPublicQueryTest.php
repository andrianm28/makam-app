<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `CemeteryPublicQuery` is this module's single public read interface — every
 * public directory read composes it, and `Cemetery`'s own doc block makes
 * that AC2's base guarantee. It had no test file of its own: its only direct
 * assertions lived in `GraveRegistry`'s and `Booking`'s suites, so this
 * module's suite would have stayed green if the class were deleted and
 * inlined. `AGENTS.md` §Testing ("Every traceability item marked `Covered`
 * needs test evidence") is the rule that makes that a gap rather than a
 * stylistic preference — `tasks.md` marks the projection task `[x]` naming
 * this class as the read path, and cited three route tests that exercise it
 * only transitively.
 *
 * The return-shape assertions on `launchCities()`/`types()` are the point of
 * this file, not filler. Those are the exact two methods whose
 * `list<array{code: string, label: string}>` shape `ba662d1` mismatched —
 * `index.blade.php` destructured them as `$code => $label`, a string-keyed
 * map, and the docblock that said otherwise was checked by nothing. This
 * asserts the shape structurally (list, exact keys, string values), which is
 * the assertion that would have failed the moment the two sides disagreed.
 */
final class CemeteryPublicQueryTest extends TestCase
{
    use RefreshDatabase;

    private const DRAFT_SLUG = 'tps-bekasi-harapan-indah';

    /**
     * @param  list<array{code: string, label: string}>  $options
     */
    private function assertIsCodeLabelList(array $options, string $method): void
    {
        $this->assertTrue(array_is_list($options), "{$method}() must return a list, not a keyed map.");
        $this->assertNotEmpty($options, "{$method}() returning nothing would make every assertion below vacuous.");

        foreach ($options as $index => $option) {
            $this->assertIsArray($option, "{$method}()[{$index}] must be an array.");
            $this->assertSame(
                ['code', 'label'],
                array_keys($option),
                "{$method}()[{$index}] must carry exactly the keys `code` and `label`, in that order."
            );
            $this->assertIsString($option['code']);
            $this->assertIsString($option['label']);
            $this->assertNotSame('', $option['code']);
            $this->assertNotSame('', $option['label']);
        }
    }

    public function test_launch_cities_returns_a_list_of_code_label_pairs(): void
    {
        $cities = CemeteryPublicQuery::launchCities();

        $this->assertIsCodeLabelList($cities, 'launchCities');

        // AC1 + `AGENTS.md` §Mandatory MVP UX — all five, in the canonical
        // order `LaunchCityCode` defines, never a subset derived from which
        // cities happen to have published rows today.
        $this->assertSame(
            LaunchCityCode::KNOWN_CODES,
            array_column($cities, 'code')
        );
    }

    public function test_types_returns_a_list_of_code_label_pairs(): void
    {
        $types = CemeteryPublicQuery::types();

        $this->assertIsCodeLabelList($types, 'types');

        $this->assertSame(
            CemeteryType::KNOWN_TYPES,
            array_column($types, 'code')
        );
    }

    public function test_published_returns_only_published_cemeteries(): void
    {
        $draft = Cemetery::query()->where('slug', self::DRAFT_SLUG)->firstOrFail();
        $this->assertSame(CemeteryPublicationStatus::DRAFT, $draft->publication_status);

        $slugs = CemeteryPublicQuery::published()->pluck('slug');

        $this->assertNotContains(self::DRAFT_SLUG, $slugs);
        $this->assertSame(
            Cemetery::query()->published()->count(),
            $slugs->count()
        );
    }

    public function test_published_respects_the_city_filter(): void
    {
        $expected = Cemetery::query()
            ->published()
            ->inCity(LaunchCityCode::BOGOR)
            ->pluck('slug')
            ->sort()
            ->values();

        $this->assertGreaterThan(0, $expected->count());

        $actual = CemeteryPublicQuery::published(city: LaunchCityCode::BOGOR)
            ->pluck('slug')
            ->sort()
            ->values();

        $this->assertSame($expected->all(), $actual->all());
    }

    public function test_published_respects_the_type_filter(): void
    {
        $expected = Cemetery::query()
            ->published()
            ->ofType(CemeteryType::TPU)
            ->pluck('slug')
            ->sort()
            ->values();

        $this->assertGreaterThan(0, $expected->count());

        $actual = CemeteryPublicQuery::published(type: CemeteryType::TPU)
            ->pluck('slug')
            ->sort()
            ->values();

        $this->assertSame($expected->all(), $actual->all());
    }

    public function test_published_applies_both_filters_together(): void
    {
        $cemeteries = CemeteryPublicQuery::published(
            city: LaunchCityCode::JAKARTA,
            type: CemeteryType::TPU,
        );

        $this->assertGreaterThan(0, $cemeteries->count());

        foreach ($cemeteries as $cemetery) {
            $this->assertSame(LaunchCityCode::JAKARTA, $cemetery->city);
            $this->assertSame(CemeteryType::TPU, $cemetery->type);
            $this->assertTrue($cemetery->isPublished());
        }
    }

    public function test_find_published_by_slug_returns_the_published_cemetery(): void
    {
        $expected = Cemetery::query()->published()->firstOrFail();

        $found = CemeteryPublicQuery::findPublishedBySlug($expected->slug);

        $this->assertNotNull($found);
        $this->assertSame($expected->id, $found->id);
    }

    /**
     * The 404-discipline guarantee `CemeteryDetail` relies on: a draft
     * cemetery's slug and a slug that does not exist must be indistinguishable
     * from outside, so a caller cannot use the response to learn that an
     * unpublished record exists.
     */
    public function test_find_published_by_slug_returns_null_for_a_draft_and_for_a_missing_slug(): void
    {
        $this->assertNull(CemeteryPublicQuery::findPublishedBySlug(self::DRAFT_SLUG));
        $this->assertNull(CemeteryPublicQuery::findPublishedBySlug('no-such-cemetery-slug'));
    }

    public function test_find_published_by_id_returns_the_published_cemetery(): void
    {
        $expected = Cemetery::query()->published()->firstOrFail();

        $found = CemeteryPublicQuery::findPublishedById((string) $expected->id);

        $this->assertNotNull($found);
        $this->assertSame($expected->slug, $found->slug);
    }

    public function test_find_published_by_id_returns_null_for_a_draft_cemeterys_id(): void
    {
        $draft = Cemetery::query()->where('slug', self::DRAFT_SLUG)->firstOrFail();

        $this->assertNull(CemeteryPublicQuery::findPublishedById((string) $draft->id));
    }

    /**
     * The UUID-shape guard, asserted directly rather than transitively.
     * `cemeteries.id` is a real `uuid` column on PostgreSQL, so a non-UUID
     * comparison is a database TYPE ERROR, not a miss — without the guard a
     * tampered query string turns a public screen into a 500. It passes
     * silently on SQLite, which is exactly why it needs an assertion that
     * does not depend on the driver raising: this asserts the clean `null`
     * on both drivers, so removing the guard fails here on PostgreSQL (with
     * the type error) rather than only in production.
     */
    public function test_find_published_by_id_returns_null_for_a_malformed_id_without_querying(): void
    {
        $this->assertNull(CemeteryPublicQuery::findPublishedById('garbage'));
        $this->assertNull(CemeteryPublicQuery::findPublishedById(''));
        $this->assertNull(CemeteryPublicQuery::findPublishedById('   '));
        $this->assertNull(CemeteryPublicQuery::findPublishedById('00000000-0000-0000-0000-00000000000'));
    }

    public function test_find_published_by_id_returns_null_for_a_well_formed_but_unknown_uuid(): void
    {
        $this->assertNull(CemeteryPublicQuery::findPublishedById('11111111-2222-4333-8444-555555555555'));
    }

    /**
     * `inCity()`'s documented posture for an unknown code: an empty
     * collection, never an exception — callers are public screens reading a
     * user-supplied value.
     */
    public function test_in_city_returns_an_empty_collection_for_an_unknown_city_code(): void
    {
        $this->assertCount(0, CemeteryPublicQuery::inCity('SURABAYA'));
        $this->assertGreaterThan(0, CemeteryPublicQuery::inCity(LaunchCityCode::JAKARTA)->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Support\ExampleData;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Support\ExampleData\CemeteryExampleData;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Locks the generator's INTERNAL consistency. The DB-facing shape contract
 * (ten cemeteries, nine published, one draft, the package spread, the
 * grave-record spread) is asserted by the existing feature suite
 * (CemeterySeedTest, GraveRecordSeedTest, CemeteryPackageAvailabilityTest)
 * and must stay there — those numbers are the fixture-design contract, not
 * derivations. This file asserts the cross-array invariants that would
 * otherwise be silent if a slug drifted between the four methods.
 */
final class CemeteryExampleDataTest extends TestCase
{
    public function test_every_referenced_slug_exists_in_cemeteries(): void
    {
        $slugs = array_column(CemeteryExampleData::cemeteries(), 2);

        $this->assertSame(count($slugs), count(array_unique($slugs)), 'Cemetery slugs must be unique.');

        foreach (array_merge(
            CemeteryExampleData::packages(),
            CemeteryExampleData::backfills(),
            CemeteryExampleData::graveRecords(),
        ) as $row) {
            $this->assertContains(
                $row[0],
                $slugs,
                "Slug [{$row[0]}] is referenced by example data but not defined in cemeteries()."
            );
        }
    }

    public function test_exactly_one_cemetery_is_deliberately_draft(): void
    {
        $draft = array_filter(
            CemeteryExampleData::cemeteries(),
            static fn (array $c): bool => $c[7] !== CemeteryPublicationStatus::PUBLISHED,
        );

        $this->assertCount(1, $draft);
        $this->assertSame(CemeteryExampleData::DRAFT_SLUG, reset($draft)[2]);
    }

    public function test_the_all_restricted_role_cemetery_has_only_restricted_grave_records(): void
    {
        $records = array_filter(
            CemeteryExampleData::graveRecords(),
            static fn (array $r): bool => $r[0] === CemeteryExampleData::ALL_RESTRICTED_SLUG,
        );

        $this->assertNotEmpty($records, 'The all-restricted fixture needs at least one grave record.');

        foreach ($records as $record) {
            $this->assertTrue(
                $record[5] === GraveRecordAccessMode::LIMITED || $record[5] === GraveRecordAccessMode::CLOSED,
                'Every record in the all-restricted cemetery must be privacy-limited.'
            );
        }
    }

    public function test_the_draft_cemetery_has_at_least_one_grave_record(): void
    {
        $records = array_filter(
            CemeteryExampleData::graveRecords(),
            static fn (array $r): bool => $r[0] === CemeteryExampleData::DRAFT_SLUG,
        );

        $this->assertNotEmpty($records, 'The draft cemetery needs a record so the negative fixture stays reachable.');
    }

    public function test_backfills_cover_every_cemetery_exactly_once(): void
    {
        $backfillSlugs = array_column(CemeteryExampleData::backfills(), 0);

        $this->assertSame(
            array_column(CemeteryExampleData::cemeteries(), 2),
            $backfillSlugs,
            'backfills() must cover every cemetery, in the same order.'
        );
    }

    public function test_by_slug_returns_the_expected_row_and_rejects_unknown_slugs(): void
    {
        $this->assertSame(
            CemeteryExampleData::DRAFT_SLUG,
            CemeteryExampleData::bySlug(CemeteryExampleData::DRAFT_SLUG)[2],
        );

        $this->expectException(InvalidArgumentException::class);
        CemeteryExampleData::bySlug('no-such-example-cemetery');
    }

    public function test_cemetery_names_are_synthetic_and_positional(): void
    {
        $names = array_column(CemeteryExampleData::cemeteries(), 1);

        $this->assertSame(10, count($names));
        // Generated, not literal: no real-sounding neighbourhood names.
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^(TPU|TPS) (Jakarta|Bogor|Depok|Tangerang|Bekasi) \d+$/', $name);
        }
    }

    public function test_roles_resolve_by_position(): void
    {
        $this->assertSame(CemeteryExampleData::DRAFT_SLUG, CemeteryExampleData::roleCemetery('draft')[2]);
        $this->assertSame(CemeteryExampleData::ALL_RESTRICTED_SLUG, CemeteryExampleData::roleCemetery('all-restricted')[2]);
        $this->assertSame(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0], CemeteryExampleData::roleCemetery('package', 0)[2]);
        $this->assertSame(CemeteryExampleData::OPEN_CEMETERY_SLUG, CemeteryExampleData::roleCemetery('open')[2]);
    }

    public function test_roles_resolve_uniquely(): void
    {
        $draft = CemeteryExampleData::roleCemetery('draft')[2];
        $restricted = CemeteryExampleData::roleCemetery('all-restricted')[2];
        $this->assertNotSame($draft, $restricted);
        $this->assertNotSame($draft, CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0]);
        $this->assertNotSame($restricted, CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0]);
    }

    public function test_coordinates_are_never_fabricated(): void
    {
        foreach (CemeteryExampleData::backfills() as $backfill) {
            // Shape: [slug, latitude, longitude, maps_url, price_min, price_max, photo]
            $this->assertNull($backfill[1]);
            $this->assertNull($backfill[2]);
        }
    }
}

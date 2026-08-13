<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\GraveRegistry;

use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\GraveRecordProjection;
use App\Domain\GraveRegistry\GraveRegistryPublicQuery;
use App\Domain\GraveRegistry\GraveSearchCriteria;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionProperty;
use Tests\Support\CemeteryFixture;
use Tests\TestCase;

/**
 * `App\Domain\GraveRegistry\GraveRegistryPublicQuery` — Sprint 4 S4-T7,
 * `.kiro/specs/renewal-and-grave-registry` AC3 (search by name, block,
 * death date), AC5 (empty result) and AC14 (open/limited/closed access
 * modes). Also asserts `design.md`'s Privacy rule: "Public results must
 * only return fields allowed by configured access mode."
 *
 * Data comes from the seed migrations `RefreshDatabase` runs before every
 * method — `2026_07_26_190300_seed_cemeteries_and_capability_profiles.php`
 * and `2026_08_08_100010_seed_example_grave_records.php`. No factories:
 * `database/factories/` holds only `UserFactory`, and `makam-testing`
 * forbids adding a domain one.
 *
 * ---------------------------------------------------------------------------
 * Why most assertions here filter by BLOCK rather than by name
 * ---------------------------------------------------------------------------
 * Not arbitrary. On PostgreSQL the name branch is `LIKE` **OR**
 * `similarity() >= 0.3`, and which extra rows that second half pulls in is
 * a property of `pg_trgm`'s scoring, not of this repository. A test that
 * asserted an exact result COUNT for a name term would be asserting
 * pg_trgm's tuning and would legitimately differ between the local SQLite
 * run and the Postgres CI run. Block and death-date filters are exact
 * equality on both drivers, so they pin down exactly one seeded row and the
 * assertion means the same thing everywhere. The genuinely trigram-specific
 * behaviour is tested separately, driver-guarded, in
 * `GraveRecordTrigramSearchTest`.
 */
final class GraveRegistryPublicQueryTest extends TestCase
{
    use RefreshDatabase;

    // =====================================================================
    // AC14 — the three access modes and their field projections
    // =====================================================================

    public function test_an_open_record_projects_every_publicly_allowed_field(): void
    {
        $cemeteryId = CemeteryFixture::id('package', 0);

        // The first seeded record of the package cemetery is OPEN. Asserted
        // against the stored row rather than a literal name/date so the
        // projection is pinned to the DATA, not to a generated value.
        $record = GraveRecord::query()
            ->where('cemetery_id', $cemeteryId)
            ->orderBy('block')
            ->firstOrFail();

        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: $cemeteryId,
            block: (string) $record->block,
        ));

        $this->assertCount(1, $outcome->openResults);
        $this->assertSame([], $outcome->restrictedResults);

        $row = $outcome->openResults[0];

        $this->assertSame(GraveRecordAccessMode::OPEN, $row->accessMode);
        $this->assertFalse($row->isRestricted());
        $this->assertSame($record->deceased_name, $row->deceasedName);
        $this->assertSame(CemeteryExampleData::bySlug(CemeteryExampleData::PACKAGE_CEMETERY_SLUGS[0])[1], $row->cemeteryName);
        $this->assertSame((string) $record->block, $row->block);
        $this->assertSame($record->death_date?->format('Y-m-d'), $row->deathDate);
        $this->assertSame($record->due_date?->format('Y-m-d'), $row->dueDate);
    }

    /**
     * `limited` shows the LOCATION and withholds the identity and the
     * dates — enough for a family to recognise the record and quote
     * something concrete to a customer-service agent, without disclosing
     * the deceased's name to whoever typed a search term.
     *
     * The `limited` fixture lives in the all-restricted example cemetery
     * (`CemeteryExampleData::ALL_RESTRICTED_SLUG`): its first seeded record
     * is `limited`, its second `closed` (locked by
     * `GraveRecordSeedTest::test_seeded_record_counts_by_role_are_explicit`).
     */
    public function test_a_limited_record_projects_only_its_location(): void
    {
        $cemeteryId = CemeteryFixture::id('all-restricted');

        $record = GraveRecord::query()
            ->where('cemetery_id', $cemeteryId)
            ->orderBy('block')
            ->firstOrFail();

        $this->assertSame(GraveRecordAccessMode::LIMITED, $record->access_mode);

        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: $cemeteryId,
            block: (string) $record->block,
        ));

        $this->assertSame([], $outcome->openResults);
        $this->assertCount(1, $outcome->restrictedResults);

        $row = $outcome->restrictedResults[0];

        $this->assertSame(GraveRecordAccessMode::LIMITED, $row->accessMode);
        $this->assertTrue($row->isRestricted());
        $this->assertSame(CemeteryExampleData::bySlug(CemeteryExampleData::ALL_RESTRICTED_SLUG)[1], $row->cemeteryName);
        $this->assertSame((string) $record->block, $row->block);

        // Withheld — and genuinely absent from the value object, not merely
        // unrendered by a template.
        $this->assertNull($row->deceasedName);
        $this->assertNull($row->deathDate);
        $this->assertNull($row->dueDate);
    }

    public function test_a_closed_record_projects_no_fields_at_all(): void
    {
        $cemeteryId = CemeteryFixture::id('all-restricted');

        $record = GraveRecord::query()
            ->where('cemetery_id', $cemeteryId)
            ->orderBy('block')
            ->skip(1)
            ->firstOrFail();

        $this->assertSame(GraveRecordAccessMode::CLOSED, $record->access_mode);

        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: $cemeteryId,
            block: (string) $record->block,
        ));

        $this->assertSame([], $outcome->openResults);
        $this->assertCount(1, $outcome->restrictedResults);

        $row = $outcome->restrictedResults[0];

        $this->assertSame(GraveRecordAccessMode::CLOSED, $row->accessMode);
        $this->assertNull($row->deceasedName);
        $this->assertNull($row->cemeteryName);
        $this->assertNull($row->block);
        $this->assertNull($row->deathDate);
        $this->assertNull($row->dueDate);
    }

    /**
     * THE load-bearing assertion of this whole spec. A `closed` record is
     * MATCHED and COUNTED, never silently dropped — dropping it would make
     * the outcome report `isNoResult()`, and the screen would then tell a
     * family their relative's grave is not in the registry when it plainly
     * is. `GraveRecordAccessMode`'s own doc block calls this out as the
     * defect the spec names.
     */
    public function test_a_closed_record_is_still_counted_and_never_reported_as_not_found(): void
    {
        $cemeteryId = CemeteryFixture::id('all-restricted');

        $record = GraveRecord::query()
            ->where('cemetery_id', $cemeteryId)
            ->orderBy('block')
            ->skip(1)
            ->firstOrFail();

        $this->assertSame(GraveRecordAccessMode::CLOSED, $record->access_mode);

        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: $cemeteryId,
            block: (string) $record->block,
        ));

        $this->assertSame(1, $outcome->matchCount());
        $this->assertSame(1, $outcome->restrictedCount());
        $this->assertFalse($outcome->isNoResult());
        $this->assertTrue($outcome->isPrivacyLimited());
    }

    // =====================================================================
    // The two search-driven empty states, kept apart
    // =====================================================================

    public function test_a_search_matching_nothing_is_no_result_and_not_privacy_limited(): void
    {
        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: CemeteryFixture::id('package', 0),
            block: 'ZZ-99',
        ));

        $this->assertSame(0, $outcome->matchCount());
        $this->assertTrue($outcome->isNoResult());
        $this->assertFalse($outcome->isPrivacyLimited());
        $this->assertFalse($outcome->hasOpenResults());
    }

    /**
     * The all-restricted example cemetery (`CemeteryExampleData::
     * ALL_RESTRICTED_SLUG`) is seeded with every one of its records
     * restricted, precisely so this state is reachable from seed data alone
     * — see the generator's fixture-design note.
     */
    public function test_a_cemetery_whose_matches_are_all_restricted_is_privacy_limited_not_no_result(): void
    {
        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: CemeteryFixture::id('all-restricted'),
            name: 'Contoh',
        ));

        $this->assertSame(2, $outcome->matchCount());
        $this->assertSame(2, $outcome->restrictedCount());

        // The distinction this whole spec turns on:
        $this->assertFalse($outcome->isNoResult());
        $this->assertTrue($outcome->isPrivacyLimited());
        $this->assertFalse($outcome->hasOpenResults());
    }

    /**
     * A mixed search reports BOTH facts. Under-reporting the restricted
     * match — showing one row and staying silent about the second — would
     * be a quieter version of the same defect.
     *
     * The generator seeds the package cemetery with only OPEN records (the
     * all-restricted cemetery is deliberately the pure restricted fixture),
     * so the mixed state is made locally: the second seeded record is
     * demoted to `limited`, then the search must report one readable row
     * and the restricted match together.
     */
    public function test_a_mixed_search_reports_readable_rows_and_the_restricted_match_together(): void
    {
        $cemeteryId = $this->makeThePackageCemeteryMixed();

        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: $cemeteryId,
            name: 'Contoh',
        ));

        $this->assertSame(2, $outcome->matchCount());
        $this->assertCount(1, $outcome->openResults);
        $this->assertSame(1, $outcome->restrictedCount());

        $this->assertTrue($outcome->hasOpenResults());
        $this->assertTrue($outcome->isPrivacyLimited());
        $this->assertFalse($outcome->isNoResult());
    }

    // =====================================================================
    // AC3 — the three search inputs
    // =====================================================================

    public function test_a_name_term_matches_regardless_of_case_and_punctuation(): void
    {
        $cemeteryId = CemeteryFixture::id('package', 0);

        $name = (string) GraveRecord::query()
            ->where('cemetery_id', $cemeteryId)
            ->orderBy('block')
            ->firstOrFail()->deceased_name;

        foreach ([strtolower($name), strtoupper($name), str_replace(' ', '  ', $name)] as $term) {
            $outcome = GraveRegistryPublicQuery::search(
                GraveSearchCriteria::make(cemeteryId: $cemeteryId, name: $term)
            );

            $names = array_map(
                static fn (GraveRecordProjection $row): ?string => $row->deceasedName,
                $outcome->openResults
            );

            $this->assertContains($name, $names, "Term [{$term}] should match the seeded record.");
        }
    }

    public function test_a_death_date_filter_narrows_to_the_matching_record(): void
    {
        $cemeteryId = CemeteryFixture::id('package', 0);

        $record = GraveRecord::query()
            ->where('cemetery_id', $cemeteryId)
            ->orderBy('block')
            ->firstOrFail();

        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: $cemeteryId,
            deathDate: $record->death_date->format('Y-m-d'),
        ));

        $this->assertSame(1, $outcome->matchCount());
        $this->assertSame($record->deceased_name, $outcome->openResults[0]->deceasedName);
    }

    public function test_name_and_block_are_combined_not_alternated(): void
    {
        // The package cemetery's first record ("Contoh Sejahtera 1"-style
        // generated name) lives in its first block. Asking for that name in
        // another block must not return it: an OR between the two filters
        // would, an AND does not.
        //
        // Asserted as "this record is absent" rather than "the result set
        // is empty" on purpose. On PostgreSQL the name half of the filter
        // also runs a similarity() comparison, and asserting an exact count
        // here would be asserting pg_trgm's scoring rather than this
        // query's AND/OR structure — see this class's own doc block.
        $cemeteryId = CemeteryFixture::id('package', 0);

        $record = GraveRecord::query()
            ->where('cemetery_id', $cemeteryId)
            ->orderBy('block')
            ->firstOrFail();

        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: $cemeteryId,
            name: (string) $record->deceased_name,
            block: 'ZZ-01',
        ));

        $names = array_map(
            static fn (GraveRecordProjection $row): ?string => $row->deceasedName,
            $outcome->openResults
        );

        $this->assertNotContains((string) $record->deceased_name, $names);
    }

    // =====================================================================
    // Scoping and refusals
    // =====================================================================

    /**
     * Cross-scope denial, the genre `ScopeAssignmentGlobalScopeTest`
     * establishes: same query, only the cemetery identifier changes. The
     * package cemetery's first block (`CemeteryExampleData::
     * PACKAGE_CEMETERY_SLUGS[0]`) must not be reachable by asking a
     * different cemetery for it.
     */
    public function test_a_search_never_reaches_a_record_in_another_cemetery(): void
    {
        $packageBlock = (string) GraveRecord::query()
            ->where('cemetery_id', CemeteryFixture::id('package', 0))
            ->orderBy('block')
            ->firstOrFail()->block;

        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: CemeteryFixture::id('all-restricted'),
            block: $packageBlock,
        ));

        $this->assertSame(0, $outcome->matchCount());
    }

    /**
     * A DRAFT cemetery's records are not public data, and the query itself
     * must say so — not the screen that happens to call it today.
     *
     * The deliberately-draft example cemetery (`CemeteryExampleData::
     * DRAFT_SLUG`) holds exactly one grave record, seeded `open`
     * mode on purpose so this is provable rather than vacuous: without the
     * publication-status filter this same search returns that row as a
     * fully-populated `open` projection — deceased name, block, death date,
     * due date — for a cemetery no public directory will list.
     *
     * The sibling test at `GraveSearchStatesTest::
     * test_a_draft_cemetery_cannot_be_searched_through_a_held_url` proves
     * the SCREEN refuses it. This proves the QUERY refuses it, which is the
     * half that was missing: a second caller would not have inherited the
     * screen's check.
     *
     * Block rather than name as the search term, per this class's own doc
     * block — exact equality means the same thing on SQLite and PostgreSQL.
     */
    public function test_a_search_never_reaches_a_record_in_an_unpublished_cemetery(): void
    {
        $draftCemeteryId = CemeteryFixture::id('draft');

        // The fixture must really be there, or the assertions below pass for
        // the wrong reason.
        $this->assertSame(
            1,
            GraveRecord::query()->where('cemetery_id', $draftCemeteryId)->count(),
            'The draft cemetery must still hold its one seeded grave record for this test to mean anything.'
        );

        $draftBlock = (string) GraveRecord::query()
            ->where('cemetery_id', $draftCemeteryId)
            ->orderBy('block')
            ->firstOrFail()->block;

        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: $draftCemeteryId,
            block: $draftBlock,
        ));

        // Not merely "no readable rows": no rows at all. A restricted-shaped
        // outcome would still disclose that a matching record exists in a
        // cemetery that is not published.
        $this->assertSame(0, $outcome->matchCount());
        $this->assertSame([], $outcome->openResults);
        $this->assertSame([], $outcome->restrictedResults);
    }

    /**
     * A blank search would match the whole cemetery's registry, which is a
     * bulk disclosure rather than a search. Refused before any query runs.
     */
    public function test_a_search_with_no_terms_returns_nothing_and_never_dumps_the_registry(): void
    {
        $outcome = GraveRegistryPublicQuery::search(
            GraveSearchCriteria::make(cemeteryId: CemeteryFixture::id('package', 0))
        );

        $this->assertSame(0, $outcome->matchCount());
        $this->assertSame([], $outcome->openResults);
        $this->assertSame([], $outcome->restrictedResults);
    }

    public function test_a_search_with_no_cemetery_returns_nothing(): void
    {
        $outcome = GraveRegistryPublicQuery::search(
            GraveSearchCriteria::make(cemeteryId: '', name: 'Contoh')
        );

        $this->assertSame(0, $outcome->matchCount());
    }

    /**
     * A tampered `?tpu=` must return nothing rather than THROW.
     * `grave_records.cemetery_id` is a real `uuid` column on PostgreSQL, so
     * comparing it against a non-UUID string is a database type error, not
     * a miss — without the shape guard this would 500 a public search form.
     *
     * Note this test would pass on SQLite even with the guard removed
     * (SQLite compares the string happily and finds nothing), so it only
     * genuinely protects the behaviour on the CI/production driver. Written
     * down rather than left as a false sense of coverage.
     */
    public function test_a_malformed_cemetery_identifier_returns_nothing_instead_of_erroring(): void
    {
        foreach (['garbage', '../../etc/passwd', "' OR 1=1 --", '12345'] as $tampered) {
            $outcome = GraveRegistryPublicQuery::search(
                GraveSearchCriteria::make(cemeteryId: $tampered, name: 'Contoh')
            );

            $this->assertSame(0, $outcome->matchCount(), "Tampered identifier [{$tampered}] must match nothing.");
        }
    }

    // =====================================================================
    // Privacy invariants
    // =====================================================================

    /**
     * Structural, not behavioural — the same instinct as
     * `ClientSideTamperingCannotOpenAGateTest`'s reflection walk. `design.md`
     * Privacy: "Contact details must never appear in public search by
     * default." A `null` assertion would only prove today's seed rows leave
     * the column empty; this proves no access mode COULD project it,
     * because the value object has nowhere to put it.
     */
    public function test_no_access_mode_can_project_heir_contact_because_the_projection_has_no_such_property(): void
    {
        $properties = array_map(
            static fn (ReflectionProperty $property): string => $property->getName(),
            (new ReflectionClass(GraveRecordProjection::class))->getProperties()
        );

        $this->assertSame(
            ['accessMode', 'isExampleData', 'deceasedName', 'cemeteryName', 'block', 'deathDate', 'dueDate'],
            $properties
        );

        foreach ($properties as $name) {
            $this->assertStringNotContainsStringIgnoringCase('heir', $name);
            $this->assertStringNotContainsStringIgnoringCase('contact', $name);
            $this->assertStringNotContainsStringIgnoringCase('kontak', $name);
        }
    }

    /**
     * The query returns value objects, never models. A `GraveRecord`
     * escaping to a caller would carry every column including the withheld
     * ones, and "the Blade template just does not print it" is not a
     * privacy control.
     *
     * The count is anchored before the loop deliberately. A bare `foreach`
     * over a result set asserts ZERO times if that set is ever empty — so
     * if the fixture shrank, or the search silently stopped returning rows,
     * this test would keep passing while testing nothing. A test that can
     * assert zero times is not test evidence under `AGENTS.md` §Testing.
     *
     * Two is the same figure `test_a_mixed_search_reports_readable_rows_
     * and_the_restricted_match_together` pins for this identical search —
     * the package example cemetery's one `open` row plus its one locally
     * demoted `limited` row — so the two tests fail together rather than
     * one of them going quiet.
     */
    public function test_the_query_never_returns_an_eloquent_model(): void
    {
        $outcome = GraveRegistryPublicQuery::search(GraveSearchCriteria::make(
            cemeteryId: $this->makeThePackageCemeteryMixed(),
            name: 'Contoh',
        ));

        $rows = [...$outcome->openResults, ...$outcome->restrictedResults];

        $this->assertCount(2, $rows, 'Fixture anchor: without matched rows the loop below would assert nothing.');

        foreach ($rows as $row) {
            $this->assertInstanceOf(GraveRecordProjection::class, $row);
        }
    }

    /**
     * The generator seeds the package cemetery with only OPEN records, and
     * the all-restricted cemetery with only restricted ones — there is no
     * seeded "mixed" cemetery. Tests that need both facts in one outcome
     * demote the package cemetery's second record to `limited` locally, so
     * the mixed state is explicit and does not depend on the seed shape.
     *
     * @return string the package cemetery id, in its mixed state
     */
    private function makeThePackageCemeteryMixed(): string
    {
        $cemeteryId = CemeteryFixture::id('package', 0);

        GraveRecord::query()
            ->where('cemetery_id', $cemeteryId)
            ->orderBy('block')
            ->skip(1)
            ->firstOrFail()
            ->update(['access_mode' => GraveRecordAccessMode::LIMITED]);

        return $cemeteryId;
    }
}

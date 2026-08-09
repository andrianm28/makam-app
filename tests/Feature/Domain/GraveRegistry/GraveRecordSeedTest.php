<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\GraveRegistry;

use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\GraveNameNormalizer;
use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\GraveRecordSource;
use App\Domain\GraveRegistry\Models\GraveRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `2026_08_08_100010_seed_example_grave_records.php` — Sprint 4 S4-T7.
 *
 * Two jobs. First, protect the FIXTURE DESIGN the rest of this spec's tests
 * depend on: `makam-testing` forbids domain factories, so every access-mode
 * and empty-state assertion elsewhere is written against these exact seeded
 * rows. If the spread changes, those tests stop testing what their names
 * claim, and this file is where that gets caught.
 *
 * Second, protect the HONESTY MARKERS. This is fabricated data about
 * fictional deceased people, which is authorised for `dev.makam.co.id`
 * (`docs/operations/dev-staging-environment.md` §4) precisely because it is
 * unmistakably marked. `makam-migration`'s rule: "fake data must read as an
 * example at a glance, must never carry a specific false attribution."
 * Those markers are a control, so they are asserted, not assumed.
 */
final class GraveRecordSeedTest extends TestCase
{
    use RefreshDatabase;

    // =====================================================================
    // Honesty markers
    // =====================================================================

    public function test_every_seeded_record_is_marked_as_example_data(): void
    {
        $records = GraveRecord::query()->get();

        $this->assertSame(14, $records->count());

        foreach ($records as $record) {
            $this->assertSame(
                GraveRecordSource::CONTOH,
                $record->source,
                "Record [{$record->deceased_name}] must carry the example-data source marker."
            );
            $this->assertTrue($record->isExampleData());
        }
    }

    /**
     * The marker is on the NAME ITSELF, not only in the migration's doc
     * block. Reason, from that migration: an address that looks fake is
     * cosmetic, but a plausible-looking name on a grave registry could be
     * mistaken for a real deceased person by anyone querying this database
     * without reading the migration.
     */
    public function test_every_seeded_deceased_name_carries_the_contoh_marker(): void
    {
        foreach (GraveRecord::query()->pluck('deceased_name') as $name) {
            $this->assertStringStartsWith(
                'Contoh ',
                (string) $name,
                'Seeded grave-record names must be unmistakably fictional at a glance.'
            );
        }
    }

    /**
     * `AGENTS.md` §Authorization and files puts heir contact in the same
     * protected class as KTP/KK/death documents, and no encrypted-contact
     * storage exists yet (platform DocumentVault is Sprint 6). Inventing a
     * phone number for a fictional heir would be fabricated personal data,
     * not a placeholder.
     */
    public function test_no_seeded_record_stores_an_heir_contact(): void
    {
        $this->assertSame(
            0,
            GraveRecord::query()->whereNotNull('heir_contact_reference')->count()
        );
    }

    // =====================================================================
    // Fixture design the rest of the suite depends on
    // =====================================================================

    public function test_all_three_access_modes_are_reachable_from_seed_data(): void
    {
        foreach (GraveRecordAccessMode::KNOWN_MODES as $mode) {
            $this->assertGreaterThan(
                0,
                GraveRecord::query()->where('access_mode', $mode)->count(),
                "No seeded record uses access mode [{$mode}]; tests for that mode would be untestable."
            );
        }
    }

    /**
     * The *pure* privacy-limited state — matches exist, none readable — is
     * the one most at risk of being wrongly rendered as "not found". It
     * needs a cemetery where EVERY record is restricted, and this is it.
     */
    public function test_tps_jakarta_kemang_has_only_restricted_records(): void
    {
        $cemeteryId = Cemetery::query()->where('slug', 'tps-jakarta-kemang')->sole()->id;

        $records = GraveRecord::query()->where('cemetery_id', $cemeteryId)->get();

        $this->assertSame(2, $records->count());

        foreach ($records as $record) {
            $this->assertTrue(
                $record->isAccessRestricted(),
                'TPS Jakarta Kemang must contain no openly-readable record — it is the pure privacy-limited fixture.'
            );
        }
    }

    /**
     * The registry incompleteness AC5's empty-state copy tells the public
     * about must be real in the data, not only in the copy.
     */
    public function test_the_seed_includes_records_with_a_missing_date(): void
    {
        $this->assertGreaterThan(0, GraveRecord::query()->whereNull('death_date')->count());
        $this->assertGreaterThan(0, GraveRecord::query()->whereNull('due_date')->count());
    }

    /**
     * Negative fixture. `TPS Bekasi Harapan Indah` is seeded `draft` by
     * `2026_07_26_190300_seed_cemeteries_and_capability_profiles.php`, and
     * this batch deliberately parks one grave record inside it: an
     * unpublished cemetery must not become searchable just because a record
     * points at it.
     */
    public function test_a_record_exists_in_the_draft_cemetery_but_that_cemetery_is_not_selectable(): void
    {
        $draft = Cemetery::query()->where('slug', 'tps-bekasi-harapan-indah')->sole();

        $this->assertFalse($draft->isPublished());
        $this->assertSame(
            1,
            GraveRecord::query()->where('cemetery_id', $draft->id)->count()
        );

        // The guard that actually stops it being searched.
        $this->assertNull(CemeteryPublicQuery::findPublishedById((string) $draft->id));
    }

    // =====================================================================
    // The derived normalized column
    // =====================================================================

    /**
     * The seed migration writes through the query builder (the established
     * shape for every seed migration here), which does not fire model
     * events — so it calls `GraveNameNormalizer` itself. If that call were
     * ever dropped, the column would hold raw names, `LIKE '%budi
     * santoso%'` would stop matching `Contoh Budi Santoso`, and the failure
     * would look like "search is broken" rather than "the seed skipped a
     * step".
     */
    public function test_every_seeded_row_has_a_correctly_normalized_name(): void
    {
        foreach (GraveRecord::query()->get() as $record) {
            $this->assertSame(
                GraveNameNormalizer::normalize((string) $record->deceased_name),
                (string) $record->deceased_name_normalized,
                "Normalized form drifted for [{$record->deceased_name}]."
            );
        }
    }

    /**
     * The model side of the same guarantee — a row written through
     * Eloquent derives the column in `booted()` and OVERRIDES whatever the
     * caller supplied for it.
     *
     * `forceFill()`, not `create()`, and the reason matters more than the
     * mechanism. `deceased_name_normalized` is no longer in `$fillable`
     * (it is derived, so advertising it as an input was an interface lie).
     * Mass assignment silently DROPS an unlisted key — so had this test
     * kept passing the wrong value to `create()`, it would still pass while
     * proving only that `$fillable` ignores unknown keys, which is a fact
     * about Eloquent rather than about this model's `saving` hook. That is
     * exactly the vacuous-pass shape this retrofit fixed elsewhere in the
     * suite.
     *
     * `forceFill()` bypasses `$fillable` and genuinely sets the attribute,
     * which is asserted BEFORE the save so the premise is visible: the
     * wrong value really was on the model, and `booted()` really did
     * replace it.
     */
    public function test_the_model_derives_the_normalized_name_and_overrides_a_supplied_one(): void
    {
        $cemeteryId = Cemetery::query()->where('slug', 'tpu-bogor-bantarjati')->sole()->id;

        $record = new GraveRecord();
        $record->forceFill([
            'cemetery_id' => $cemeteryId,
            'deceased_name' => 'Contoh Nama  Uji-Coba',
            'deceased_name_normalized' => 'nilai yang salah',
            'block' => 'Z-01',
            'access_mode' => GraveRecordAccessMode::OPEN,
            'source' => GraveRecordSource::CONTOH,
        ]);

        // The premise: the caller-supplied value really is on the model.
        // Without this the assertion below could hold for the wrong reason.
        $this->assertSame('nilai yang salah', $record->deceased_name_normalized);

        $record->save();

        $this->assertSame('contoh nama uji coba', $record->deceased_name_normalized);
    }

    /**
     * The other half of the `$fillable` change: the two derived/protected
     * columns are no longer mass-assignable at all. Asserted directly
     * rather than left implicit, because a future edit re-adding either
     * would be silent — and for `heir_contact_reference` that means a
     * protected-class field (`AGENTS.md` §Authorization and files puts heir
     * contact with KTP/KK/death documents) becoming writable by any array
     * splatted into `create()`.
     */
    public function test_derived_and_protected_columns_are_not_mass_assignable(): void
    {
        $fillable = (new GraveRecord())->getFillable();

        $this->assertNotContains('deceased_name_normalized', $fillable);
        $this->assertNotContains('heir_contact_reference', $fillable);
    }

    // =====================================================================
    // Closed-list validation at write time
    // =====================================================================

    public function test_an_unknown_access_mode_cannot_be_saved(): void
    {
        $cemeteryId = Cemetery::query()->where('slug', 'tpu-bogor-bantarjati')->sole()->id;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown grave record access mode [publik]');

        GraveRecord::query()->create([
            'cemetery_id' => $cemeteryId,
            'deceased_name' => 'Contoh Nama Uji',
            'block' => 'Z-02',
            'access_mode' => 'publik',
            'source' => GraveRecordSource::CONTOH,
        ]);
    }

    public function test_an_unknown_source_cannot_be_saved(): void
    {
        $cemeteryId = Cemetery::query()->where('slug', 'tpu-bogor-bantarjati')->sole()->id;

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown grave record source [scraped]');

        GraveRecord::query()->create([
            'cemetery_id' => $cemeteryId,
            'deceased_name' => 'Contoh Nama Uji',
            'block' => 'Z-03',
            'access_mode' => GraveRecordAccessMode::OPEN,
            'source' => 'scraped',
        ]);
    }
}

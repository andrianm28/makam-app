<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\GraveRegistry\GraveNameNormalizer;
use App\Domain\GraveRegistry\GraveRecordAccessMode;
use App\Domain\GraveRegistry\GraveRecordSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The ONE place the ten EXAMPLE cemeteries (and their capability profiles,
 * package rows, dummy map/price/photo backfill, and grave records) are
 * defined. The data migrations and the `CemeteryExampleDataSeeder` both
 * materialize from here, so a slug/name/data change is a single deliberate
 * edit in one file — never three interlocking copies.
 *
 * ---------------------------------------------------------------------------
 * THE HONESTY FRAMING — read this before changing any rule below
 * ---------------------------------------------------------------------------
 * No real cemetery business data (a verified name, address, price, photo,
 * or coordinate for an actual operating TPU/TPS) exists anywhere in this
 * repository — checked directly (`docs/product/**`, `.kiro/specs/**`)
 * before writing the original seed migration, the same verification step
 * `2026_07_26_170400_seed_faq_categories_and_articles.php`'s own AC7
 * section documents for its payment/Urgent content. The ten cemeteries
 * below are therefore EXAMPLE fixtures, not real business data, and are
 * deliberately built to read as such rather than risk being mistaken for
 * verified content later:
 *
 *   - Names are GENERATED positionally — "TPU/TPS <City> <n>" where
 *     `<City>` comes from `LaunchCityCode::KNOWN_CODES` (2 per city,
 *     TPU then TPS) and `<n>` is the 1-based index. The result reads
 *     "TPU Jakarta 1", never a real-sounding neighbourhood name; the
 *     pattern is deliberately recognizable as a fixture.
 *   - Addresses use the literal placeholder street "Jl. Contoh Kota
 *     <City> No. <n>" ("Contoh" = Indonesian for "Example")
 *     specifically so nothing here could be read as a claim about a
 *     real, geocodable street address.
 *   - `latitude`/`longitude`/`google_maps_url` are ALWAYS `null` —
 *     inventing precise-looking coordinates for a fictional address
 *     would be a false-precision claim `requirements.md`'s negative
 *     criteria do not ask for and this batch's honesty discipline
 *     forbids. The dummy photo backfill uses the existing four
 *     illustration SVGs, which are explicitly illustrations, not
 *     photographs of real cemeteries.
 *   - `price_min`/`price_max` are DERIVED placeholders
 *     (`3_000_000 + index * 500_000` and `price_min * 1.8`) attributed
 *     by `priceSourceLabel()` as an internal estimate of example data —
 *     an ATTRIBUTED range (AC3) instead of an invented unattributed
 *     number; `price_source`/`price_effective_at` are only ever set
 *     together with the range, by `applyBackfill()`.
 *   - `operator_name` uses generic institutional phrasing ("Unit
 *     Pengelola Pemakaman Contoh") that names no real agency.
 *   - `facilities` lists only generic, plausible amenity words that
 *     are not a specific claim about a specific real place.
 *
 * ---------------------------------------------------------------------------
 * Nine published, one deliberately seeded `draft` — not accidental
 * ---------------------------------------------------------------------------
 * The LAST row (index 9, "TPS Bekasi 10") is seeded with
 * `publication_status = draft` on purpose, mirroring
 * `2026_07_26_170400_seed_faq_categories_and_articles.php`'s identical
 * choice for its one draft FAQ article: the suite (and any later batch's
 * AC2 "published only" scoping) needs a real seeded non-published row to
 * prove `Cemetery::scopePublished()` actually excludes something, not just
 * an empty negative test. It also carries exactly one grave record so the
 * negative fixture stays reachable.
 *
 * ---------------------------------------------------------------------------
 * Deterministic generation — same input, same output, every environment
 * ---------------------------------------------------------------------------
 * Every rule below is pure index math over the enum vocabularies
 * (`LaunchCityCode`, `CemeteryType`) plus constant word/photo pools. No
 * `random()`, no `time()`, no environment-dependent source: ten
 * cemeteries, their role slugs, the backfill prices, and the grave
 * records are byte-identical in every environment and every run.
 *
 * ---------------------------------------------------------------------------
 * Capability profiles — every seeded cemetery gets the documented safe
 * defaults, none activate anything stronger
 * ---------------------------------------------------------------------------
 * Every profile is `version_number = 1`, `superseded_at = null`
 * (current), and every one of the six modes equals `CemeteryCapability
 * Profile::safeDefaults()` — `INDICATIVE` / `REQUEST_CONFIRMATION` /
 * `LOCATION_ONLY` / `NONE` / `NONE` / `NONE`. `source` records these as
 * seed-generated, not a real operator/admin activation; `evidence` and
 * `rollback_plan` say so explicitly in Indonesian for anyone reading the
 * row directly. No seeded profile enables `SPECIFIC_PLOT`, `RESERVE_PLOT`,
 * `PLOT_MAP`, `AUTHORITATIVE`, `PLATFORM_MANAGED`, or `BOOKABLE` — this
 * batch has no authoritative registry/evidence to justify any of them
 * (requirements.md AC7's negative criterion).
 *
 * ---------------------------------------------------------------------------
 * `cemetery_packages` — only two cemeteries get example rows, not all ten
 * ---------------------------------------------------------------------------
 * AC6 only requires that package/class-level availability be EXPRESSIBLE,
 * not that every seeded cemetery have it populated — most real cemeteries
 * would not have this level of detail on day one either. Seeding it on
 * every row would overstate how much real per-package data exists. The
 * index-0 cemetery (Jakarta TPU) and the index-4 cemetery (Depok TPU) each
 * get a small, plausible set (a package-level "Makam Tumpang" row, two
 * class-level breakdowns of it, and one "Makam Single" package-level row)
 * so tests and a later batch's UI have real fixture data for the
 * package/class granularity AC6 asks for, without claiming universal
 * coverage. `PACKAGE_CEMETERY_SLUGS` is the one constant that carries the
 * two positions, in load-bearing order.
 *
 * ---------------------------------------------------------------------------
 * Why this generator, and why migrations are still the delivery path
 * ---------------------------------------------------------------------------
 * Nothing in this codebase's CI pipeline or deployment process ever runs
 * `php artisan db:seed`, so example content that must exist in every
 * environment ships through `php artisan migrate`. The data migrations
 * call `seed()` / `applyBackfill()` / `seedGraveRecords()` below, so the
 * delivery mechanism is unchanged; a seeder (`CemeteryExampleDataSeeder`)
 * additionally makes `db:seed` produce the same data idempotently for
 * anyone who runs it. This class carries the honesty framing the seed
 * migration used to own.
 */
final class CemeteryExampleData
{
    /**
     * The one seeded cemetery that is deliberately `draft` — the negative
     * fixture that proves `Cemetery::scopePublished()` excludes something.
     *
     * Position rule: index 9 — the last row, "TPS Bekasi 10". PHP cannot
     * compute constants from static calls, so this literal holds exactly
     * the slug the rule produces; `test_roles_resolve_by_position` locks
     * it to `roleCemetery('draft')`.
     */
    public const string DRAFT_SLUG = 'tps-bekasi-10';

    /**
     * The two cemeteries that carry `cemetery_packages` example rows.
     * Order is load-bearing for tests: index 0 is the Jakarta TPU, index 1
     * the Depok TPU.
     *
     * Position rule: index 0 ("TPU Jakarta 1") and index 4 ("TPU Depok 5").
     * Literal values per PHP constant-expression limits; the positional
     * equality is locked by `test_roles_resolve_by_position`.
     */
    public const array PACKAGE_CEMETERY_SLUGS = ['tpu-jakarta-1', 'tpu-depok-5'];

    /**
     * The cemetery whose EVERY grave record is privacy-restricted — the pure
     * privacy-limited fixture the renewal suite depends on.
     *
     * Position rule: index 1 — "TPS Jakarta 2". Literal value per PHP
     * constant-expression limits; locked by `test_roles_resolve_by_position`.
     */
    public const string ALL_RESTRICTED_SLUG = 'tps-jakarta-2';

    /**
     * A plain published, openly-searchable cemetery used by tests that need
     * an arbitrary cemetery with no special role.
     *
     * Position rule: index 2 — "TPU Bogor 3". Literal value per PHP
     * constant-expression limits; locked by `test_roles_resolve_by_position`.
     */
    public const string OPEN_CEMETERY_SLUG = 'tpu-bogor-3';

    /**
     * Generic Indonesian words used to build `Contoh <word> <n>` grave
     * record names — deliberately ordinary, never a real person's name.
     *
     * @var list<string>
     */
    private const array EXAMPLE_WORDS = ['Sejahtera', 'Permai', 'Asri', 'Damai', 'Cendana', 'Kenanga', 'Melati', 'Anggrek'];

    /**
     * The existing four illustration SVGs, cycled by index — illustrations,
     * not photographs of real cemeteries.
     *
     * @var list<string>
     */
    private const array EXAMPLE_PHOTOS = [
        'images/cemeteries/illustration-01-gate.svg',
        'images/cemeteries/illustration-02-grove.svg',
        'images/cemeteries/illustration-03-path.svg',
        'images/cemeteries/illustration-04-garden.svg',
    ];

    /**
     * Position rule: index 0-9 → a 2-per-city grid (TPU then TPS) over
     * `LaunchCityCode::KNOWN_CODES`. The city is Title-cased for the
     * display name so the row reads "TPU Jakarta 1"; the `cemeteries.city`
     * column keeps the canonical uppercase enum code.
     */
    private static function cemeteryName(int $index): string
    {
        $cityIndex = intdiv($index, 2);
        $isTpu = $index % 2 === 0;
        $city = array_values(LaunchCityCode::KNOWN_CODES)[$cityIndex] ?? 'Jakarta';
        $type = $isTpu ? CemeteryType::TPU : CemeteryType::TPS;

        return sprintf('%s %s %d', $type, ucfirst(strtolower($city)), $index + 1);
    }

    /**
     * Shape: [type, name, slug, city, address, operator_name, facilities, publication_status]
     *
     * @return list<array{0: int, 1: string, 2: string, 3: string, 4: string, 5: string, 6: list<string>, 7: string}>
     */
    public static function cemeteries(): array
    {
        $rows = [];
        for ($i = 0; $i < 10; $i++) {
            $name = self::cemeteryName($i);
            $isDraft = $i === 9;                       // role: last = draft
            $city = array_values(LaunchCityCode::KNOWN_CODES)[intdiv($i, 2)];
            $rows[] = [
                $i % 2 === 0 ? CemeteryType::TPU : CemeteryType::TPS,
                $name,
                Str::slug($name),
                $city,
                sprintf('Jl. Contoh Kota %s No. %d', $city, $i + 1),
                'Unit Pengelola Pemakaman Contoh',
                ['Area Parkir', 'Toilet Umum'],
                $isDraft ? CemeteryPublicationStatus::DRAFT : CemeteryPublicationStatus::PUBLISHED,
            ];
        }

        return $rows;
    }

    /**
     * Shape: [slug, name, class_label, availability_status, description, sort_order]
     * — the fixed example package catalog for the two package cemeteries
     * (see the class doc block); the cemetery slugs come from the
     * computed `PACKAGE_CEMETERY_SLUGS` positions.
     *
     * @return list<array{0: string, 1: string, 2: ?string, 3: string, 4: ?string, 5: int}>
     */
    public static function packages(): array
    {
        return [
            [self::PACKAGE_CEMETERY_SLUGS[0], 'Makam Tumpang', null, CemeteryPackageAvailabilityStatus::LIMITED,
                'Ketersediaan bersifat indikatif dan dapat berubah; konfirmasi akhir melalui operator.', 1],
            [self::PACKAGE_CEMETERY_SLUGS[0], 'Makam Tumpang', 'Kelas A', CemeteryPackageAvailabilityStatus::AVAILABLE,
                null, 2],
            [self::PACKAGE_CEMETERY_SLUGS[0], 'Makam Tumpang', 'Kelas B', CemeteryPackageAvailabilityStatus::LIMITED,
                null, 3],
            [self::PACKAGE_CEMETERY_SLUGS[0], 'Makam Single', null, CemeteryPackageAvailabilityStatus::AVAILABLE,
                null, 4],
            [self::PACKAGE_CEMETERY_SLUGS[1], 'Makam Tumpang', null, CemeteryPackageAvailabilityStatus::AVAILABLE,
                'Ketersediaan bersifat indikatif dan dapat berubah; konfirmasi akhir melalui operator.', 1],
            [self::PACKAGE_CEMETERY_SLUGS[1], 'Makam Tumpang', 'Kelas A', CemeteryPackageAvailabilityStatus::AVAILABLE,
                null, 2],
            [self::PACKAGE_CEMETERY_SLUGS[1], 'Makam Single', null, CemeteryPackageAvailabilityStatus::UNAVAILABLE,
                'Penuh untuk periode saat ini.', 3],
        ];
    }

    /**
     * Shape: [slug, latitude, longitude, google_maps_url, price_min, price_max, primary_photo_path]
     * — coordinates and the maps URL are ALWAYS `null` (honesty framing,
     * class doc block); the price range is derived
     * (`3_000_000 + index * 500_000`, max = min * 1.8 rounded to whole
     * rupiah) and attributed via `priceSourceLabel()`; the photo cycles
     * the four existing illustration SVGs by index.
     *
     * @return list<array{0: string, 1: null, 2: null, 3: null, 4: float, 5: float, 6: string}>
     */
    public static function backfills(): array
    {
        $rows = [];
        foreach (self::cemeteries() as $index => $cemetery) {
            $priceMin = (float) (3_000_000 + $index * 500_000);
            $rows[] = [
                $cemetery[2],
                null, null, null,
                $priceMin,
                round($priceMin * 1.8),
                self::EXAMPLE_PHOTOS[$index % 4],
            ];
        }

        return $rows;
    }

    /**
     * Shape: [cemetery slug, deceased name, block, death date, due date, access mode]
     * — deterministic counts by role: the all-restricted cemetery
     * (index 1) gets 2 records, both privacy-limited; the draft cemetery
     * (index 9) gets exactly 1 OPEN record; every other cemetery gets
     * 1-2 records, all OPEN. Names are `Contoh <word> <n>` from
     * `EXAMPLE_WORDS`; index 4's second record has a NULL death date and
     * index 0's second record has a NULL due date (the registry
     * incompleteness AC5's empty-state copy tells the public about must
     * be real in the data, not only in the copy). All dates are derived
     * from index math — never `random()`/`time()`.
     *
     * @return list<array{0: string, 1: string, 2: string, 3: ?string, 4: ?string, 5: string}>
     */
    public static function graveRecords(): array
    {
        $rows = [];
        $counter = 0;

        foreach (self::cemeteries() as $index => $cemetery) {
            $slug = $cemetery[2];
            $count = match (true) {
                $index === 1, $index % 2 === 0 => 2,
                default => 1, // odd indexes (and the draft, index 9) get one
            };

            for ($record = 0; $record < $count; $record++) {
                $isSecond = $record === 1;
                $accessMode = $index === 1
                    ? ($record === 0 ? GraveRecordAccessMode::LIMITED : GraveRecordAccessMode::CLOSED)
                    : GraveRecordAccessMode::OPEN;

                // Death year cycles 2016-2024; month/day derive from the
                // same pure index math so every row is reproducible.
                $year = 2016 + (($index * 3 + $record * 7 + $counter) % 9);
                $month = (($index * 5 + $record * 3 + $counter) % 12) + 1;
                $day = (($index * 2 + $record * 5 + $counter) % 28) + 1;
                $deathDate = sprintf('%d-%02d-%02d', $year, $month, $day);
                $dueDate = sprintf('%d-%02d-%02d', $year + 10, $month, $day);

                $rows[] = [
                    $slug,
                    'Contoh '.self::EXAMPLE_WORDS[$counter % 8].' '.($counter + 1),
                    sprintf('%s-%02d', chr(65 + $index), $record + 1),
                    $isSecond && $index === 4 ? null : $deathDate,
                    $isSecond && $index === 0 ? null : $dueDate,
                    $accessMode,
                ];

                $counter++;
            }
        }

        return $rows;
    }

    /**
     * Resolve a role cemetery by position, without string-matching names.
     *
     * Roles:
     * - `draft` → the deliberately unpublished cemetery (index 9)
     * - `all-restricted` → the fully privacy-limited cemetery (index 1)
     * - `package` → the package cemetery at `PACKAGE_CEMETERY_SLUGS[$index]`
     *   (index defaults to 0 — the Jakarta TPU; 1 is the Depok TPU)
     *
     * @return array{0: int, 1: string, 2: string, 3: string, 4: string, 5: string, 6: list<string>, 7: string}
     *
     * @throws InvalidArgumentException on an unknown role
     */
    public static function roleCemetery(string $role, ?int $index = null): array
    {
        $position = match ($role) {
            'draft' => 9,
            'all-restricted' => 1,
            'package' => $index ?? 0,
            default => throw new InvalidArgumentException("Unknown example cemetery role [{$role}]."),
        };

        if ($role === 'package') {
            return self::bySlug(self::PACKAGE_CEMETERY_SLUGS[$position]);
        }

        return self::cemeteries()[$position];
    }

    /** @return list<string> */
    public static function slugs(): array
    {
        return array_column(self::cemeteries(), 2);
    }

    public static function priceSourceLabel(): string
    {
        return 'Estimasi internal (data contoh)';
    }

    public static function bySlug(string $slug): array
    {
        foreach (self::cemeteries() as $cemetery) {
            if ($cemetery[2] === $slug) {
                return $cemetery;
            }
        }

        throw new InvalidArgumentException("Unknown example cemetery slug [{$slug}].");
    }

    public static function seed(): void
    {
        $now = now();

        $cemeteryIds = [];

        foreach (self::cemeteries() as [$type, $name, $slug, $city, $address, $operatorName, $facilities, $publicationStatus]) {
            $id = (string) Str::uuid();
            $cemeteryIds[$slug] = $id;

            $isPublished = $publicationStatus === CemeteryPublicationStatus::PUBLISHED;

            DB::table('cemeteries')->insert([
                'id' => $id,
                'type' => $type,
                'publication_status' => $publicationStatus,
                'name' => $name,
                'slug' => $slug,
                'city' => $city,
                'address' => $address,
                'latitude' => null,
                'longitude' => null,
                'google_maps_url' => null,
                'primary_photo_path' => null,
                'facilities' => json_encode($facilities),
                'price_min' => null,
                'price_max' => null,
                'price_currency' => 'IDR',
                'price_source' => null,
                'price_effective_at' => null,
                'operator_name' => $operatorName,
                'published_at' => $isPublished ? $now : null,
                'unpublished_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $defaults = CemeteryCapabilityProfile::safeDefaults();

            DB::table('cemetery_capability_profiles')->insert([
                'cemetery_id' => $id,
                'version_number' => 1,
                'availability_mode' => $defaults['availability_mode'],
                'booking_mode' => $defaults['booking_mode'],
                'map_mode' => $defaults['map_mode'],
                'registry_mode' => $defaults['registry_mode'],
                'certificate_mode' => $defaults['certificate_mode'],
                'visitation_mode' => $defaults['visitation_mode'],
                'source' => 'seed:cemetery-directory-master-data',
                'owner' => 'Platform Admin (seed)',
                'evidence' => 'Data awal Sprint 4 (S4-T1) — belum ada evaluasi operator lapangan; seluruh mode mengikuti nilai aman default, bukan hasil aktivasi kapabilitas nyata.',
                'rollback_plan' => 'Tidak ada aktivasi untuk dibatalkan — profil ini adalah nilai default awal, bukan hasil aktivasi kapabilitas lanjutan.',
                'effective_at' => $now,
                'superseded_at' => null,
            ]);
        }

        foreach (self::packages() as [$cemeterySlug, $name, $classLabel, $availabilityStatus, $description, $sortOrder]) {
            DB::table('cemetery_packages')->insert([
                'cemetery_id' => $cemeteryIds[$cemeterySlug],
                'name' => $name,
                'class_label' => $classLabel,
                'availability_status' => $availabilityStatus,
                'description' => $description,
                'sort_order' => $sortOrder,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Price + photo ONLY — coordinates and the maps URL are never set
     * here (they stay `null` from `seed()`; see the honesty framing).
     */
    public static function applyBackfill(): void
    {
        $now = now();

        foreach (self::backfills() as [$slug, $latitude, $longitude, $googleMapsUrl, $priceMin, $priceMax, $photoPath]) {
            DB::table('cemeteries')->where('slug', $slug)->update([
                'primary_photo_path' => $photoPath,
                'price_min' => $priceMin,
                'price_max' => $priceMax,
                'price_source' => self::priceSourceLabel(),
                'price_effective_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public static function seedGraveRecords(): void
    {
        $now = now();

        $records = self::graveRecords();

        $cemeteryIds = DB::table('cemeteries')
            ->whereIn('slug', array_unique(array_column($records, 0)))
            ->pluck('id', 'slug');

        foreach ($records as [$slug, $name, $block, $deathDate, $dueDate, $accessMode]) {
            $cemeteryId = $cemeteryIds[$slug] ?? null;

            // A slug this data expects but cannot find means the cemetery
            // seed was rolled back or edited. Skip rather than fail: a
            // missing FIXTURE row must never block a real deployment's
            // migration run. (Same choice the previous grave-record seed
            // migration documented.)
            if ($cemeteryId === null) {
                continue;
            }

            DB::table('grave_records')->insert([
                'id' => (string) Str::uuid(),
                'cemetery_id' => $cemeteryId,
                'deceased_name' => $name,
                // GraveRecord::booted() derives this on Eloquent writes, but
                // the query builder does not fire model events — calling the
                // same normalizer keeps stored form identical to what
                // GraveRegistryPublicQuery searches against.
                'deceased_name_normalized' => GraveNameNormalizer::normalize($name),
                'block' => $block,
                'death_date' => $deathDate,
                'due_date' => $dueDate,
                'heir_contact_reference' => null,
                'access_mode' => $accessMode,
                'source' => GraveRecordSource::CONTOH,
                'source_updated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}

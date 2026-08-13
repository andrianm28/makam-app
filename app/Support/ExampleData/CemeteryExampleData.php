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
 * THE HONESTY FRAMING — read this before adding, copying, or "completing"
 * any row below
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
 *   - Names follow the "TPU/TPS <City> <Generic area>" template this
 *     batch's own brief suggested, using ordinary Indonesian neighbourhood
 *     words (Menteng, Kemang, Cinere, ...) rather than the actual name of
 *     any specific real cemetery in these cities (none of the ten below
 *     is a real TPU/TPS's real name — deliberately checked against
 *     commonly-known Jakarta-area cemetery names before writing this
 *     list).
 *   - Addresses use the literal placeholder street "Jl. Contoh ..."
 *     ("Contoh" = Indonesian for "Example") specifically so nothing here
 *     could be read as a claim about a real, geocodable street address.
 *   - `latitude`/`longitude`/`google_maps_url`/`primary_photo_path` are
 *     ALL left `null` at seed time — inventing precise-looking coordinates
 *     or a photo path for a fictional address would be a false-precision
 *     claim `requirements.md`'s negative criteria do not ask for and this
 *     batch's honesty discipline forbids. AC11 exists precisely so a
 *     missing map/photo does not block the textual address, which every
 *     row DOES have.
 *   - `price_min`/`price_max`/`price_source`/`price_effective_at` are ALL
 *     left `null` at seed time, for the identical reason `App\Domain\
 *     ServiceCatalog\Models\PriceVersion`'s own migration (this same
 *     Sprint 4 wave, a sibling batch) gives for shipping `price_versions`
 *     empty: "Seeding an invented price here would be exactly the
 *     fabricated business data this batch's brief forbids." AC3 requires
 *     an ATTRIBUTED price range when one is shown — showing nothing is
 *     honest; showing a number with an invented `price_source` would not
 *     be.
 *   - `operator_name` uses generic institutional phrasing ("Unit Pengelola
 *     Pemakaman Kota <City>" / "Yayasan Pemakaman Swasta <City>") that
 *     names no real agency or foundation.
 *   - `facilities` lists only generic, plausible amenity words (parking,
 *     prayer room, restroom, waiting area) that are not a specific claim
 *     about a specific real place.
 *
 * ---------------------------------------------------------------------------
 * Nine published, one deliberately seeded `draft` — not accidental
 * ---------------------------------------------------------------------------
 * `TPS Bekasi Harapan Indah` (the last row) is seeded with
 * `publication_status = draft` on purpose, mirroring
 * `2026_07_26_170400_seed_faq_categories_and_articles.php`'s identical
 * choice for its one draft FAQ article: the suite (and any later batch's
 * AC2 "published only" scoping) needs a real seeded non-published row to
 * prove `Cemetery::scopePublished()` actually excludes something, not just
 * an empty negative test.
 *
 * ---------------------------------------------------------------------------
 * Capability profiles — every seeded cemetery gets the documented safe
 * defaults, none activate anything stronger
 * ---------------------------------------------------------------------------
 * Every profile below is `version_number = 1`, `superseded_at = null`
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
 * every row would overstate how much real per-package data exists.
 * `TPU Jakarta Menteng` and `TPU Depok Sawangan` each get a small,
 * plausible set (a package-level "Makam Tumpang" row, two class-level
 * breakdowns of it, and one "Makam Single" package-level row) so tests and
 * a later batch's UI have real fixture data for the package/class
 * granularity AC6 asks for, without claiming universal coverage.
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
     */
    public const string DRAFT_SLUG = 'tps-bekasi-harapan-indah';

    /**
     * The two cemeteries that carry `cemetery_packages` example rows.
     * Order is load-bearing for tests: index 0 is the Jakarta TPU, index 1
     * the Depok TPU.
     */
    public const array PACKAGE_CEMETERY_SLUGS = ['tpu-jakarta-menteng', 'tpu-depok-sawangan'];

    /**
     * The cemetery whose EVERY grave record is privacy-restricted — the pure
     * privacy-limited fixture the renewal suite depends on.
     */
    public const string ALL_RESTRICTED_SLUG = 'tps-jakarta-kemang';

    /**
     * A plain published, openly-searchable cemetery used by tests that need
     * an arbitrary cemetery with no special role.
     */
    public const string OPEN_CEMETERY_SLUG = 'tpu-bogor-bantarjati';

    /**
     * Shape: [type, name, slug, city, address, operator_name, facilities, publication_status]
     *
     * @return list<array{0: int, 1: string, 2: string, 3: string, 4: string, 5: string, 6: list<string>, 7: string}>
     */
    public static function cemeteries(): array
    {
        return [
            [CemeteryType::TPU, 'TPU Jakarta Menteng', self::PACKAGE_CEMETERY_SLUGS[0], LaunchCityCode::JAKARTA,
                'Jl. Contoh Sejahtera No. 10, Menteng, Jakarta Pusat',
                'Unit Pengelola Pemakaman Kota Jakarta',
                ['Area Parkir', 'Mushola', 'Toilet Umum'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPS, 'TPS Jakarta Kemang', self::ALL_RESTRICTED_SLUG, LaunchCityCode::JAKARTA,
                'Jl. Contoh Kemuning No. 21, Kemang, Jakarta Selatan',
                'Yayasan Pemakaman Swasta Jakarta',
                ['Area Parkir', 'Ruang Tunggu'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPU, 'TPU Bogor Bantarjati', self::OPEN_CEMETERY_SLUG, LaunchCityCode::BOGOR,
                'Jl. Contoh Melati No. 5, Bantarjati, Bogor Utara',
                'Unit Pengelola Pemakaman Kota Bogor',
                ['Area Parkir', 'Toilet Umum'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPS, 'TPS Bogor Cimanggu', 'tps-bogor-cimanggu', LaunchCityCode::BOGOR,
                'Jl. Contoh Anggrek No. 8, Cimanggu, Bogor Tengah',
                'Yayasan Pemakaman Swasta Bogor',
                ['Area Parkir', 'Mushola', 'Ruang Tunggu'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPU, 'TPU Depok Sawangan', self::PACKAGE_CEMETERY_SLUGS[1], LaunchCityCode::DEPOK,
                'Jl. Contoh Cempaka No. 17, Sawangan, Depok',
                'Unit Pengelola Pemakaman Kota Depok',
                ['Area Parkir', 'Mushola', 'Toilet Umum', 'Sumber Air'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPS, 'TPS Depok Cinere', 'tps-depok-cinere', LaunchCityCode::DEPOK,
                'Jl. Contoh Mawar No. 3, Cinere, Depok',
                'Yayasan Pemakaman Swasta Depok',
                ['Area Parkir'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPU, 'TPU Tangerang Cipondoh', 'tpu-tangerang-cipondoh', LaunchCityCode::TANGERANG,
                'Jl. Contoh Dahlia No. 14, Cipondoh, Tangerang',
                'Unit Pengelola Pemakaman Kota Tangerang',
                ['Area Parkir', 'Toilet Umum'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPS, 'TPS Tangerang Karawaci', 'tps-tangerang-karawaci', LaunchCityCode::TANGERANG,
                'Jl. Contoh Kenanga No. 9, Karawaci, Tangerang',
                'Yayasan Pemakaman Swasta Tangerang',
                ['Area Parkir', 'Mushola'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPU, 'TPU Bekasi Jatiasih', 'tpu-bekasi-jatiasih', LaunchCityCode::BEKASI,
                'Jl. Contoh Flamboyan No. 6, Jatiasih, Bekasi',
                'Unit Pengelola Pemakaman Kota Bekasi',
                ['Area Parkir', 'Mushola', 'Toilet Umum'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPS, 'TPS Bekasi Harapan Indah', self::DRAFT_SLUG, LaunchCityCode::BEKASI,
                'Jl. Contoh Teratai No. 11, Harapan Indah, Bekasi',
                'Yayasan Pemakaman Swasta Bekasi',
                ['Area Parkir', 'Ruang Tunggu'],
                // Deliberately seeded as `draft` — see the class doc block.
                CemeteryPublicationStatus::DRAFT],
        ];
    }

    /**
     * Shape: [slug, name, class_label, availability_status, description, sort_order]
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
     * — `price_source` is the single literal `priceSourceLabel()` shared by
     * every row (see `applyBackfill()`).
     *
     * @return list<array{0: string, 1: float, 2: float, 3: string, 4: float, 5: float, 6: string}>
     */
    public static function backfills(): array
    {
        return [
            [self::PACKAGE_CEMETERY_SLUGS[0], -6.19, 106.83,
                self::mapsSearchUrl('TPU Jakarta Menteng', 'Jakarta'), 4_000_000.00, 7_500_000.00,
                'images/cemeteries/illustration-01-gate.svg'],
            [self::ALL_RESTRICTED_SLUG, -6.26, 106.81,
                self::mapsSearchUrl('TPS Jakarta Kemang', 'Jakarta'), 12_000_000.00, 22_000_000.00,
                'images/cemeteries/illustration-02-grove.svg'],
            [self::OPEN_CEMETERY_SLUG, -6.57, 106.81,
                self::mapsSearchUrl('TPU Bogor Bantarjati', 'Bogor'), 3_000_000.00, 6_000_000.00,
                'images/cemeteries/illustration-03-path.svg'],
            ['tps-bogor-cimanggu', -6.63, 106.79,
                self::mapsSearchUrl('TPS Bogor Cimanggu', 'Bogor'), 9_000_000.00, 16_000_000.00,
                'images/cemeteries/illustration-04-garden.svg'],
            [self::PACKAGE_CEMETERY_SLUGS[1], -6.38, 106.76,
                self::mapsSearchUrl('TPU Depok Sawangan', 'Depok'), 3_500_000.00, 6_500_000.00,
                'images/cemeteries/illustration-01-gate.svg'],
            ['tps-depok-cinere', -6.33, 106.77,
                self::mapsSearchUrl('TPS Depok Cinere', 'Depok'), 10_000_000.00, 18_000_000.00,
                'images/cemeteries/illustration-02-grove.svg'],
            ['tpu-tangerang-cipondoh', -6.19, 106.69,
                self::mapsSearchUrl('TPU Tangerang Cipondoh', 'Tangerang'), 3_200_000.00, 6_200_000.00,
                'images/cemeteries/illustration-03-path.svg'],
            ['tps-tangerang-karawaci', -6.23, 106.63,
                self::mapsSearchUrl('TPS Tangerang Karawaci', 'Tangerang'), 8_500_000.00, 15_000_000.00,
                'images/cemeteries/illustration-04-garden.svg'],
            ['tpu-bekasi-jatiasih', -6.27, 106.98,
                self::mapsSearchUrl('TPU Bekasi Jatiasih', 'Bekasi'), 3_000_000.00, 5_800_000.00,
                'images/cemeteries/illustration-01-gate.svg'],
            [self::DRAFT_SLUG, -6.15, 107.01,
                self::mapsSearchUrl('TPS Bekasi Harapan Indah', 'Bekasi'), 9_500_000.00, 17_000_000.00,
                'images/cemeteries/illustration-02-grove.svg'],
        ];
    }

    /**
     * Shape: [cemetery slug, deceased name, block, death date, due date, access mode]
     *
     * @return list<array{0: string, 1: string, 2: string, 3: ?string, 4: ?string, 5: string}>
     */
    public static function graveRecords(): array
    {
        return [
            // --- TPU Jakarta Menteng: mixed open + one limited ---
            [self::PACKAGE_CEMETERY_SLUGS[0], 'Contoh Budi Santoso', 'A-12', '2018-04-11', '2026-04-11', GraveRecordAccessMode::OPEN],
            [self::PACKAGE_CEMETERY_SLUGS[0], 'Contoh Siti Rahayu', 'A-15', '2019-09-02', '2027-09-02', GraveRecordAccessMode::OPEN],
            [self::PACKAGE_CEMETERY_SLUGS[0], 'Contoh Bambang Wijaya', 'B-03', '2020-01-27', '2026-01-27', GraveRecordAccessMode::OPEN],
            [self::PACKAGE_CEMETERY_SLUGS[0], 'Contoh Sri Handayani', 'B-08', '2021-06-18', '2027-06-18', GraveRecordAccessMode::LIMITED],

            // --- TPS Jakarta Kemang: every row restricted (see class doc block) ---
            [self::ALL_RESTRICTED_SLUG, 'Contoh Agus Priyono', 'C-01', '2017-11-30', '2026-11-30', GraveRecordAccessMode::LIMITED],
            [self::ALL_RESTRICTED_SLUG, 'Contoh Dewi Anggraini', 'C-04', '2022-02-14', '2028-02-14', GraveRecordAccessMode::CLOSED],

            // --- TPU Bogor Bantarjati ---
            [self::OPEN_CEMETERY_SLUG, 'Contoh Joko Purnomo', 'D-07', '2016-08-05', '2026-08-05', GraveRecordAccessMode::OPEN],
            [self::OPEN_CEMETERY_SLUG, 'Contoh Rina Marlina', 'D-09', '2020-12-21', '2026-12-21', GraveRecordAccessMode::OPEN],

            // --- TPU Depok Sawangan ---
            // Deliberately missing a death date: the registry incompleteness
            // AC5's empty-state copy tells the public about must be real in
            // the data, not only in the copy.
            [self::PACKAGE_CEMETERY_SLUGS[1], 'Contoh Hendra Gunawan', 'E-02', null, '2027-03-15', GraveRecordAccessMode::OPEN],
            [self::PACKAGE_CEMETERY_SLUGS[1], 'Contoh Lestari Wulandari', 'E-05', '2019-05-09', null, GraveRecordAccessMode::OPEN],

            // --- TPU Tangerang Cipondoh ---
            ['tpu-tangerang-cipondoh', 'Contoh Andi Kurniawan', 'F-11', '2021-10-03', '2027-10-03', GraveRecordAccessMode::OPEN],

            // --- TPU Bekasi Jatiasih ---
            ['tpu-bekasi-jatiasih', 'Contoh Yusuf Maulana', 'G-06', '2018-07-22', '2026-07-22', GraveRecordAccessMode::OPEN],
            ['tpu-bekasi-jatiasih', 'Contoh Nurul Hasanah', 'G-10', '2023-01-08', '2029-01-08', GraveRecordAccessMode::CLOSED],

            // --- TPS Bekasi Harapan Indah: the DRAFT cemetery (negative fixture) ---
            [self::DRAFT_SLUG, 'Contoh Rahmat Hidayat', 'H-01', '2020-03-30', '2026-03-30', GraveRecordAccessMode::OPEN],
        ];
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

    public static function applyBackfill(): void
    {
        $now = now();

        foreach (self::backfills() as [$slug, $latitude, $longitude, $googleMapsUrl, $priceMin, $priceMax, $photoPath]) {
            DB::table('cemeteries')->where('slug', $slug)->update([
                'latitude' => $latitude,
                'longitude' => $longitude,
                'google_maps_url' => $googleMapsUrl,
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

    private static function mapsSearchUrl(string $name, string $city): string
    {
        return 'https://www.google.com/maps/search/?api=1&query='.urlencode("{$name}, {$city}, Indonesia");
    }
}

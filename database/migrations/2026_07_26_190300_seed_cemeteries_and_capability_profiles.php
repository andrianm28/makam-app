<?php

declare(strict_types=1);

use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryCapability\Models\CemeteryCapabilityProfile;
use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds `cemeteries`, one current-version `cemetery_capability_profiles`
 * row per cemetery, and a handful of `cemetery_packages` rows demonstrating
 * requirements.md AC6 ("Makam Tumpang ... at the ... package/class
 * level").
 *
 * ---------------------------------------------------------------------------
 * Why a DATA MIGRATION, not a `database/seeders/*` class
 * ---------------------------------------------------------------------------
 * Same precedent `2026_07_26_170400_seed_faq_categories_and_articles.php`
 * established and documents in full in its own doc block: nothing in this
 * codebase's CI pipeline or deployment process ever runs `php artisan
 * db:seed`, so real content that must exist in every environment
 * automatically ships as a migration instead.
 *
 * ---------------------------------------------------------------------------
 * THE HONESTY FRAMING — read this before adding, copying, or "completing"
 * any row below
 * ---------------------------------------------------------------------------
 * No real cemetery business data (a verified name, address, price, photo,
 * or coordinate for an actual operating TPU/TPS) exists anywhere in this
 * repository — checked directly (`docs/product/**`, `.kiro/specs/**`)
 * before writing this migration, the same verification step
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
 *     ALL left `null` for every row — inventing precise-looking
 *     coordinates or a photo path for a fictional address would be a
 *     false-precision claim `requirements.md`'s negative criteria do not
 *     ask for and this batch's honesty discipline forbids. AC11 exists
 *     precisely so a missing map/photo does not block the textual address,
 *     which every row DOES have.
 *   - `price_min`/`price_max`/`price_source`/`price_effective_at` are ALL
 *     left `null` for every row, for the identical reason `App\Domain\
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
 * choice for its one draft FAQ article: this batch's own tests (and any
 * later batch's AC2 "published only" scoping) need a real seeded
 * non-published row to prove `Cemetery::scopePublished()` actually
 * excludes something, not just an empty negative test.
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
 * Migration timestamp slot: see
 * `2026_07_26_190000_create_cemeteries_table.php`'s own doc block.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Shape: [type, name, slug, city, address, operator_name, facilities, publication_status]
        $cemeteries = [
            [CemeteryType::TPU, 'TPU Jakarta Menteng', 'tpu-jakarta-menteng', LaunchCityCode::JAKARTA,
                'Jl. Contoh Sejahtera No. 10, Menteng, Jakarta Pusat',
                'Unit Pengelola Pemakaman Kota Jakarta',
                ['Area Parkir', 'Mushola', 'Toilet Umum'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPS, 'TPS Jakarta Kemang', 'tps-jakarta-kemang', LaunchCityCode::JAKARTA,
                'Jl. Contoh Kemuning No. 21, Kemang, Jakarta Selatan',
                'Yayasan Pemakaman Swasta Jakarta',
                ['Area Parkir', 'Ruang Tunggu'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPU, 'TPU Bogor Bantarjati', 'tpu-bogor-bantarjati', LaunchCityCode::BOGOR,
                'Jl. Contoh Melati No. 5, Bantarjati, Bogor Utara',
                'Unit Pengelola Pemakaman Kota Bogor',
                ['Area Parkir', 'Toilet Umum'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPS, 'TPS Bogor Cimanggu', 'tps-bogor-cimanggu', LaunchCityCode::BOGOR,
                'Jl. Contoh Anggrek No. 8, Cimanggu, Bogor Tengah',
                'Yayasan Pemakaman Swasta Bogor',
                ['Area Parkir', 'Mushola', 'Ruang Tunggu'], CemeteryPublicationStatus::PUBLISHED],
            [CemeteryType::TPU, 'TPU Depok Sawangan', 'tpu-depok-sawangan', LaunchCityCode::DEPOK,
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
            [CemeteryType::TPS, 'TPS Bekasi Harapan Indah', 'tps-bekasi-harapan-indah', LaunchCityCode::BEKASI,
                'Jl. Contoh Teratai No. 11, Harapan Indah, Bekasi',
                'Yayasan Pemakaman Swasta Bekasi',
                ['Area Parkir', 'Ruang Tunggu'],
                // Deliberately seeded as `draft` — see this migration's
                // own class-level doc block for why.
                CemeteryPublicationStatus::DRAFT],
        ];

        $cemeteryIds = [];

        foreach ($cemeteries as [$type, $name, $slug, $city, $address, $operatorName, $facilities, $publicationStatus]) {
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

        // --- cemetery_packages — AC6 example fixtures on two cemeteries only.
        $packages = [
            ['tpu-jakarta-menteng', 'Makam Tumpang', null, CemeteryPackageAvailabilityStatus::LIMITED,
                'Ketersediaan bersifat indikatif dan dapat berubah; konfirmasi akhir melalui operator.', 1],
            ['tpu-jakarta-menteng', 'Makam Tumpang', 'Kelas A', CemeteryPackageAvailabilityStatus::AVAILABLE,
                null, 2],
            ['tpu-jakarta-menteng', 'Makam Tumpang', 'Kelas B', CemeteryPackageAvailabilityStatus::LIMITED,
                null, 3],
            ['tpu-jakarta-menteng', 'Makam Single', null, CemeteryPackageAvailabilityStatus::AVAILABLE,
                null, 4],
            ['tpu-depok-sawangan', 'Makam Tumpang', null, CemeteryPackageAvailabilityStatus::AVAILABLE,
                'Ketersediaan bersifat indikatif dan dapat berubah; konfirmasi akhir melalui operator.', 1],
            ['tpu-depok-sawangan', 'Makam Tumpang', 'Kelas A', CemeteryPackageAvailabilityStatus::AVAILABLE,
                null, 2],
            ['tpu-depok-sawangan', 'Makam Single', null, CemeteryPackageAvailabilityStatus::UNAVAILABLE,
                'Penuh untuk periode saat ini.', 3],
        ];

        foreach ($packages as [$cemeterySlug, $name, $classLabel, $availabilityStatus, $description, $sortOrder]) {
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

    public function down(): void
    {
        $slugs = [
            'tpu-jakarta-menteng', 'tps-jakarta-kemang',
            'tpu-bogor-bantarjati', 'tps-bogor-cimanggu',
            'tpu-depok-sawangan', 'tps-depok-cinere',
            'tpu-tangerang-cipondoh', 'tps-tangerang-karawaci',
            'tpu-bekasi-jatiasih', 'tps-bekasi-harapan-indah',
        ];

        // cemetery_capability_profiles and cemetery_packages both
        // cascadeOnDelete() from cemeteries.id, so deleting the cemeteries
        // is sufficient.
        DB::table('cemeteries')->whereIn('slug', $slugs)->delete();
    }
};

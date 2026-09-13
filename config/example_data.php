<?php

declare(strict_types=1);

/**
 * `seed_realistic_marketplace_pricing` gates
 * `database/migrations/2026_08_25_140000_seed_realistic_marketplace_pricing_
 * fixtures.php`, which inserts fictional marketplace vendors/listings priced
 * from real-world researched ranges (see that migration's own doc block for
 * sourcing) rather than the arbitrary hash-derived prices
 * `App\Support\ExampleData\VendorListingExampleData` uses.
 *
 * Same shape and same reason as `config/e2e_fixtures.php`'s flags:
 * `RefreshDatabase` applies every migration once per PHPUnit process, so an
 * unconditional `up()` here would permanently write real `vendors`/
 * `vendor_listings`/`service_areas` rows into every unrelated Feature test's
 * database in the same process. Deliberately NOT gated on
 * `app()->environment('testing')` for the identical reason
 * `config/e2e_fixtures.php` gives: that value is ALSO what `phpunit.xml` sets
 * for every ordinary PHPUnit run, so it would seed unconditionally there too.
 *
 * Default false via env. As with every other fixture migration in this
 * repository, the migration ALSO carries its own independent
 * `app()->isProduction()` guard as defense-in-depth — this file's flag is
 * not the only thing standing between this fixture data and a production
 * database.
 *
 * ---------------------------------------------------------------------------
 * DB-05 (batch M3a) — the six flags below, default TRUE (not false)
 * ---------------------------------------------------------------------------
 * These six gate the BASELINE fixture migrations that were found running
 * completely unguarded on every `php artisan migrate`, including a real
 * production deploy: `2026_07_26_190300`, `2026_07_26_200100`,
 * `2026_07_26_210000`, `2026_08_08_100010`, `2026_08_14_100000`,
 * `2026_08_14_100010`. Unlike `seed_realistic_marketplace_pricing` above,
 * these are NOT opt-in extras: their own doc blocks, and this repo's
 * existing (currently-passing) test suite —
 * `VendorListingBootstrapTest::test_the_seed_migration_creates_five_vendors_
 * and_nine_listings()`, `CemeterySeedTest`, `GraveRecordSeedTest`,
 * `ProductDetailRouteTest` — depend on every one of them running
 * UNCONDITIONALLY in dev, staging, and CI ("every fresh database ships
 * five example vendors ... so the marketplace journey is operable end to
 * end from seed alone"). Defaulting these to `false`, the same as
 * `seed_realistic_marketplace_pricing`, would silently break that existing
 * contract everywhere the env var isn't explicitly set — a regression at
 * least as bad as the bug being fixed here.
 *
 * The actual reported hole (fabricated data landing in a REAL production
 * deploy) is closed by each migration's own mandatory
 * `if (app()->isProduction()) { return; }` guard, unconditionally, with no
 * flag involved — production never runs any of these six regardless of
 * what any of the flags below are set to. The flag on each migration is an
 * additional, independent kill switch for an operator who wants to disable
 * one of these in a NON-production environment (e.g. a clean beta review
 * without demo vendors) without touching the migration file; it does not
 * change today's default behaviour anywhere these migrations currently run.
 */
return [
    'seed_realistic_marketplace_pricing' => (bool) env('SEED_REALISTIC_MARKETPLACE_PRICING', false),

    'seed_cemeteries_and_capability_profiles' => (bool) env('SEED_CEMETERIES_AND_CAPABILITY_PROFILES', true),
    'seed_dummy_vendor_pricing_and_photo' => (bool) env('SEED_DUMMY_VENDOR_PRICING_AND_PHOTO', true),
    'seed_dummy_map_price_and_photo_backfill' => (bool) env('SEED_DUMMY_MAP_PRICE_AND_PHOTO_BACKFILL', true),
    'seed_example_grave_records' => (bool) env('SEED_EXAMPLE_GRAVE_RECORDS', true),
    'seed_vendors_and_listings' => (bool) env('SEED_VENDORS_AND_LISTINGS', true),
    'seed_service_areas_for_example_vendors' => (bool) env('SEED_SERVICE_AREAS_FOR_EXAMPLE_VENDORS', true),
];

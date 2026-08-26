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
 */
return [
    'seed_realistic_marketplace_pricing' => (bool) env('SEED_REALISTIC_MARKETPLACE_PRICING', false),
];

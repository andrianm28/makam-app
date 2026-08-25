<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Support\ExampleData\CemeteryExampleData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Makes `php artisan db:seed` produce the same example cemeteries (and
 * their capability profiles, packages, dummy backfill, and grave records)
 * that the data migrations produce.
 *
 * In this repository the migrations are the delivery mechanism for every
 * environment (CI and deploy never run `db:seed`), so on any database that
 * has been `migrate`d this seeder is a no-op by design: the ten example
 * slugs already exist (under `cemeteries.slug`'s unique index) and a
 * second insert would violate it. The existence check below makes the
 * seeder idempotent across that case; it only materializes when the data
 * is genuinely absent (e.g. a hand-built database that never ran the data
 * migrations).
 */
final class CemeteryExampleDataSeeder extends Seeder
{
    public function run(): void
    {
        if (DB::table('cemeteries')->whereIn('slug', CemeteryExampleData::slugs())->exists()) {
            return;
        }

        CemeteryExampleData::seed();
        CemeteryExampleData::applyBackfill();
        CemeteryExampleData::seedGraveRecords();
    }
}

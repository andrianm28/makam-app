<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\LaunchCity;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Makes `php artisan db:seed` produce the five canonical launch cities
 * (Jakarta, Bogor, Depok, Tangerang, Bekasi) in `launch_cities`.
 *
 * In this repository the migrations are the delivery mechanism for every
 * environment (CI and deploy never run `db:seed` — the canonical five land
 * with the table via `2026_08_15_110010_seed_launch_cities`), so on any
 * database that has been `migrate`d this seeder is a no-op by design:
 * `firstOrCreate` keyed on the unique `code` column. It only materializes
 * when the rows are genuinely absent (e.g. a hand-built database that
 * never ran the data migration).
 */
final class LaunchCitySeeder extends Seeder
{
    public function run(): void
    {
        foreach (LaunchCityCode::KNOWN_CODES as $sortOrder => $code) {
            LaunchCity::query()->firstOrCreate(
                ['code' => $code],
                [
                    'label' => Str::title(mb_strtolower($code)),
                    'is_active' => true,
                    'sort_order' => $sortOrder + 1,
                ],
            );
        }
    }
}

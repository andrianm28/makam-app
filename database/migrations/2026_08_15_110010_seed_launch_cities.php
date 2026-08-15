<?php

declare(strict_types=1);

use App\Domain\CemeteryDirectory\LaunchCityCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Seeds the five canonical launch cities (`AGENTS.md` §Mandatory MVP UX:
 * "Launch locations include Jakarta, Bogor, Depok, Tangerang, and Bekasi")
 * into `launch_cities`.
 *
 * ---------------------------------------------------------------------------
 * Why a DATA MIGRATION, not just `database/seeders/LaunchCitySeeder.php`
 * ---------------------------------------------------------------------------
 * Nothing in CI or any deployment process runs `php artisan db:seed`
 * (verified in `.github/workflows/ci.yml`, the Dockerfile, the entrypoint,
 * and compose — see `2026_07_26_170400_seed_faq_categories_and_articles.
 * php`'s doc block for the same argument). The five launch cities MUST
 * land with the table in every environment, so the canonical five ship
 * through `php artisan migrate` here; `database/seeders/LaunchCitySeeder.
 * php` exists as the idempotent manual `db:seed` companion (the
 * `CemeteryExampleDataSeeder` pattern).
 *
 * ---------------------------------------------------------------------------
 * Single source for the rows
 * ---------------------------------------------------------------------------
 * The codes are read from `LaunchCityCode::KNOWN_CODES` — the one PHP-side
 * source of the catalogue (`AGENTS.md` §Documentation) — not restated
 * here. Labels derive from the code by rule (`Str::title(mb_strtolower(
 * $code))`: `JAKARTA` -> `Jakarta`), and `sort_order` is the list index
 * + 1, so the seeded display order is exactly the documented order.
 *
 * `updateOrInsert` keyed on `code` keeps this idempotent for any
 * environment that has already applied it.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        foreach (LaunchCityCode::KNOWN_CODES as $sortOrder => $code) {
            DB::table('launch_cities')->updateOrInsert(
                ['code' => $code],
                [
                    'label' => Str::title(mb_strtolower($code)),
                    'is_active' => true,
                    'sort_order' => $sortOrder + 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );
        }
    }

    public function down(): void
    {
        // Scoped to the five canonical codes only — admin-added rows are
        // not this migration's to remove.
        DB::table('launch_cities')->whereIn('code', LaunchCityCode::KNOWN_CODES)->delete();
    }
};

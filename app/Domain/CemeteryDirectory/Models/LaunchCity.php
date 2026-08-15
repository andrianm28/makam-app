<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory\Models;

use App\Domain\CemeteryDirectory\LaunchCityCode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Eloquent model for `launch_cities` — the admin-extendable, table-backed
 * source of the platform's launch cities, replacing the closed constant
 * list as the *read* source for the public flow (see `LaunchCityCode`'s
 * own doc block for the constant's remaining role).
 *
 * `AGENTS.md` §Mandatory MVP UX requires Jakarta, Bogor, Depok, Tangerang,
 * and Bekasi; those five ship with the table via the
 * `2026_08_15_110010_seed_launch_cities` data migration (CI and deploy
 * never run `db:seed` — see `database/seeders/LaunchCitySeeder.php` for
 * the manual `db:seed` companion).
 *
 * ---------------------------------------------------------------------------
 * Validation posture: shape, not membership
 * ---------------------------------------------------------------------------
 * Admin extension is a deliberate, product-approved capability (spec
 * §4.6): a new city is created here first and then flows into the public
 * screens. `LaunchCityCode::assertKnown()` therefore does NOT gate saves
 * on this model — it would make the very extension this table exists for
 * impossible. The saving hook validates only the *shape* of a code
 * (non-blank, uppercase — matching how the canonical codes are written),
 * and the `code` column's unique index is the final word on duplicates.
 * `LaunchCityCode::assertKnown` stays as the gate for the *public fallback
 * path* only: `LaunchCityQuery::isKnown()` accepts a code from the table
 * OR the canonical constants, and `CemeteryPublicQuery::launchCities()`
 * falls back to `LaunchCityCode::KNOWN_CODES` when the table has no active
 * rows (seed guarantees it does).
 */
final class LaunchCity extends Model
{
    protected $table = 'launch_cities';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'code',
        'label',
        'is_active',
        'sort_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $city): void {
            if (! is_string($city->code) || $city->code === '' || $city->code !== Str::upper(trim($city->code))) {
                throw new InvalidArgumentException(
                    "Launch city code must be a non-blank uppercase string, got [{$city->code}]."
                );
            }
        });
    }
}

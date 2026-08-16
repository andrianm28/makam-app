<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\Models\LaunchCity;
use Illuminate\Database\QueryException;

/**
 * The table-backed read seam for launch cities — the single definition of
 * "which cities exist" for every city-code consumer in the codebase:
 *
 *   - `CemeteryPublicQuery::launchCities()` / `::inCity()` (public lists)
 *   - `BookingDraft` and `Cemetery` saving hooks, `SaveBookingDraftStep`
 *     (write-path validation)
 *   - `RenewalStart` and `CemeteryDirectoryIndex` (public screens)
 *   - `CemeteryForm` (admin city options)
 *
 * An admin-added `launch_cities` row (spec §4.6's product-approved
 * extension) therefore flows through every seam without any consumer
 * knowing the catalogue is no longer the five constants. `LaunchCityCode::
 * KNOWN_CODES` remains the canonical fallback: `isKnown()` accepts it, and
 * `CemeteryPublicQuery::launchCities()` (plus the two screens' option
 * lists) falls back to it when the table has no active rows — the seed
 * migration guarantees it does.
 */
final class LaunchCityQuery
{
    /**
     * Active rows in display order. `is_active = false` keeps a city
     * "known" (drafts referencing it stay valid) while removing it from the
     * public lists.
     *
     * ---------------------------------------------------------------------
     * Degradation contract: a failed read returns [], never throws
     * ---------------------------------------------------------------------
     * This is a PUBLIC render read — the booking wizard, renewal, and
     * directory blades reach it (via `CemeteryPublicQuery::launchCities()`)
     * on every render, and every caller falls back to the canonical
     * `LaunchCityCode::KNOWN_CODES` when it returns []. A failed or
     * aborted-transaction read must therefore degrade to that same
     * fallback: on PostgreSQL one failed statement aborts the WHOLE
     * transaction (SQLSTATE 25P02), so a caught, already-degraded failure
     * elsewhere (e.g. the wizard's deliberately-dropped cemetery tables)
     * poisons this SELECT too — throwing here would turn a handled
     * degradation into a 500 on a public screen. The fallback contract
     * therefore extends to "unreadable", not just "empty".
     *
     * Validation/write paths deliberately keep failing closed: `isKnown()`
     * throws on an unreadable table because a code that cannot be verified
     * must not be trusted.
     *
     * @return list<array{code: string, label: string}>
     */
    public static function activeCities(): array
    {
        try {
            return LaunchCity::query()
                ->where('is_active', true)
                ->orderBy('sort_order')
                ->get(['code', 'label'])
                ->map(fn (LaunchCity $city): array => ['code' => $city->code, 'label' => $city->label])
                ->all();
        } catch (QueryException) {
            return [];
        }
    }

    /**
     * A code is known when it exists in the table (active or not) or is one
     * of the canonical `LaunchCityCode::KNOWN_CODES`. The constant fallback
     * keeps the canonical five valid even if every row were deleted — the
     * same guarantee `CemeteryPublicQuery::launchCities()` relies on.
     */
    public static function isKnown(string $code): bool
    {
        return LaunchCity::query()->where('code', $code)->exists()
            || in_array($code, LaunchCityCode::KNOWN_CODES, true);
    }
}

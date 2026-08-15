<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory;

use App\Domain\CemeteryDirectory\Models\LaunchCity;

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
     * @return list<array{code: string, label: string}>
     */
    public static function activeCities(): array
    {
        return LaunchCity::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get(['code', 'label'])
            ->map(fn (LaunchCity $city): array => ['code' => $city->code, 'label' => $city->label])
            ->all();
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

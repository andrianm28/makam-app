<?php

declare(strict_types=1);

namespace App\Livewire\Public\Directory\Support;

use App\Domain\CemeteryCapability\Actions\ResolveCemeteryCapabilityProfile;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Support\Collection;

/**
 * The public-facing read entry point for the TPU/TPS directory — Sprint 4
 * S4-T6, `.kiro/specs/cemetery-directory-and-availability` AC1/AC2/AC3/
 * AC4/AC12.
 *
 * Every read here starts from `Cemetery::published()`, never a bare
 * `Cemetery::query()`. That scope is AC2's base guarantee and the model's
 * own doc block says so explicitly ("every public directory read must start
 * here (or a helper composing it)"); one seeded cemetery
 * (`tps-bekasi-harapan-indah`) is deliberately `draft` precisely so that
 * exclusion is provable rather than vacuous.
 *
 * `App\Livewire\Public\Directory\**` components and their Blade views MUST
 * call through this class and never touch `Cemetery`/`CemeteryPackage`
 * directly — the same discipline `App\Domain\Faq\FaqPublicQuery` enforces
 * for the FAQ pages.
 *
 * ---------------------------------------------------------------------------
 * PLACEMENT — a deviation from convention, flagged deliberately
 * ---------------------------------------------------------------------------
 * The established convention puts this class in the DOMAIN namespace
 * (`App\Domain\Faq\FaqPublicQuery`, `MarketplaceCatalogQuery`), and by that
 * convention this file belongs at `app/Domain/CemeteryDirectory/
 * CemeteryPublicQuery.php`. It is here instead because S4-T6's batch scope
 * is `app/Livewire/Public/Directory/**` plus views and tests, with
 * `app/Domain/CemeteryDirectory/**` and `app/Domain/CemeteryCapability/**`
 * explicitly to be consumed as-is and not added to. Writing a new class
 * into a directory another batch owns would be the larger violation of the
 * two, so this is the smaller one, made visibly rather than silently.
 *
 * Nothing here reaches around the domain's guarantees — it composes
 * `Cemetery::scopePublished()`, `CemeteryPackage::scopeActive()`, and
 * `ResolveCemeteryCapabilityProfile` rather than reimplementing any of
 * them. **Recommended follow-up:** move this class to
 * `app/Domain/CemeteryDirectory/CemeteryPublicQuery.php` once that module
 * is writable again, and leave nothing behind here.
 */
final class CemeteryDirectoryQuery
{
    /**
     * AC1's five launch cities, keyed by code, valued by display label —
     * `JAKARTA => 'Jakarta'`.
     *
     * The list and its order come from `LaunchCityCode::KNOWN_CODES`, which
     * is itself sourced from `docs/product/booking-wizard-fields.md`. This
     * class does not restate the five names (`AGENTS.md` §Documentation),
     * and the label is a structural transform of the code rather than a
     * hand-maintained second copy — so a city added to the canonical list
     * appears here automatically and CANNOT be silently omitted, which is
     * exactly the negative criterion "No hidden omission of a required MVP
     * city".
     *
     * @return array<string, string>
     */
    public static function launchCities(): array
    {
        $cities = [];

        foreach (LaunchCityCode::KNOWN_CODES as $code) {
            $cities[$code] = ucfirst(strtolower($code));
        }

        return $cities;
    }

    /**
     * The two `CemeteryType` values, keyed by code, valued by display
     * label. `TPU`/`TPS` are acronyms and are shown as-is — expanding them
     * inline would be inventing product copy; the directory page explains
     * both in prose once instead.
     *
     * @return array<string, string>
     */
    public static function types(): array
    {
        $types = [];

        foreach (CemeteryType::KNOWN_TYPES as $code) {
            $types[$code] = $code;
        }

        return $types;
    }

    /**
     * AC2: "WHEN a user applies a city/regency filter THE SYSTEM SHALL
     * filter published TPU/TPS by that city/regency." A `null` filter means
     * "no filter applied", never "match nothing".
     *
     * Callers are expected to have already rejected values outside
     * `LaunchCityCode::KNOWN_CODES`/`CemeteryType::KNOWN_TYPES` (the
     * component surfaces that as a §6.3 validation state). Passing an
     * unknown value here is not an error — it simply matches nothing, which
     * is the safe direction for a public read.
     *
     * @return Collection<int, Cemetery>
     */
    public static function published(?string $city = null, ?string $type = null): Collection
    {
        $query = Cemetery::query()->published();

        if ($city !== null) {
            $query->inCity($city);
        }

        if ($type !== null) {
            $query->ofType($type);
        }

        /** @var Collection<int, Cemetery> $cemeteries */
        $cemeteries = $query->orderBy('name')->get();

        // Ordered in PHP rather than SQL: the intended order is "launch-city
        // order, then name", and launch-city order is `LaunchCityCode::
        // KNOWN_CODES`' documented order (Jakarta, Bogor, Depok, Tangerang,
        // Bekasi) — not alphabetical, so it cannot be expressed as a plain
        // ORDER BY. The portable SQL alternative is a CASE expression that
        // restates the five codes a second time, which is the duplication
        // `AGENTS.md` §Documentation forbids. At this catalogue's real size
        // (ten seeded rows, and a directory of launch-city cemeteries is
        // never going to be a large table) sorting the result set in PHP is
        // the cheaper honesty.
        $cityOrder = array_flip(LaunchCityCode::KNOWN_CODES);

        return $cemeteries
            ->sortBy([
                fn (Cemetery $cemetery): int => $cityOrder[$cemetery->city] ?? PHP_INT_MAX,
                fn (Cemetery $cemetery): string => (string) $cemetery->name,
            ])
            ->values();
    }

    /**
     * The detail route's lookup. Returns `null` for a slug that does not
     * exist AND for one belonging to a draft/unpublished cemetery — the two
     * are never distinguished, so a caller cannot use the response to learn
     * that an unpublished cemetery exists. Same discipline as
     * `FaqPublicQuery::findBySlug()`.
     */
    public static function findPublishedBySlug(string $slug): ?Cemetery
    {
        /** @var Cemetery|null $cemetery */
        $cemetery = Cemetery::query()
            ->published()
            ->where('slug', $slug)
            ->first();

        return $cemetery;
    }

    /**
     * AC4 + AC12 in one call: resolve the cemetery's current capability
     * profile (falling back to safe defaults when it has none) and project
     * it down to the four public modes before it can reach a view.
     *
     * This is the ONLY way a Directory component should obtain capability
     * information. It never returns a `CemeteryCapabilityProfile` — see
     * `PublicCapabilityProjection`'s doc block for why the six-to-four
     * narrowing is structural rather than a filtering step.
     */
    public static function publicCapabilities(Cemetery $cemetery): PublicCapabilityProjection
    {
        return PublicCapabilityProjection::from(
            (new ResolveCemeteryCapabilityProfile)($cemetery)
        );
    }

    /**
     * AC6's package/class-level availability rows for one cemetery, active
     * only, in the operator's configured display order.
     *
     * Most seeded cemeteries have none — `2026_07_26_190300_seed_cemeteries
     * _and_capability_profiles.php` deliberately populates packages on two
     * of the ten, because "AC6 only requires that package/class-level
     * availability be EXPRESSIBLE, not that every seeded cemetery have it
     * populated." An empty collection is therefore a NORMAL result here,
     * not a degraded one, and the detail view says so rather than
     * rendering a bare "no data".
     *
     * @return Collection<int, CemeteryPackage>
     */
    public static function activePackages(Cemetery $cemetery): Collection
    {
        /** @var Collection<int, CemeteryPackage> $packages */
        $packages = $cemetery->packages()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $packages;
    }
}

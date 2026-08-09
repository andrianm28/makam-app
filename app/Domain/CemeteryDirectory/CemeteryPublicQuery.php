<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory;

use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * THE public-facing read entry point for the TPU/TPS directory — the single
 * place any public screen reads `cemeteries` from.
 *
 * Every method here starts from `Cemetery::published()`, never a bare
 * `Cemetery::query()`. That scope is `cemetery-directory-and-availability`
 * AC2's base guarantee, and the model's own doc block says so explicitly:
 * "every public directory read must start here (or a helper composing it)".
 * One seeded cemetery (`tps-bekasi-harapan-indah`) is deliberately `draft`
 * precisely so that exclusion is provable rather than vacuous.
 *
 * ---------------------------------------------------------------------------
 * Why this class exists — a merge, not a new abstraction
 * ---------------------------------------------------------------------------
 * Sprint 4 ran S4-T6 (cemetery directory) and S4-T7 (renewal) concurrently.
 * `makam-livewire-page` requires public reads to go through the owning
 * domain's `*Query` class and says to add one when none exists — but this
 * module belonged to neither batch, so BOTH built a stand-in on their own
 * side rather than write here:
 *
 *   - `App\Livewire\Public\Directory\Support\CemeteryDirectoryQuery` (S4-T6)
 *   - `App\Domain\Renewal\RenewalLocationQuery` (S4-T7)
 *
 * Two read paths into one model, neither in the right place. Re-pointing
 * either at the other was rejected on inspection: the S4-T6 class sits in
 * `App\Livewire\**`, so a `App\Domain\**` consumer depending on it would
 * invert the dependency `AGENTS.md` §Architecture forbids ("Keep domain
 * logic outside controllers, Livewire components, and Filament Resources").
 * This class is the resolution both batches asked the lead for, authorised
 * 8 Aug 2026. Both stand-ins are deleted; nothing is left behind as a second
 * read path.
 *
 * The two implementations agreed on the load-bearing decision without having
 * coordinated — see `launchCities()` — which is decent evidence it is right.
 *
 * ---------------------------------------------------------------------------
 * What deliberately did NOT move here
 * ---------------------------------------------------------------------------
 * The AC12 public capability projection stays at the UI boundary
 * (`App\Livewire\Public\Directory\Support\PublicCapabilityProjection`,
 * whose `forCemetery()` resolves and projects in one call). It is defined by
 * `docs/contracts/openapi.yaml`'s public schema rather than by this domain's
 * invariants, and returning it from here would make this class depend on
 * `App\Livewire\**` — the very inversion this merge exists to avoid.
 */
final class CemeteryPublicQuery
{
    /**
     * All five MVP launch cities, ALWAYS — `AGENTS.md` §Mandatory MVP UX
     * ("Launch locations include Jakarta, Bogor, Depok, Tangerang, and
     * Bekasi") and `cemetery-directory-and-availability` AC1.
     *
     * ---------------------------------------------------------------------
     * NEVER filter this to cities that have published cemeteries
     * ---------------------------------------------------------------------
     * It is the one change to this method that looks like an obvious
     * improvement and is actually the failure the spec's negative criteria
     * name outright: "No hidden omission of a required MVP city." A launch
     * city is a statement about where the platform operates, not a summary
     * of today's inventory. A city with no onboarded operator is an honest
     * empty state on the next screen (§6.2); dropping it from the filter
     * tells a grieving family the city is unserved. `AGENTS.md`'s "Never
     * remove a stakeholder MVP item merely because an external gate is
     * closed" is the same instinct applied to a gate.
     *
     * Both Sprint 4 batches reached this independently and both flagged it
     * as non-negotiable for this merge. It is enforced by a test that
     * empties a city out and asserts the filter survives
     * (`CemeteryDirectoryIndexRouteTest::
     * test_a_launch_city_keeps_its_filter_even_with_no_published_cemeteries`)
     * — the simpler "all five appear" assertion CANNOT catch the regression,
     * because every seeded city currently has published rows. Carry that
     * test forward with this method.
     *
     * The label is derived from the code (`JAKARTA` -> `Jakarta`), never a
     * second hand-maintained list: `LaunchCityCode` is the one PHP-side
     * source for the catalogue (`AGENTS.md` §Documentation), so a city added
     * there appears here automatically and cannot be silently omitted.
     *
     * @return list<array{code: string, label: string}>
     */
    public static function launchCities(): array
    {
        return array_map(
            static fn (string $code): array => [
                'code' => $code,
                'label' => Str::title(mb_strtolower($code)),
            ],
            LaunchCityCode::KNOWN_CODES,
        );
    }

    /**
     * The two `CemeteryType` values. `TPU`/`TPS` are acronyms shown as-is —
     * expanding them inline would be inventing product copy; a screen that
     * needs the expansion explains both in prose once instead.
     *
     * @return list<array{code: string, label: string}>
     */
    public static function types(): array
    {
        return array_map(
            static fn (string $code): array => ['code' => $code, 'label' => $code],
            CemeteryType::KNOWN_TYPES,
        );
    }

    /**
     * AC2: "WHEN a user applies a city/regency filter THE SYSTEM SHALL
     * filter published TPU/TPS by that city/regency." A `null` filter means
     * "no filter applied", never "match nothing".
     *
     * An unknown city/type value is not an error here — it simply matches
     * nothing, which is the safe direction for a public read. Screens are
     * expected to surface their own §6.3 validation state for a tampered
     * value rather than relying on this method to reject it.
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

        // Sorted in PHP, not SQL: the intended order is "launch-city order,
        // then name", and launch-city order is `LaunchCityCode::KNOWN_CODES`'
        // documented order (Jakarta, Bogor, Depok, Tangerang, Bekasi) — not
        // alphabetical, so it cannot be a plain ORDER BY. The portable SQL
        // alternative is a CASE expression restating the five codes a second
        // time, which is the duplication `AGENTS.md` §Documentation forbids.
        // A directory of launch-city cemeteries is never a large table.
        $cityOrder = array_flip(LaunchCityCode::KNOWN_CODES);

        return $cemeteries
            ->sortBy([
                fn (Cemetery $cemetery): int => $cityOrder[$cemetery->city] ?? PHP_INT_MAX,
                fn (Cemetery $cemetery): string => (string) $cemetery->name,
            ])
            ->values();
    }

    /**
     * Published TPU/TPS in one launch city, by name.
     *
     * An unknown city code returns an empty collection rather than throwing:
     * callers are public screens reading a user-supplied value, and
     * `LaunchCityCode::assertKnown()` at that boundary would turn a tampered
     * query string into a 500.
     *
     * @return Collection<int, Cemetery>
     */
    public static function inCity(string $cityCode): Collection
    {
        if (! LaunchCityCode::isKnown($cityCode)) {
            return new Collection;
        }

        /** @var Collection<int, Cemetery> $cemeteries */
        $cemeteries = Cemetery::query()
            ->published()
            ->inCity($cityCode)
            ->orderBy('name')
            ->get();

        return $cemeteries;
    }

    /**
     * The public detail route's lookup, by slug.
     *
     * Returns `null` for a slug that does not exist AND for one belonging to
     * a draft/unpublished cemetery — the two are never distinguished, so a
     * caller cannot use the response to learn that an unpublished cemetery
     * exists. Same discipline as `App\Domain\Faq\FaqPublicQuery::
     * findBySlug()`.
     *
     * No UUID guard is needed or wanted here: `slug` is a plain `string`
     * column (`2026_07_26_190000_create_cemeteries_table.php:109`), so any
     * input is a clean miss. See `findPublishedById()` for why the id-keyed
     * sibling is different — the two have genuinely different failure modes
     * and must not be collapsed into one polymorphic `find()`.
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
     * One published cemetery by id, or `null`.
     *
     * ---------------------------------------------------------------------
     * The UUID-shape guard is NOT cosmetic validation — do not remove it
     * ---------------------------------------------------------------------
     * `cemeteries.id` is a real `uuid` COLUMN on PostgreSQL
     * (`2026_07_26_190000_create_cemeteries_table.php:103`), so comparing it
     * against a non-UUID string is a database-level TYPE ERROR ("invalid
     * input syntax for type uuid"), not a miss. Without this guard a
     * tampered `?tpu=garbage` returns a 500 from a public screen instead of
     * a clean null.
     *
     * It passes silently on SQLite, which `phpunit.xml` uses locally while
     * CI runs Postgres 18 — so a green local run is not evidence this works
     * (`makam-testing`: CI is the oracle). Found by the S4-T7 batch
     * (finding F9) and carried here in the merge so no consumer has to
     * remember it.
     */
    public static function findPublishedById(string $cemeteryId): ?Cemetery
    {
        $cemeteryId = trim($cemeteryId);

        if ($cemeteryId === '' || ! Str::isUuid($cemeteryId)) {
            return null;
        }

        /** @var Cemetery|null $cemetery */
        $cemetery = Cemetery::query()
            ->published()
            ->whereKey($cemeteryId)
            ->first();

        return $cemetery;
    }

    /**
     * AC6's package/class-level availability rows for one cemetery, active
     * only, in the operator's configured display order.
     *
     * An empty collection is a NORMAL result, not a degraded one: the seed
     * migration populates packages on two of the ten cemeteries because
     * "AC6 only requires that package/class-level availability be
     * EXPRESSIBLE, not that every seeded cemetery have it populated." A
     * screen must present that as "not recorded yet", never as a failure.
     *
     * Unlike every other method here this one receives an already-loaded
     * model rather than starting its own query, so this class's headline rule
     * ("every method here starts from `Cemetery::published()`") had nowhere
     * to live except in caller discipline. All current callers do obtain the
     * model from a published-scoped method first, but the guarantee belongs
     * at this seam, not in the callers' memory — including the two Booking
     * call sites this module does not own. An unpublished cemetery therefore
     * returns an empty collection, matching `inCity()`'s posture for an
     * unknown city code: an invalid input on a public read is an empty
     * result, never an exception.
     *
     * @return Collection<int, CemeteryPackage>
     */
    public static function activePackages(Cemetery $cemetery): Collection
    {
        if (! $cemetery->isPublished()) {
            return new Collection;
        }

        /** @var Collection<int, CemeteryPackage> $packages */
        $packages = $cemetery->packages()
            ->active()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get();

        return $packages;
    }
}

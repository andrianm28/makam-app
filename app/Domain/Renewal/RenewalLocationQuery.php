<?php

declare(strict_types=1);

namespace App\Domain\Renewal;

use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The read entry point for the renewal journey's steps 1 and 2 (AC1: city,
 * then TPU/TPS) — Sprint 4 S4-T7, screen PUB-030.
 *
 * ---------------------------------------------------------------------------
 * Why this composes `Cemetery` directly, and what should replace it
 * ---------------------------------------------------------------------------
 * `makam-livewire-page` requires every public read to go through the owning
 * domain's `*Query` class rather than an Eloquent model, and it says what
 * to do when none exists: "If the domain has no `*Query` class yet, add one
 * rather than querying the model directly." `app/Domain/CemeteryDirectory`
 * has no such class today (`Actions/` holds only a `.gitkeep`), and adding
 * one would mean writing into a module this batch does not own — S4-T6 owns
 * `CemeteryDirectory` and is being built in parallel with this task.
 *
 * So this class stands in for it from the renewal side, and it is
 * deliberately thin: two methods, both starting from
 * `Cemetery::published()`, which is where requirements.md AC2's
 * draft-exclusion guarantee actually lives (see that scope's own doc
 * block). It composes with that guarantee rather than around it.
 *
 * When `CemeteryDirectory` grows its own public query class, the two
 * methods below should be re-pointed at it and this class kept only for
 * whatever is genuinely renewal-specific. Reading a sibling module's model
 * through its own documented scope is an ordinary cross-module read — the
 * same shape `Cemetery::capabilityProfiles()` already uses into
 * `CemeteryCapability` — not a table-ownership violation; the point of this
 * note is that it should not stay the long-term shape. Raised with the
 * `CemeteryDirectory` batch rather than silently duplicated.
 *
 * ---------------------------------------------------------------------------
 * Correction (8 Aug 2026, after comparing notes with the S4-T6 batch)
 * ---------------------------------------------------------------------------
 * The paragraph above says to re-point these methods at `CemeteryDirectory`'s
 * own query class once one exists. That instruction is now DANGEROUS AS
 * WRITTEN and is corrected here rather than deleted, following the
 * `sprint-plan.md` N-10/N-11 precedent for superseding a statement.
 *
 * One such class now exists — but it is `App\Livewire\Public\Directory\
 * Support\CemeteryDirectoryQuery`, in the LIVEWIRE namespace, not the domain
 * one. The S4-T6 batch hit the identical wall this class documents (that
 * module was not theirs to add to either) and resolved it the same way, on
 * their own side. Re-pointing at it would therefore make an
 * `App\Domain\**` class depend on `App\Livewire\**` — precisely the
 * inversion `AGENTS.md` §Architecture forbids ("Keep domain logic outside
 * controllers, Livewire components, and Filament Resources"). That is a
 * worse violation than the two thin read paths that exist today, both of
 * which at least compose `Cemetery::scopePublished()` correctly.
 *
 * So: DO NOT re-point at the Livewire-namespaced class. The real fix, raised
 * with the batch lead by both batches independently, is one shared
 * `app/Domain/CemeteryDirectory/CemeteryPublicQuery.php` consumed by both,
 * with this class and the S4-T6 `Support` class DELETED rather than left as
 * second read paths. The two implementations already agree on the load-
 * bearing decision without having coordinated — both derive the city list
 * from all five `LaunchCityCode::KNOWN_CODES` rather than filtering to
 * cities that happen to have published rows, for the same hidden-MVP-city
 * negative criterion — so the merge is close to mechanical.
 *
 * When that merge happens, `findPublishedCemetery()`'s UUID-shape guard
 * below must move with it into the merged `findPublishedById()`. That is the
 * method that needs it, and putting it there once means no consumer has to
 * remember. The S4-T6 screens are not exposed to that bug today only because
 * they key on `slug` (a varchar) rather than on the uuid `id` column.
 */
final class RenewalLocationQuery
{
    /**
     * All five MVP launch cities, always, in `LaunchCityCode`'s own order —
     * requirements.md AC2 ("THE SYSTEM SHALL include the five MVP launch
     * areas in city selection") and `AGENTS.md` §Mandatory MVP UX ("Launch
     * locations include Jakarta, Bogor, Depok, Tangerang, and Bekasi").
     *
     * Deliberately NOT filtered down to cities that currently have a
     * published cemetery. A city with none is a real, honest step-2 empty
     * state (§6.2); dropping the city from step 1 instead would be the
     * "hidden omission of a required MVP city" `cemetery-directory-and-
     * availability` names as a negative criterion, and `AGENTS.md`'s
     * "Never remove a stakeholder MVP item merely because an external gate
     * is closed" is the same instinct applied to a gate.
     *
     * The label is derived from the code (`JAKARTA` -> `Jakarta`), not
     * stored in a second hand-maintained list — `AGENTS.md` §Documentation
     * forbids duplicating canonical catalogue data across code locations,
     * and `LaunchCityCode` is that catalogue's one PHP-side source.
     *
     * @return list<array{code: string, label: string}>
     */
    public static function cities(): array
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
     * Published TPU/TPS in one launch city, by name — the step-2 list.
     * Never includes a draft or unpublished cemetery: composed from
     * `Cemetery::scopePublished()`, never a bare `Cemetery::query()`.
     *
     * An unknown city code returns an empty collection rather than
     * throwing. The caller is a public screen reading a user-supplied
     * value, and `LaunchCityCode::assertKnown()` at that boundary would
     * turn a tampered query string into a 500; the screen validates the
     * code itself and this method simply has nothing to return.
     *
     * @return Collection<int, Cemetery>
     */
    public static function cemeteriesInCity(string $cityCode): Collection
    {
        if (! LaunchCityCode::isKnown($cityCode)) {
            return new Collection;
        }

        return Cemetery::query()
            ->published()
            ->inCity($cityCode)
            ->orderBy('name')
            ->get();
    }

    /**
     * One published cemetery by id, or `null` — used by the search screen
     * to confirm the cemetery chosen in step 2 is still a real, published
     * one before it will run a search against it. A draft cemetery must not
     * become searchable by holding on to its id.
     *
     * The UUID-shape guard is not cosmetic validation. `cemeteries.id` is a
     * real `uuid` COLUMN on PostgreSQL, and comparing it against a
     * non-UUID string is a database-level type error ("invalid input
     * syntax for type uuid"), not a miss — so a tampered `?tpu=garbage`
     * would 500 the public search screen rather than returning null. It
     * would pass silently on SQLite, which is what makes this the kind of
     * defect a local test run cannot catch (`makam-testing`: CI is the
     * oracle). Returning `null` sends the visitor back to step 2 instead.
     */
    public static function findPublishedCemetery(string $cemeteryId): ?Cemetery
    {
        $cemeteryId = trim($cemeteryId);

        if ($cemeteryId === '' || ! Str::isUuid($cemeteryId)) {
            return null;
        }

        return Cemetery::query()
            ->published()
            ->whereKey($cemeteryId)
            ->first();
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\GraveRegistry;

use App\Domain\GraveRegistry\Models\GraveRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONE public read entry point for the grave registry — Sprint 4 S4-T7,
 * `.kiro/specs/renewal-and-grave-registry` AC3, AC5, AC14.
 *
 * `design.md`'s Search section: "Search query must apply access policy
 * before returning fields." That is this class's whole contract. It returns
 * `GraveSearchOutcome` carrying `GraveRecordProjection` value objects and
 * NEVER returns a `GraveRecord` model, so a caller cannot reach a column
 * AC14's configured mode withholds. Public surfaces read through this
 * class; nothing outside `app/Domain/GraveRegistry/**` touches the model.
 *
 * This class does NOT check `G-DATA-01`. That check belongs to the screen,
 * before it decides whether to run a search at all — see
 * `App\Livewire\Public\Renewal\GraveSearch` and `GraveSearchOutcome`'s own
 * doc block for why the gate-closed case is deliberately not representable
 * as a search result. A caller that skips the gate check gets a working
 * search, which is a real footgun; it is accepted here rather than
 * duplicating gate resolution into the read path, because
 * `App\Platform\FeatureGate\ModeResolver` is documented as "the ONE place
 * that pairs a mode enum with its backing gate id" and re-deriving that
 * pairing here would be the duplication it exists to prevent. Flagged in
 * this batch's report rather than resolved silently.
 *
 * ---------------------------------------------------------------------------
 * Fuzzy matching: `pg_trgm` on PostgreSQL, substring elsewhere
 * ---------------------------------------------------------------------------
 * `phpunit.xml` defaults to SQLite locally while CI and production run
 * PostgreSQL (`makam-testing`), and `similarity()` exists only on the
 * latter. Rather than degrade the production query to the portable one,
 * this class runs the real trigram query where it is available and a plain
 * substring match where it is not, and says so. The consequence is stated
 * plainly because it is easy to be misled by: **a local SQLite run does not
 * exercise AC3's fuzzy behaviour at all.** The test that does is guarded
 * with `markTestSkipped()` on other drivers.
 *
 * ---------------------------------------------------------------------------
 * AC4 (< 500 ms at 100,000 records) is NOT certified by this class
 * ---------------------------------------------------------------------------
 * The query and its GIN trigram index exist (see
 * `2026_08_08_100000_create_grave_records_table.php`), but no benchmark has
 * been run at any scale. `docs/planning/sprint-plan.md` §9 defers this
 * spec's performance certification to Sprint 13. Anyone reading this class
 * as evidence for AC4 is reading it wrong: the status is NOT TESTED, not
 * passing.
 */
final class GraveRegistryPublicQuery
{
    /**
     * `pg_trgm`'s own default `similarity_threshold` is 0.3 and this
     * matches it deliberately rather than picking a different number: the
     * value is not tuned against real registry data (none exists in this
     * repository), so inventing a "better" threshold would be a fabricated
     * figure. Retuning it belongs to the batch that has an operator's real
     * file in hand.
     */
    public const float SIMILARITY_THRESHOLD = 0.3;

    /**
     * A public search returns at most this many rows. Not a pagination
     * feature — a disclosure ceiling. Without it, a one-letter name term
     * against a 100,000-row registry returns the registry, which is a bulk
     * export wearing a search box. A family looking for one relative never
     * needs more than this; a result set that hits the cap is a signal to
     * add a block or death date, which is what the UI says.
     */
    public const int MAX_RESULTS = 50;

    /**
     * Run one public search and apply AC14's access policy to every match.
     *
     * A criteria set with no terms at all returns an empty outcome without
     * touching the database — see `GraveSearchCriteria::hasAnyTerm()`. That
     * empty outcome reports as *no-result*, which is correct: nothing was
     * searched for, so nothing was found, and the screen's own guard stops
     * a blank submission before it reaches here anyway.
     */
    public static function search(GraveSearchCriteria $criteria): GraveSearchOutcome
    {
        // The UUID-shape check is defence in depth, not duplicated
        // validation. `grave_records.cemetery_id` is a real `uuid` column on
        // PostgreSQL, so comparing it against a non-UUID string is a
        // database type error rather than a miss — it would throw instead of
        // returning nothing. `App\Domain\CemeteryDirectory\
        // CemeteryPublicQuery::findPublishedById()` already stops that on the
        // public screen's path; this stops it for any other caller too, since this is a
        // public read entry point and the failure mode is a 500 on a search
        // form rather than an empty result.
        if (! $criteria->hasAnyTerm() || ! Str::isUuid($criteria->cemeteryId)) {
            return GraveSearchOutcome::empty();
        }

        $records = self::buildQuery($criteria)
            ->limit(self::MAX_RESULTS)
            ->get();

        $open = [];
        $restricted = [];

        foreach ($records as $record) {
            $projection = GraveRecordProjection::fromRecord($record, $record->cemetery?->name);

            if ($projection->isRestricted()) {
                $restricted[] = $projection;

                continue;
            }

            $open[] = $projection;
        }

        return new GraveSearchOutcome(openResults: $open, restrictedResults: $restricted);
    }

    /**
     * @return Builder<GraveRecord>
     */
    private static function buildQuery(GraveSearchCriteria $criteria): Builder
    {
        // Publication status is scoped HERE, not left to the caller. Before
        // this clause existed, `search()` carried an unstated precondition —
        // "whoever calls me has already proven this cemetery is published" —
        // that appeared nowhere in `GraveSearchCriteria`'s signature, and a
        // draft cemetery's records came back as fully-populated `open`
        // projections to anyone holding its UUID. The sole caller
        // (`GraveSearch`) does check, so nothing incorrect ever reached a
        // visitor, but a precondition invisible at the seam is not a control.
        //
        // `Cemetery::scopePublished()` IS the canonical definition of
        // published (AC2's base guarantee); composing it here is a reference
        // to that canon, not the duplication `AGENTS.md` §Documentation
        // forbids — which is exactly why this is fixed and the `G-DATA-01`
        // gate check above is not.
        $query = GraveRecord::query()
            ->with('cemetery')
            ->inCemetery($criteria->cemeteryId)
            ->whereHas('cemetery', static function (Builder $cemetery): void {
                $cemetery->published();
            });

        if ($criteria->block !== '') {
            $query->inBlock($criteria->block);
        }

        if ($criteria->deathDate !== '') {
            $query->diedOn($criteria->deathDate);
        }

        $normalizedName = GraveNameNormalizer::normalize($criteria->name);

        if ($normalizedName === '') {
            return $query->orderBy('deceased_name_normalized');
        }

        // GraveNameNormalizer collapses every non-letter/non-digit
        // character to a space, so `%`, `_` and `\` cannot survive into
        // the pattern below and there is nothing left to escape. The
        // bindings are still parameterised, not interpolated — this note
        // explains why no additional LIKE-wildcard escaping step is
        // needed, not why one was skipped.
        $like = '%'.$normalizedName.'%';

        if (! self::supportsTrigram()) {
            return $query
                ->where('deceased_name_normalized', 'like', $like)
                ->orderBy('deceased_name_normalized');
        }

        return $query
            ->where(function (Builder $inner) use ($like, $normalizedName): void {
                // Substring OR similarity, not similarity alone: an exact
                // substring match with a low trigram score (a short name
                // inside a long one) is the single most obvious thing a
                // family will type, and must never be ranked out by a
                // threshold tuned for typo tolerance.
                $inner
                    ->where('deceased_name_normalized', 'like', $like)
                    ->orWhereRaw(
                        'similarity(deceased_name_normalized, ?) >= ?',
                        [$normalizedName, self::SIMILARITY_THRESHOLD]
                    );
            })
            ->orderByRaw('similarity(deceased_name_normalized, ?) desc', [$normalizedName])
            ->orderBy('deceased_name_normalized');
    }

    /**
     * `pg_trgm`'s `similarity()` is PostgreSQL-only. The extension itself
     * is created by this table's own migration, guarded to `pgsql` the same
     * way every other driver-specific statement in `database/migrations/`
     * is (`makam-migration`).
     */
    public static function supportsTrigram(): bool
    {
        return DB::connection()->getDriverName() === 'pgsql';
    }
}

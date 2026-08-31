<?php

declare(strict_types=1);

namespace App\Domain\GraveRegistry;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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
 * `App\Livewire\Public\Renewal\RenewalStart` (formerly `GraveSearch`,
 * merged into it) and `GraveSearchOutcome`'s own doc block for why the
 * gate-closed case is deliberately not representable as a search result.
 * A caller that skips the gate check gets a working
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
 * AC4 (< 500 ms at 100,000 records) — real benchmark exists, scoped
 * ---------------------------------------------------------------------------
 * The query and its GIN trigram index exist (see
 * `2026_08_08_100000_create_grave_records_table.php`). `php artisan
 * bench:grave-search` (`BenchGraveSearchCommand`) now measures this class's
 * own `search()` p50/p95/p99 against a real, generated dataset in CI (the
 * `load-test` job) — but against the LARGEST single cemetery's ~1,000
 * records, not a flat 100,000-record scan, matching the real cemetery-scoped
 * shape every call to `search()` actually runs. A full 100,000-record
 * certification stays deferred to Phase 3 per `docs/planning/sprint-plan.md`
 * §9. Anyone reading this class as evidence for AC4 should read
 * `BenchGraveSearchCommand`'s own doc block and
 * `docs/testing/release-gates.md` §H for the real, current numbers, not this
 * comment.
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
        $records = self::matchedRecords($criteria);

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
     * The renewal journey's Screen 1 → Screen 2 handoff (`docs/superpowers/
     * specs/2026-08-29-wizard-screen-consolidation-design.md`). A visitor
     * picks a result by its ORDINAL POSITION in the open subset of the
     * current search — never a database id, because `GraveRecordProjection`
     * (what the rendered result rows actually are) deliberately has no `id`
     * property at all. This method re-runs the IDENTICAL search server-side
     * and resolves the real `GraveRecord` at that position, restricted to
     * OPEN-mode rows only — a restricted row can never be renewed online
     * regardless (`RenewalFee`'s own gate already refuses it), so there is
     * no legitimate reason for this method to ever hand one back.
     *
     * Returns `null` for an out-of-range index or a criteria that would not
     * search at all (mirroring `search()`'s own early returns) rather than
     * throwing — a race between render and click (the registry changed
     * underneath the visitor) is an ordinary, expected condition here, not
     * an error.
     *
     * That race does not ALWAYS fail safe to `null`. A row inserted or
     * reordered between the visitor's search render and their click can
     * shift what sits at a given ordinal position, so this method can
     * resolve to a DIFFERENT real record than the one the visitor actually
     * clicked, rather than detecting the mismatch and returning `null`.
     * This is an accepted property of the ordinal-index design, not a
     * defect: the grave registry is low-churn (no bulk-import or bulk-edit
     * path runs concurrently with public traffic), the render-to-click
     * window is human-speed (seconds, not the sub-second window a
     * high-churn table would need to worry about), and the fee-quote screen
     * immediately downstream shows the deceased's identifying details for
     * the customer to visually confirm before paying — an independent
     * safety net that catches exactly this race if it ever occurs.
     */
    public static function resolveOpenRecordAt(GraveSearchCriteria $criteria, int $index): ?GraveRecord
    {
        if ($index < 0) {
            return null;
        }

        $openRecords = self::matchedRecords($criteria)->filter(
            static fn (GraveRecord $record): bool => ! GraveRecordProjection::fromRecord($record, null)->isRestricted()
        )->values();

        $record = $openRecords->get($index);

        return $record instanceof GraveRecord ? $record : null;
    }

    /**
     * @return Collection<int, GraveRecord>
     */
    private static function matchedRecords(GraveSearchCriteria $criteria): Collection
    {
        // See `search()`'s former inline comments (now here, since both
        // callers share this guard): the UUID/date shape checks are defence
        // in depth against a database type error on PostgreSQL's typed
        // columns, not duplicated validation — the screen's own validation
        // is still what produces the right §6.3 state.
        if (! $criteria->hasAnyTerm() || ! Str::isUuid($criteria->cemeteryId)) {
            return collect();
        }

        if ($criteria->deathDate !== '' && ! self::isIsoDate($criteria->deathDate)) {
            return collect();
        }

        return self::buildQuery($criteria)->limit(self::MAX_RESULTS)->get();
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
                // Typed as Builder<Cemetery> via the phpdoc below so phpstan
                // can resolve the `published()` scope (scopePublished) on the
                // relation's builder — a bare Eloquent Builder hides it.
                /** @var Builder<Cemetery> $cemetery */
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
     * A strict `Y-m-d` calendar date, matching `rules()`' `date_format:Y-m-d`
     * on the screen and `docs/contracts/openapi.yaml`'s `death_date`
     * (`format: date`).
     *
     * The round-trip comparison is what makes it strict:
     * `createFromFormat()` alone accepts `2018-13-45` and rolls it forward
     * into a real date, so re-formatting and comparing is the only way to
     * reject a date that parses but was never the date the caller wrote. The
     * leading `!` resets the time fields so today's clock cannot leak into
     * the comparison.
     */
    private static function isIsoDate(string $value): bool
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $parsed !== false && $parsed->format('Y-m-d') === $value;
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

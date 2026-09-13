<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Makes a UUID-shape-guard test refuse to pass on a driver that cannot prove it.
 *
 * ---------------------------------------------------------------------------
 * The defect this exists to close
 * ---------------------------------------------------------------------------
 *
 * A family of tests in this suite feeds a deliberately malformed identifier
 * ('not-a-uuid', '', "' OR 1=1 --", a 4096-character string) to production
 * code and asserts a GRACEFUL outcome: null, an empty result, a clean refusal,
 * an honest empty state — never an exception. What makes that assertion
 * meaningful is a `Str::isUuid()` (or equivalent regex) guard in production
 * that stops the value BEFORE it reaches a `uuid` column as a bind parameter.
 *
 * On PostgreSQL, removing that guard is fatal. Probed directly against this
 * repository's schema on postgres:18:
 *
 *     DB::table('grave_records')->where('cemetery_id', 'not-a-uuid')->count();
 *     -> SQLSTATE[22P02]: Invalid text representation: 7 ERROR:
 *        invalid input syntax for type uuid: "not-a-uuid"
 *
 * On SQLite, the same query SUCCEEDS and returns 0. SQLite has no `uuid` type
 * and compares the string happily. So on SQLite the guarded path and the
 * unguarded path produce the IDENTICAL observable result, and every one of
 * those tests passes whether or not the guard it exists to protect still
 * exists. The test is not weak on SQLite; it is incapable. Deleting the
 * production guard cannot make it fail.
 *
 * ---------------------------------------------------------------------------
 * Why this is not a theoretical concern
 * ---------------------------------------------------------------------------
 *
 * `PreNeedInterestPageTest`'s own doc block records the outcome: on
 * 2 Sep 2026 a non-UUID subject id reached `find()` on a `uuid` primary key
 * and became a live HTTP 500 on a public page — while this suite was green.
 * Green on SQLite is not evidence about PostgreSQL, and PostgreSQL is what
 * CI and production run.
 *
 * ---------------------------------------------------------------------------
 * Why the driver differs between runs at all
 * ---------------------------------------------------------------------------
 *
 * `phpunit.xml` declares `DB_CONNECTION=sqlite` WITHOUT `force="true"`, and a
 * non-forced `<env>` yields to a variable that is already set in the real
 * environment. CI (`.github/workflows/ci.yml`, the "PHP (validate, lint,
 * analyse, test)" job) exports `DB_CONNECTION=pgsql`, so CI gets PostgreSQL;
 * a bare `vendor/bin/phpunit` on a developer machine exports nothing, so it
 * gets in-memory SQLite. Both are legitimate. Adding `force="true"` would
 * change the driver for the WHOLE suite at once and is deliberately not done
 * here.
 *
 * ---------------------------------------------------------------------------
 * The remedy, and why it is shaped this way
 * ---------------------------------------------------------------------------
 *
 * Call `requiresUuidTypeEnforcement()` as the FIRST statement of a test whose
 * assertion depends on `uuid` column typing. It resolves three ways:
 *
 *   - PostgreSQL          -> returns; the test runs and really can fail.
 *   - other driver, no CI -> `markTestSkipped()`, naming the exact guard that
 *                            went unproven.
 *   - other driver, in CI -> `fail()`.
 *
 * The local skip rather than a local failure: a red test on SQLite would be
 * reporting a defect in code that is in fact correct, which is its own kind of
 * dishonesty, and it would redden every developer's plain `vendor/bin/phpunit`
 * run. `AGENTS.md` §Infrastructure-agent execution draws exactly this
 * distinction — "Never report `PASS` for a check that was not executed; use
 * `BLOCKED` or `NOT TESTED` explicitly." `markTestSkipped()` is PHPUnit's
 * BLOCKED. It is also the established idiom here: 38 files already gate on
 * `getDriverName()`, and `RunReconciliationTest` names the specific constraint
 * in every one of its eight skip messages.
 *
 * The CI escalation to `fail()` answers the obvious objection to a skip — that
 * a skip nobody reads loses the signal just as quietly as a green lie. In CI
 * the skip branch is unreachable today (every DB-touching job in `ci.yml` sets
 * `DB_CONNECTION=pgsql`, verified job by job), so this branch costs nothing
 * now. It earns its keep the day someone flips the test job to SQLite for
 * speed: these twelve tests go red and name themselves, instead of dissolving
 * into a skipped count among the 40-odd skips the suite already prints.
 *
 * Cost of getting it wrong, stated plainly: if a future CI job legitimately
 * runs this suite on SQLite, it fails here and someone must either point that
 * job at PostgreSQL or consciously exclude these tests. That is a loud,
 * cheap, self-explaining failure. The failure mode it replaces — a guard
 * silently deleted under a green suite — cost a production 500.
 *
 * Scope note: this is about UUID TYPE ENFORCEMENT, not about PostgreSQL in
 * general. Do not reach for it to skip a test merely because PostgreSQL is
 * more convenient; use it only where the malformed-identifier assertion is
 * the point.
 */
trait RequiresUuidTypeEnforcement
{
    /**
     * @param  string  $guard  The production guard this test exists to protect,
     *                         named concretely enough that a reader of the skip
     *                         message knows what went unproven — e.g.
     *                         "CemeteryPublicQuery::findPublishedById()'s
     *                         Str::isUuid() guard on cemeteries.id".
     */
    protected function requiresUuidTypeEnforcement(string $guard): void
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'pgsql') {
            return;
        }

        $message = sprintf(
            'Cannot prove %s on the [%s] driver: only PostgreSQL type-checks a uuid '
            .'column, so the guarded and unguarded code paths are indistinguishable '
            .'here and this test would pass with the guard deleted. '
            .'Run with DB_CONNECTION=pgsql.',
            $guard,
            $driver,
        );

        if (self::runningInContinuousIntegration()) {
            $this->fail(
                'CI must prove this test for real, not skip it. '.$message
            );
        }

        $this->markTestSkipped($message);
    }

    /**
     * GitHub Actions sets `CI=true` on every runner; so does essentially every
     * other hosted CI. Treated as false for the unset/empty/"0"/"false" cases
     * so that a developer who happens to export `CI=0` is not surprised.
     */
    private static function runningInContinuousIntegration(): bool
    {
        $ci = getenv('CI');

        if (! is_string($ci)) {
            return false;
        }

        return ! in_array(strtolower(trim($ci)), ['', '0', 'false', 'off', 'no'], true);
    }
}

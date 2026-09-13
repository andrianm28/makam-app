<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The premise every `Str::isUuid()` guard in this application rests on —
 * asserted directly, because until now nothing asserted it anywhere.
 *
 * ---------------------------------------------------------------------------
 * What this is for
 * ---------------------------------------------------------------------------
 * A dozen production guards exist for one reason: comparing a PostgreSQL
 * `uuid` column against arbitrary client text is a DATABASE TYPE ERROR
 * (SQLSTATE 22P02), not a miss. Every one of those guards, and every test
 * that covers one, silently assumes that. None of them check it.
 *
 * That assumption is not permanent. An expand/contract migration that retyped
 * any column below from `uuid` to `text` — for a natural key, a provider
 * reference, a merge with an external system — would be entirely successful,
 * break no test, and quietly make every guard pointless AND every guard test
 * vacuous ON BOTH DRIVERS at once. `Tests\Support\RequiresUuidTypeEnforcement`
 * cannot catch that: it only distinguishes drivers, and after such a migration
 * both drivers would agree. Nothing else would catch it either.
 *
 * So this file is not a test of any production method. It is a
 * characterization test of the DATABASE, and it is deliberately the only
 * place in the suite where asserting a fact about the driver is the right
 * thing to do — everywhere else, an assertion about the engine would be a
 * test that never touches the code it claims to cover.
 *
 * ---------------------------------------------------------------------------
 * Why it asserts on BOTH drivers instead of skipping on SQLite
 * ---------------------------------------------------------------------------
 * `AuditEventAppendOnlyTest`'s precedent: where the two drivers genuinely
 * behave differently, assert the difference rather than skip, so a green run
 * on the weaker driver can never be mistaken for proof. That precedent applies
 * HERE, where a real behavioural difference exists to assert, and does not
 * apply to the guard tests themselves, where the guard is PHP-level and always
 * present so both drivers return identical results.
 *
 * The SQLite half is therefore not filler. It is the executable statement of
 * why a green local suite proves less than it appears to: the same comparison
 * that is fatal in production is silently empty here.
 *
 * ---------------------------------------------------------------------------
 * Asserting the SQLSTATE, not merely "it threw"
 * ---------------------------------------------------------------------------
 * A bare `expectException(QueryException::class)` would pass just as happily
 * if a table were renamed out from under this file (42P01, undefined table) —
 * i.e. it would keep reporting success while testing nothing. The SQLSTATE is
 * asserted explicitly so this file cannot rot into the very shape it exists to
 * detect.
 *
 * ---------------------------------------------------------------------------
 * How this file was mutation-tested, and what that does NOT prove
 * ---------------------------------------------------------------------------
 * Two mutations, both confirmed to fail: a provider row pointed at a column
 * that is already `text` fails with "no longer behaving as a `uuid` column",
 * and a row pointed at a missing table fails on the SQLSTATE assertion
 * (`42P01`) rather than passing on "it threw".
 *
 * The first is a SUBSTITUTE for the retype it simulates. For what this file
 * claims — "this column raises 22P02 on a non-uuid comparison" — exercising a
 * column that genuinely does not raise proves the detector fires, and the two
 * are equivalent. What it does NOT prove: a column retyped `uuid` -> `text`
 * *in place* could carry leftover constraints or a lingering check that an
 * always-`text` column never had. If that distinction ever matters, the real
 * retype is the experiment; it has not been run.
 */
final class UuidColumnTypingPremiseTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Every `uuid` column that a production `Str::isUuid()` (or equivalent
     * shape) guard stands in front of, with the guard that depends on it.
     *
     * That word "every" is a claim, and it is the entire value of this
     * provider — a list that merely covers "most" would go on reporting
     * success while the uncovered column was the one that moved. It was
     * derived by enumerating every `Str::isUuid()` and equivalent shape check
     * under `app/` and resolving each to the column it protects, including the
     * guards that have no test of their own. If you add a guard, add its
     * column here. If a guarded column genuinely cannot be covered, say why
     * here — do not narrow the claim to fit what the list happens to contain.
     *
     * A column that appears in this list and is no longer `uuid` is not a
     * failing test to silence — it means the guard in front of it has become
     * decorative and its test has gone vacuous, and both need revisiting.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function guardedUuidColumns(): array
    {
        return [
            'booking_drafts.id' => ['booking_drafts', 'id', 'BookingDraftQuery::find()'],
            'cemeteries.id' => ['cemeteries', 'id', 'CemeteryPublicQuery::findPublishedById() and BasePlotFloorMapPage::selectedCemetery()'],
            'grave_records.id' => ['grave_records', 'id', 'RenewalPayment'],
            'grave_records.cemetery_id' => ['grave_records', 'cemetery_id', 'GraveRegistryPublicQuery::search()'],
            'grave_plots.id' => ['grave_plots', 'id', 'BasePlotFloorMapPage::resolvePlot()'],
            'documents.id' => ['documents', 'id', 'IssueSignedUrl::issueForDocumentId() and DownloadDocument'],
            'orders.id' => ['orders', 'id', 'PreNeedInterestPage and BasePlotFloorMapPage::linkedOrder()'],
            'outbox_events.id' => ['outbox_events', 'id', "OutboxReplayCommand::replayOne()'s UUID regex"],
            'reconciliation_exceptions.id' => ['reconciliation_exceptions', 'id', 'ResolveException::resolve()'],

            // The two below sit behind guards that have NO test of their own.
            // That makes this file their only coverage, and it is partial: it
            // proves the column still type-checks, never that the guard in
            // front of it still exists. They were missed in this provider's
            // first revision, which is worth knowing — the artefact written to
            // detect a silently-weakened premise skipped precisely the two
            // guards nothing else watches.
            'subscription_cycles.id' => ['subscription_cycles', 'id', 'ApplyPaymentSettlement (money path; no test of its own)'],
            'work_orders.id' => ['work_orders', 'id', 'CareHistoryPage::ownedWorkOrder() (no test of its own)'],
        ];
    }

    /**
     * PostgreSQL: the comparison is fatal. This is the fact that makes every
     * guard load-bearing rather than defensive noise.
     */
    #[DataProvider('guardedUuidColumns')]
    public function test_postgres_refuses_a_non_uuid_comparison_against_a_guarded_column(
        string $table,
        string $column,
        string $guard,
    ): void {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'Asserted for SQLite by the sibling test below; this half needs DB_CONNECTION=pgsql.'
            );
        }

        try {
            DB::table($table)->where($column, 'not-a-uuid')->count();
        } catch (QueryException $e) {
            $this->assertSame(
                '22P02',
                $e->getCode(),
                "{$table}.{$column} raised a QueryException, but not the invalid-uuid-syntax one (22P02). "
                .'If this is 42P01 the table was renamed and this test is no longer checking anything.'
            );

            return;
        }

        $this->fail(
            "{$table}.{$column} accepted a non-UUID comparison without error on PostgreSQL. "
            ."That column is no longer behaving as a `uuid` column, which means {$guard}'s shape guard "
            .'is now decorative AND the test covering it has gone vacuous on every driver. '
            .'Do not delete this assertion — revisit the guard and its test.'
        );
    }

    /**
     * SQLite: the identical comparison succeeds and matches nothing. Asserted
     * rather than skipped, so the local suite states its own limitation out
     * loud instead of implying coverage it does not have.
     */
    #[DataProvider('guardedUuidColumns')]
    public function test_sqlite_silently_accepts_the_same_comparison_which_is_why_a_green_local_run_proves_less(
        string $table,
        string $column,
        string $guard,
    ): void {
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Characterizes the SQLite driver specifically; the PostgreSQL half is above.');
        }

        $this->assertSame(
            0,
            DB::table($table)->where($column, 'not-a-uuid')->count(),
            "SQLite is expected to compare {$table}.{$column} against arbitrary text happily and match nothing. "
            ."That is precisely why a green SQLite run cannot prove {$guard}'s shape guard still exists."
        );
    }
}

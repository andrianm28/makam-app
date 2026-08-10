<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Actions;

use App\Platform\Correlation\CorrelationContext;
use App\Platform\FinancialLedger\Exceptions\InvalidReconciliationException;
use App\Platform\FinancialLedger\Models\Reconciliation;
use App\Platform\FinancialLedger\ProviderStatement;
use App\Platform\FinancialLedger\ReconciliationExceptionStatus;
use App\Platform\FinancialLedger\ReconciliationExceptionType;
use App\Platform\FinancialLedger\ReconciliationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AC10: compare one badan usaha's journal for one period against the provider
 * statement for that period, and record every difference as a finding.
 *
 * ---------------------------------------------------------------------------
 * Reconciliation NEVER adjusts the ledger to make a statement match
 * ---------------------------------------------------------------------------
 * This is the single idea this Action exists to enforce, and it is spec text
 * (AC10's negative criteria), not a style preference.
 *
 * When the journal and the provider statement disagree, THE DISAGREEMENT IS THE
 * FINDING. It becomes a `reconciliation_exceptions` row that a human with
 * finance authority must decide on through `Actions\ResolveException`. It is
 * never silently absorbed by editing, reversing, or "correcting" the ledger to
 * agree with the statement — not even helpfully, not even when the statement is
 * obviously right.
 *
 * So: nothing in this file writes `journal_batches` or `journal_entries`. There
 * is no `Journal` dependency on this class at all, which is the strongest form
 * that promise can take — the write API is not even reachable from here.
 * `tests/Feature/FinancialLedger/RunReconciliationTest.php` proves it with a
 * `DB::listen` assertion that catches raw SQL too, because a before/after row
 * count would pass even if this code wrote and then rolled back.
 *
 * ---------------------------------------------------------------------------
 * A missing statement is not a zero
 * ---------------------------------------------------------------------------
 * When no statement is available for the period, this run records
 * `status = statement_missing` and creates NO exceptions. It does not default
 * the statement to `0` and report the whole journal as a mismatch against it:
 * that manufactures a false finding, buries the real problem (we do not have
 * the statement), and renders an unfetchable period as though it had been
 * checked. `AGENTS.md` and `docs/design/design-system.md` §6 both forbid
 * presenting a partial period as complete. The `reconciliations` migration
 * restates the rule as a database CHECK.
 *
 * ---------------------------------------------------------------------------
 * Re-running is safe, and the database is what makes it safe
 * ---------------------------------------------------------------------------
 * This runs on the `reports` queue with at-least-once delivery, and it is
 * scheduled, so it WILL run twice for the same period. Two UNIQUE indexes carry
 * that guarantee:
 *
 *  - `reconciliations (entity_ref, period)` — one conclusion per period;
 *  - `reconciliation_exceptions (entity_ref, period, type, subject_ref)` — one
 *    finding per difference, the natural key stated in natural terms rather
 *    than on a surrogate id.
 *
 * Exceptions are written with `insertOrIgnore` against that second index, which
 * is race-safe where a read-then-insert check in PHP would not be. A second run
 * therefore leaves every existing exception exactly as it found it: a resolved
 * one is not reopened, a recorded decision is not overwritten, and the amounts a
 * human already looked at are not rewritten underneath them.
 *
 * One consequence worth stating plainly rather than discovering later: because
 * an existing finding is never rewritten, a difference whose AMOUNT changes
 * between runs keeps its original recorded amounts. That is the deliberate
 * trade — a decided finding is evidence, and evidence that silently updates is
 * not evidence. A genuinely new difference has a different subject or type and
 * gets its own row.
 *
 * ---------------------------------------------------------------------------
 * What "the period" means
 * ---------------------------------------------------------------------------
 * A `YYYY-MM` calendar month, matched against `journal_batches.occurred_at`
 * (when the money moved), never `created_at` (when the row was written). A
 * correction posted later carries its own later `occurred_at` — `Journal`
 * deliberately does not backdate a reversal into a period that has already been
 * reported on — so it lands in the period it actually happened in rather than
 * re-opening this one.
 */
final class RunReconciliation
{
    /**
     * `YYYY-MM`. The `reconciliations` migration restates this as a PostgreSQL
     * CHECK; this pattern exists so a malformed period fails with a message
     * that names the expected form, and fails the same way on SQLite. Same
     * relationship `Journal::assertSourcePrefixed()` has with the business-key
     * CHECK.
     */
    private const string PERIOD_PATTERN = '/\A\d{4}-(0[1-9]|1[0-2])\z/D';

    /**
     * Reconcile one badan usaha's period against a provider statement.
     *
     * @param  string  $period  `YYYY-MM`.
     * @param  int|string  $entityRef  The `badan usaha` (AC4).
     * @param  ProviderStatement|null  $statement  NULL means the statement
     *                                             could not be fetched — see the class doc block. There is no live
     *                                             provider-statement adapter and this Action does not build one; it
     *                                             accepts a statement record/fixture as input.
     *
     * @throws InvalidReconciliationException on a malformed period, a blank
     *                                        entity reference, or a statement covering a different period or
     *                                        entity than the one being reconciled.
     */
    public function run(
        string $period,
        int|string $entityRef,
        ?ProviderStatement $statement,
        ?string $correlationId = null,
        ?CarbonImmutable $ranAt = null,
    ): Reconciliation {
        $period = trim($period);
        $entityRef = trim((string) $entityRef);

        if (preg_match(self::PERIOD_PATTERN, $period) !== 1) {
            throw InvalidReconciliationException::forMalformedPeriod($period);
        }

        if ($entityRef === '') {
            throw InvalidReconciliationException::forBlankEntityRef();
        }

        if ($statement !== null) {
            $this->assertStatementCovers($statement, $period, $entityRef);
        }

        $ranAt ??= CarbonImmutable::now();
        $correlationId ??= app(CorrelationContext::class)->current()?->value;

        // Safe to parse: the period matched `PERIOD_PATTERN` above, so it is a
        // real `YYYY-MM` month. Half-open `[start, start + 1 month)` so a batch
        // at midnight on the first of the next month belongs to that month and
        // to exactly one period.
        $windowStart = CarbonImmutable::parse($period.'-01 00:00:00')->startOfMonth();
        $windowEnd = $windowStart->addMonth();

        $journalTotals = $this->journalTotalsFor($entityRef, $windowStart, $windowEnd);
        $findings = $this->findings($journalTotals, $statement);

        return DB::transaction(function () use (
            $period,
            $entityRef,
            $statement,
            $journalTotals,
            $findings,
            $correlationId,
            $ranAt,
        ): Reconciliation {
            $reconciliationId = $this->upsertReconciliation(
                $period,
                $entityRef,
                $statement,
                $this->journalTotalMinor($journalTotals),
                $correlationId,
                $ranAt,
            );

            $this->recordFindings($reconciliationId, $period, $entityRef, $findings, $correlationId, $ranAt);

            $this->settleStatus($reconciliationId, $period, $entityRef, $statement, $ranAt);

            return Reconciliation::query()->findOrFail($reconciliationId);
        });
    }

    private function assertStatementCovers(ProviderStatement $statement, string $period, string $entityRef): void
    {
        if (trim($statement->period) !== $period) {
            throw InvalidReconciliationException::forStatementPeriodMismatch($period, trim($statement->period));
        }

        if (trim((string) $statement->entityRef) !== $entityRef) {
            throw InvalidReconciliationException::forStatementEntityMismatch(
                $entityRef,
                trim((string) $statement->entityRef),
            );
        }
    }

    /**
     * A pure READ of the ledger. Grouped in SQL rather than hydrated into
     * models so a large period does not load every entry row into memory.
     *
     * @return array<string, array{debit: int, credit: int}>
     */
    private function journalTotalsFor(string $entityRef, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $rows = DB::table('journal_batches as b')
            ->leftJoin('journal_entries as e', 'e.batch_id', '=', 'b.id')
            ->where('b.entity_ref', $entityRef)
            ->where('b.occurred_at', '>=', $start)
            ->where('b.occurred_at', '<', $end)
            ->groupBy('b.id', 'b.business_key')
            ->selectRaw('b.business_key as business_key')
            ->selectRaw("COALESCE(SUM(CASE WHEN e.direction = 'DR' THEN e.amount_minor ELSE 0 END), 0) as debit_minor")
            ->selectRaw("COALESCE(SUM(CASE WHEN e.direction = 'CR' THEN e.amount_minor ELSE 0 END), 0) as credit_minor")
            ->orderBy('b.business_key')
            ->get();

        $totals = [];

        foreach ($rows as $row) {
            $totals[(string) $row->business_key] = [
                'debit' => (int) $row->debit_minor,
                'credit' => (int) $row->credit_minor,
            ];
        }

        return $totals;
    }

    /**
     * The debit-side total of every batch in the window. A balanced batch has
     * the same credit total, matching `Models\JournalBatch::total()`.
     *
     * @param  array<string, array{debit: int, credit: int}>  $journalTotals
     */
    private function journalTotalMinor(array $journalTotals): int
    {
        return array_sum(array_column($journalTotals, 'debit'));
    }

    /**
     * Compare, in memory, and produce findings. Deliberately pure: it writes
     * nothing, and in particular it cannot write the ledger.
     *
     * @param  array<string, array{debit: int, credit: int}>  $journalTotals
     * @return list<array{type: string, subject_ref: string, journal_amount_minor: int|null, statement_amount_minor: int|null}>
     */
    private function findings(array $journalTotals, ?ProviderStatement $statement): array
    {
        // No statement means there is nothing to compare against. NOT a
        // comparison against zero — see the class doc block.
        if ($statement === null) {
            return [];
        }

        $findings = [];
        $statementLines = $statement->lines();
        $comparable = [];

        foreach ($journalTotals as $businessKey => $totals) {
            if ($totals['debit'] !== $totals['credit']) {
                // An unbalanced batch has no well-defined total, so it is
                // reported as its own finding and excluded from the amount
                // comparison rather than producing a second, derivative one.
                $findings[] = [
                    'type' => ReconciliationExceptionType::UNBALANCED,
                    'subject_ref' => $businessKey,
                    'journal_amount_minor' => $totals['debit'],
                    'statement_amount_minor' => null,
                ];

                continue;
            }

            $comparable[$businessKey] = $totals['debit'];
        }

        foreach ($comparable as $businessKey => $journalAmountMinor) {
            if (! array_key_exists($businessKey, $statementLines)) {
                $findings[] = [
                    'type' => ReconciliationExceptionType::EXTRA,
                    'subject_ref' => $businessKey,
                    'journal_amount_minor' => $journalAmountMinor,
                    'statement_amount_minor' => null,
                ];

                continue;
            }

            if ($statementLines[$businessKey] !== $journalAmountMinor) {
                $findings[] = [
                    'type' => ReconciliationExceptionType::AMOUNT_MISMATCH,
                    'subject_ref' => $businessKey,
                    'journal_amount_minor' => $journalAmountMinor,
                    'statement_amount_minor' => $statementLines[$businessKey],
                ];
            }
        }

        foreach ($statementLines as $lineReference => $statementAmountMinor) {
            if (array_key_exists($lineReference, $journalTotals)) {
                continue;
            }

            $findings[] = [
                'type' => ReconciliationExceptionType::MISSING,
                'subject_ref' => (string) $lineReference,
                'journal_amount_minor' => null,
                'statement_amount_minor' => $statementAmountMinor,
            ];
        }

        return $findings;
    }

    /**
     * One conclusion per (entity, period). The UNIQUE index is the authority;
     * this lookup is only how a second run produces a sensible result rather
     * than a raw constraint violation — the same relationship
     * `Actions\VendorPayable::assess()` has with its own UNIQUE index.
     *
     * The status written here is provisional: `settleStatus()` derives the
     * final one after the findings are persisted, because it depends on how
     * many exceptions are actually still open (including ones an earlier run
     * found and a human has since decided).
     */
    private function upsertReconciliation(
        string $period,
        string $entityRef,
        ?ProviderStatement $statement,
        int $journalTotalMinor,
        ?string $correlationId,
        CarbonImmutable $ranAt,
    ): string {
        $existingId = DB::table('reconciliations')
            ->where('entity_ref', $entityRef)
            ->where('period', $period)
            ->lockForUpdate()
            ->value('id');

        $attributes = [
            'status' => $statement === null
                ? ReconciliationStatus::STATEMENT_MISSING
                : ReconciliationStatus::MATCHED,
            'statement_reference' => $statement?->reference,
            'statement_total_minor' => $statement?->totalMinor(),
            'journal_total_minor' => $journalTotalMinor,
            'correlation_id' => $correlationId,
            'ran_at' => $ranAt,
            'updated_at' => $ranAt,
        ];

        if ($existingId !== null) {
            DB::table('reconciliations')->where('id', $existingId)->update($attributes);

            return (string) $existingId;
        }

        $id = (string) Str::uuid();

        DB::table('reconciliations')->insert($attributes + [
            'id' => $id,
            'entity_ref' => $entityRef,
            'period' => $period,
            'created_at' => $ranAt,
        ]);

        return $id;
    }

    /**
     * `insertOrIgnore` against the natural-key UNIQUE index. A finding that is
     * already on file — open OR resolved — is left exactly as it is.
     *
     * @param  list<array{type: string, subject_ref: string, journal_amount_minor: int|null, statement_amount_minor: int|null}>  $findings
     */
    private function recordFindings(
        string $reconciliationId,
        string $period,
        string $entityRef,
        array $findings,
        ?string $correlationId,
        CarbonImmutable $ranAt,
    ): void {
        if ($findings === []) {
            return;
        }

        $rows = [];

        foreach ($findings as $finding) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'reconciliation_id' => $reconciliationId,
                'entity_ref' => $entityRef,
                'period' => $period,
                'type' => $finding['type'],
                'subject_ref' => $finding['subject_ref'],
                'journal_amount_minor' => $finding['journal_amount_minor'],
                'statement_amount_minor' => $finding['statement_amount_minor'],
                'status' => ReconciliationExceptionStatus::OPEN,
                'decision' => null,
                'decided_by' => null,
                'decided_at' => null,
                'correlation_id' => $correlationId,
                'created_at' => $ranAt,
                'updated_at' => $ranAt,
            ];
        }

        DB::table('reconciliation_exceptions')->insertOrIgnore($rows);
    }

    /**
     * The final status, derived from persisted rows rather than from what this
     * run happened to find. A period with an exception an earlier run found and
     * nobody has decided yet is still `exceptions_open`, even if today's
     * comparison came out clean — the finding has not gone away, it is waiting
     * on a human.
     */
    private function settleStatus(
        string $reconciliationId,
        string $period,
        string $entityRef,
        ?ProviderStatement $statement,
        CarbonImmutable $ranAt,
    ): void {
        if ($statement === null) {
            return;
        }

        $openCount = DB::table('reconciliation_exceptions')
            ->where('entity_ref', $entityRef)
            ->where('period', $period)
            ->where('status', ReconciliationExceptionStatus::OPEN)
            ->count();

        DB::table('reconciliations')->where('id', $reconciliationId)->update([
            'status' => $openCount > 0
                ? ReconciliationStatus::EXCEPTIONS_OPEN
                : ReconciliationStatus::MATCHED,
            'updated_at' => $ranAt,
        ]);
    }
}

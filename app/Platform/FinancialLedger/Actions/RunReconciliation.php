<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Actions;

use App\Platform\Correlation\CorrelationContext;
use App\Platform\FinancialLedger\Exceptions\InvalidReconciliationException;
use App\Platform\FinancialLedger\LedgerPeriod;
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
 *  - `reconciliation_exceptions (entity_ref, period, type, subject_ref,
 *    version)` — one observation per finding version, with the natural key
 *    stated in natural terms rather than on a surrogate id.
 *
 * Reconciliation locks the parent before locking a current finding. An open
 * finding is updated with changed evidence; a resolved one is retained and a
 * new version is inserted. A second run therefore never reopens a resolved
 * row, overwrites its decision, or discards the statement amount/reference it
 * superseded.
 *
 * A changed open finding keeps its row because it has no historical decision to
 * preserve. A changed resolved finding gets a new version linked to the old
 * row, so the original decision remains evidence rather than silently changing.
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

        if (! LedgerPeriod::matches($period)) {
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

        // Safe to build: the period matched `LedgerPeriod::matches()` above, so
        // it is a real `YYYY-MM` month.
        //
        // The window comes from `LedgerPeriod`, the module's single definition
        // of which batches belong to a period. This Action used to build it
        // here with `CarbonImmutable::parse()` in the app timezone while
        // `LedgerReport` built the same window in `Asia/Jakarta` — the two
        // agreed only because the persistence layer formats a
        // `DateTimeInterface` without converting zones. Sharing one definition
        // removes a seven-hour disagreement that a single `->utc()` or a date
        // cast would otherwise have introduced silently between a report and
        // the reconciliation of the same period.
        [$windowStart, $windowEnd] = LedgerPeriod::boundsFor($period);

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

            $this->recordFindings(
                reconciliationId: $reconciliationId,
                period: $period,
                entityRef: $entityRef,
                findings: $findings,
                statementReference: $statement?->reference,
                correlationId: $correlationId,
                ranAt: $ranAt,
            );

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
     * One conclusion per (entity, period). The database upsert is the authority
     * and serializes concurrent first runs on the natural key; a unique
     * collision cannot abort a valid run after it has found exceptions.
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

        $id = (string) Str::uuid();

        DB::table('reconciliations')->upsert(
            [$attributes + [
                'id' => $id,
                'entity_ref' => $entityRef,
                'period' => $period,
                'created_at' => $ranAt,
            ]],
            ['entity_ref', 'period'],
            array_keys($attributes),
        );

        return (string) DB::table('reconciliations')
            ->where('entity_ref', $entityRef)
            ->where('period', $period)
            ->value('id');
    }

    /**
     * Preserve changed evidence without silently rewriting a decision. An open
     * current finding may receive its latest amount/reference; a resolved
     * current finding gets a new version linked to the historical row.
     *
     * @param  list<array{type: string, subject_ref: string, journal_amount_minor: int|null, statement_amount_minor: int|null}>  $findings
     */
    private function recordFindings(
        string $reconciliationId,
        string $period,
        string $entityRef,
        array $findings,
        ?string $statementReference,
        ?string $correlationId,
        CarbonImmutable $ranAt,
    ): void {
        if ($findings === []) {
            return;
        }

        foreach ($findings as $finding) {
            $current = DB::table('reconciliation_exceptions')
                ->where('entity_ref', $entityRef)
                ->where('period', $period)
                ->where('type', $finding['type'])
                ->where('subject_ref', $finding['subject_ref'])
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();

            if ($current !== null && $this->findingMatches($current, $finding, $statementReference)) {
                continue;
            }

            $evidence = [
                'journal_amount_minor' => $finding['journal_amount_minor'],
                'statement_amount_minor' => $finding['statement_amount_minor'],
                'statement_reference' => $statementReference,
                'correlation_id' => $correlationId,
                'updated_at' => $ranAt,
            ];

            if ($current !== null && $current->status === ReconciliationExceptionStatus::OPEN) {
                DB::table('reconciliation_exceptions')
                    ->where('id', $current->id)
                    ->update($evidence);

                continue;
            }

            DB::table('reconciliation_exceptions')->insert([
                'id' => (string) Str::uuid(),
                'reconciliation_id' => $reconciliationId,
                'entity_ref' => $entityRef,
                'period' => $period,
                'type' => $finding['type'],
                'subject_ref' => $finding['subject_ref'],
                'statement_reference' => $statementReference,
                'version' => $current === null ? 1 : ((int) $current->version + 1),
                'supersedes_id' => $current?->id,
                'journal_amount_minor' => $finding['journal_amount_minor'],
                'statement_amount_minor' => $finding['statement_amount_minor'],
                'status' => ReconciliationExceptionStatus::OPEN,
                'decision' => null,
                'decided_by' => null,
                'decided_at' => null,
                'correlation_id' => $correlationId,
                'created_at' => $ranAt,
                'updated_at' => $ranAt,
            ]);
        }
    }

    /**
     * @param  array{type: string, subject_ref: string, journal_amount_minor: int|null, statement_amount_minor: int|null}  $finding
     */
    private function findingMatches(object $current, array $finding, ?string $statementReference): bool
    {
        return $this->sameAmount($current->journal_amount_minor, $finding['journal_amount_minor'])
            && $this->sameAmount($current->statement_amount_minor, $finding['statement_amount_minor'])
            && $current->statement_reference === $statementReference;
    }

    /**
     * Null is "no amount recorded on this side", which is not the same fact as
     * zero. A blanket `(int)` cast on both sides collapsed the two, so a
     * finding that genuinely changed from "the statement had no line at all"
     * to "the statement had a line for 0" compared equal and was skipped as
     * unchanged — losing the evidence version this method exists to preserve.
     */
    private function sameAmount(mixed $current, mixed $incoming): bool
    {
        if ($current === null || $incoming === null) {
            return $current === null && $incoming === null;
        }

        return (int) $current === (int) $incoming;
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

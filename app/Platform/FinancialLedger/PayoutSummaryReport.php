<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use App\Platform\FinancialLedger\Exceptions\InvalidLedgerReportException;
use App\Platform\FinancialLedger\Models\Payout;
use Carbon\CarbonImmutable;

/**
 * The "outgoing payments" report AC7 asks for: money that actually left the
 * business to a vendor in a period.
 *
 * ---------------------------------------------------------------------------
 * Why `payouts`, not a journal-entries filter
 * ---------------------------------------------------------------------------
 * `CashReceiptsReport` derives receipts from the journal because no table
 * dedicated to "money received" exists. An outgoing payment is different:
 * `Models\Payout` (`payouts` table) IS the dedicated record — "the record
 * that money left the business to a vendor: the amount, the proof reference,
 * the approver, and the journal batch that recorded the movement" (that
 * model's own doc block). Every payout also posts a CR leg on
 * `ChartOfAccounts::CASH_BANK_ACCOUNT` through its `journal_business_key`, so
 * this report still reconciles to the journal — it reads the workflow record
 * that carries the entity/vendor/method/proof context a bare journal leg
 * does not, rather than re-deriving that context from the ledger a second
 * way.
 *
 * `entity_ref` lives directly on `payouts` (frozen at payout time — see the
 * migration's own doc block), so this report scopes by it exactly the way
 * `LedgerReport` scopes by `journal_batches.entity_ref`.
 */
final class PayoutSummaryReport
{
    /**
     * @param  string|list<string>|null  $entityRef
     *
     * @throws InvalidLedgerReportException on a malformed period or an empty
     *                                      entity-reference list.
     */
    public function summary(string $period, string|array|null $entityRef = null): PayoutSummaryReportResult
    {
        $period = trim($period);
        $this->assertPeriod($period);

        if (is_array($entityRef) && $entityRef === []) {
            throw InvalidLedgerReportException::forEmptyEntityScope();
        }

        $entityRefs = match (true) {
            $entityRef === null => null,
            is_array($entityRef) => array_values($entityRef),
            default => [$entityRef],
        };

        [$start, $endExclusive] = LedgerPeriod::boundsFor($period);

        $rows = Payout::query()
            ->where('occurred_at', '>=', $start)
            ->where('occurred_at', '<', $endExclusive)
            ->when($entityRefs !== null, static fn ($query) => $query->whereIn('entity_ref', $entityRefs))
            ->orderBy('occurred_at')
            ->get(['id', 'vendor_id', 'entity_ref', 'amount_minor', 'method', 'state', 'occurred_at'])
            ->map(static fn (Payout $payout): array => [
                'id' => (string) $payout->id,
                'vendor_id' => (string) $payout->vendor_id,
                'entity_ref' => (string) $payout->entity_ref,
                'amount_minor' => (int) $payout->amount_minor,
                'method' => (string) $payout->method,
                'state' => (string) $payout->state,
                'occurred_at' => $payout->occurred_at->toISOString(),
            ])
            ->all();

        $rows = $this->sortRowsDeterministically($rows);

        return new PayoutSummaryReportResult(
            period: $period,
            entityRef: $entityRef,
            generatedAt: CarbonImmutable::now(),
            rows: $rows,
            totalMinor: array_sum(array_column($rows, 'amount_minor')),
        );
    }

    /**
     * @throws InvalidLedgerReportException on a malformed period.
     */
    public function assertPeriod(string $period): void
    {
        if (! LedgerPeriod::matches($period)) {
            throw InvalidLedgerReportException::forMalformedPeriod($period);
        }
    }

    /**
     * PHP owns the output order, not the SQL `ORDER BY` — same reasoning as
     * `LedgerReport::sortRowsByAccountCode()` and
     * `CashReceiptsReport::sortRowsDeterministically()`.
     *
     * @param  list<array{id: string, vendor_id: string, entity_ref: string, amount_minor: int, method: string, state: string, occurred_at: string}>  $rows
     * @return list<array{id: string, vendor_id: string, entity_ref: string, amount_minor: int, method: string, state: string, occurred_at: string}>
     */
    private function sortRowsDeterministically(array $rows): array
    {
        usort(
            $rows,
            static fn (array $a, array $b): int => strcmp($a['occurred_at'], $b['occurred_at'])
                ?: strcmp($a['id'], $b['id']),
        );

        return $rows;
    }
}

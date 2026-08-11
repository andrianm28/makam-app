<?php

declare(strict_types=1);

namespace Tests\Contract\Support;

use App\Platform\FinancialLedger\Contracts\Journal;
use App\Platform\FinancialLedger\Models\JournalBatch;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

if (! class_exists(JournalBatch::class)) {
    class_alias(\stdClass::class, JournalBatch::class);
}

/**
 * Test-local seam consumer. It models only the contract guarantees that L3
 * can exercise before L4's concrete journal implementation is merged.
 */
final class LedgerSeamStub implements Journal
{
    /** @var array<string, true> */
    private array $businessKeys = [];

    /** @var list<int> */
    private array $transactionLevels = [];

    /**
     * @param  list<array{account: string, direction: string, amountMinor: int, reference?: string|null}>  $entries
     */
    public function post(
        string $businessKey,
        int|string $entityRef,
        string $sourceType,
        int|string $sourceId,
        array $entries,
        ?string $correlationId = null,
        ?string $occurredAt = null,
    ): JournalBatch {
        $debits = 0;
        $credits = 0;

        foreach ($entries as $entry) {
            if ($entry['amountMinor'] < 0) {
                throw new InvalidArgumentException('Journal amounts must be non-negative.');
            }

            if ($entry['direction'] === 'DR') {
                $debits += $entry['amountMinor'];
            } elseif ($entry['direction'] === 'CR') {
                $credits += $entry['amountMinor'];
            }
        }

        if ($debits !== $credits) {
            throw new InvalidArgumentException('Journal entries must balance.');
        }

        if (isset($this->businessKeys[$businessKey])) {
            throw new InvalidArgumentException('The business key must be unique.');
        }

        $this->businessKeys[$businessKey] = true;
        $this->transactionLevels[] = DB::transactionLevel();

        return new JournalBatch;
    }

    /**
     * The broad test-only kind parameter keeps this stub independent of L4's
     * enum while remaining compatible with the canonical interface.
     */
    public function postReversal(
        string $originalBusinessKey,
        string $reason,
        mixed $kind = null,
        ?string $correlationId = null,
        ?string $occurredAt = null,
    ): JournalBatch {
        throw new InvalidArgumentException('Reversal behavior is owned by L4.');
    }

    /** @return list<int> */
    public function observedTransactionLevels(): array
    {
        return $this->transactionLevels;
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use App\Platform\FinancialLedger\Contracts\Journal as JournalContract;
use App\Platform\FinancialLedger\Exceptions\InvalidReconciliationException;
use App\Platform\FinancialLedger\Models\JournalBatch;

/**
 * The corrective posting that accompanies a `post_correction` decision — a NEW
 * batch, described up front, posted through `Contracts\Journal` and through
 * nothing else.
 *
 * ---------------------------------------------------------------------------
 * Why this is a value object and not a callback
 * ---------------------------------------------------------------------------
 * `Actions\ResolveException` has to run the correction inside its own
 * transaction, so the resolution and the correction commit or roll back as one.
 * The obvious way to express that is "hand the Action a closure and let it call
 * you back" — and it is the wrong way, because a closure can do ANYTHING,
 * including `DB::table('journal_entries')->update(...)`. The whole point of
 * this task is that the ledger is written through one seam. So the caller
 * describes the correction declaratively and this class is the only thing that
 * turns a description into a journal write.
 *
 * ---------------------------------------------------------------------------
 * Two shapes, because AC10 names two
 * ---------------------------------------------------------------------------
 *  - `reversalOf()` — the difference exists because a batch should not have
 *    been posted. Delegates to `Journal::postReversal()`, which flips every
 *    entry of the original into a new batch linked by `reverses_batch_id` and
 *    writes its own `JOURNAL_REVERSAL` audit event. Note that `postReversal()`
 *    opens a transaction of its OWN (this changed in Task 4, and differs from
 *    `post()` deliberately); called from inside `ResolveException`'s
 *    transaction that becomes a savepoint, so the outer rollback still undoes
 *    it — but no code here may assume it is transaction-free.
 *  - `adjustment()` — the difference exists because something was never posted,
 *    or posted at the wrong value. Delegates to `Journal::post()`, which opens
 *    NO transaction of its own by design and therefore inherits
 *    `ResolveException`'s.
 *
 * Neither shape can express an edit. There is no third constructor, and there
 * is no field on this class that names an existing row to change.
 *
 * `$sourceType` for an adjustment must be a member of `journal_batches`'
 * source-type closed list, which Task 2's PostgreSQL CHECK owns and this class
 * deliberately does not restate (`AGENTS.md` §Documentation forbids duplicating
 * canonical data). An unknown value is refused by that CHECK, not by a rival
 * list here.
 */
final readonly class ReconciliationCorrection
{
    private const string KIND_REVERSAL = 'reversal';

    private const string KIND_ADJUSTMENT = 'adjustment';

    /**
     * @param  list<array{account: string, direction: string, amountMinor: int, reference?: string|null}>  $entries
     */
    private function __construct(
        private string $kind,
        private ?string $originalBusinessKey = null,
        private JournalReversalKind $reversalKind = JournalReversalKind::Reversal,
        private ?string $businessKey = null,
        private ?string $sourceType = null,
        private ?string $sourceId = null,
        private array $entries = [],
        private ?string $entityRef = null,
    ) {}

    /**
     * Reverse a batch that should not have been posted.
     *
     * @throws InvalidReconciliationException on a blank business key.
     */
    public static function reversalOf(
        string $originalBusinessKey,
        JournalReversalKind $kind = JournalReversalKind::Reversal,
    ): self {
        if (trim($originalBusinessKey) === '') {
            throw InvalidReconciliationException::forBlankCorrectionBusinessKey();
        }

        return new self(
            kind: self::KIND_REVERSAL,
            originalBusinessKey: trim($originalBusinessKey),
            reversalKind: $kind,
        );
    }

    /**
     * Post a new adjusting batch.
     *
     * @param  list<array{account: string, direction: string, amountMinor: int, reference?: string|null}>  $entries
     *                                                                                                               Validated by `Journal::post()` itself — account codes and integer minor
     *                                                                                                               units only, never customer PII.
     *
     * @throws InvalidReconciliationException on a blank business key or an
     *                                        empty entry list.
     */
    public static function adjustment(
        string $businessKey,
        int|string $entityRef,
        string $sourceType,
        int|string $sourceId,
        array $entries,
    ): self {
        if (trim($businessKey) === '') {
            throw InvalidReconciliationException::forBlankCorrectionBusinessKey();
        }

        if ($entries === []) {
            throw InvalidReconciliationException::forEmptyCorrectionEntries($businessKey);
        }

        return new self(
            kind: self::KIND_ADJUSTMENT,
            businessKey: trim($businessKey),
            sourceType: $sourceType,
            sourceId: (string) $sourceId,
            entries: $entries,
            entityRef: (string) $entityRef,
        );
    }

    /**
     * Post this correction. Called ONLY from inside
     * `Actions\ResolveException`'s transaction, so the correction and the
     * exception resolution commit or roll back together.
     *
     * @param  string  $reason  The decider's recorded reason, carried onto the
     *                          reversing entries' own note column. Free of restricted data, the same
     *                          discipline `Audit::record()`'s `$reason` carries.
     */
    public function post(JournalContract $journal, string $reason, ?string $correlationId = null): JournalBatch
    {
        if ($this->kind === self::KIND_REVERSAL) {
            return $journal->postReversal(
                originalBusinessKey: (string) $this->originalBusinessKey,
                reason: $reason,
                kind: $this->reversalKind,
                correlationId: $correlationId,
            );
        }

        return $journal->post(
            businessKey: (string) $this->businessKey,
            entityRef: (string) $this->entityRef,
            sourceType: (string) $this->sourceType,
            sourceId: (string) $this->sourceId,
            entries: $this->entries,
            correlationId: $correlationId,
        );
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Contracts;

use App\Platform\FinancialLedger\Exceptions\InvalidJournalBatchException;
use App\Platform\FinancialLedger\Exceptions\JournalBatchAlreadyReversedException;
use App\Platform\FinancialLedger\Exceptions\UnknownJournalBatchException;
use App\Platform\FinancialLedger\JournalReversalKind;
use App\Platform\FinancialLedger\Models\JournalBatch;

/**
 * The write seam for `journal_batches`/`journal_entries` — co-owned with
 * `lane/l3-payment-adapter`, which consumes this interface while this lane
 * implements it (plan §File Structure, Task 7).
 *
 * ---------------------------------------------------------------------------
 * What an implementation of this interface must guarantee
 * ---------------------------------------------------------------------------
 * These are contract terms, not implementation notes — a second
 * implementation (a fake in a consumer's test, say) is only faithful if it
 * keeps them:
 *
 *  1. **It opens no transaction of its own (AC3).** The caller posts from
 *     inside its own `DB::transaction()`, alongside the state change that
 *     caused the money movement. Identical discipline to
 *     `App\Platform\Outbox\Outbox::record()` and
 *     `App\Platform\Audit\Audit::record()`.
 *  2. **A colliding `business_key` posts nothing (AC5).** The insert throws,
 *     the caller's transaction rolls back, and the redelivered event does not
 *     double-post. It is never caught, never upserted, never ignored.
 *  3. **Nothing already written is ever edited (AC2, AC14).** Corrections are
 *     new reversing batches. No `UPDATE`, no `DELETE`, no `save()` on an
 *     existing batch or entry — Task 6 revokes both from the application role
 *     outright.
 *  4. **The database is the balance authority (AC1).** The PostgreSQL
 *     `assert_balanced_batch` constraint trigger decides whether a batch
 *     balances. An implementation may validate shape for a better error
 *     message, but must not present itself as the balance gate.
 *
 * ---------------------------------------------------------------------------
 * What it does NOT guarantee
 * ---------------------------------------------------------------------------
 * Term 1 is a discipline this interface enables and cannot enforce. Called
 * outside any transaction, `post()` still writes — a lone batch insert
 * followed by a lone entries insert is not atomic with anything else, and is
 * not even atomic with itself. The pairing guarantee is the caller's
 * transaction, nothing here. Stated plainly rather than implied, matching
 * `Outbox`'s own doc block.
 */
interface Journal
{
    /**
     * Post one balanced batch and all of its entries.
     *
     * @param  string  $businessKey  Source-prefixed and UNIQUE — the
     *                               idempotency key for at-least-once delivery (AC5). Documented
     *                               formats: `payment:{provider_event_id}`,
     *                               `renewal:{grave_record_id}:{period}`, `manual_verify:{verification_id}`.
     * @param  int|string  $entityRef  The `badan usaha` the money belongs to.
     * @param  string  $sourceType  One of `journal_batches`' closed list
     *                              (Task 2's PostgreSQL CHECK owns that list; it is not restated here).
     * @param  int|string  $sourceId  The source record's own identifier.
     * @param  list<array{account: string, direction: string, amountMinor: int, reference?: string|null}>  $entries
     *                                                                                                               Account codes and integer minor units only — never customer PII
     *                                                                                                               (`AGENTS.md` §Observability). `amountMinor` is non-negative; the sign
     *                                                                                                               lives in `direction`, never in the amount.
     * @param  string|null  $correlationId  Falls back to the request's/job's
     *                                      `CorrelationContext` when null — a money row with no trace id is a
     *                                      traceability gap (`AGENTS.md` §Observability).
     * @param  string|null  $occurredAt  When the money moved, defaulting to
     *                                   now. Not the same as when the row was written.
     *
     * @throws InvalidJournalBatchException on a malformed business key,
     *                                      a blank `$entityRef`, an empty entry list, an unknown account code,
     *                                      a direction that is not `DR`/`CR`, or a negative/non-integer amount.
     *
     * ---------------------------------------------------------------------
     * CONSUMERS OF THIS CONTRACT, READ THIS: the blank-`$entityRef` refusal is
     * newer than the stub
     * ---------------------------------------------------------------------
     * `Journal::post()` began refusing a blank (or whitespace-only)
     * `$entityRef` in Task 9b. `entity_ref` is `NOT NULL`, but `''` satisfies
     * `NOT NULL` and no CHECK rejects it, so a batch scoped to no badan usaha
     * was silently accepted — invisible to every entity-scoped report and
     * export while still counting toward an unscoped total, with the two views
     * disagreeing permanently and no error anywhere.
     *
     * `tests/Contract/Support/LedgerSeamStub.php` does NOT enforce this, so the
     * stub and the real implementation now diverge on it. That divergence is
     * recorded deliberately rather than fixed here: the stub is
     * `lane/l3-payment-adapter`'s artifact, copied into this repository
     * byte-for-byte, and editing another lane's file unilaterally is how the
     * two copies stop being the same file. It belongs with merge sign-off
     * bundle item 17 (E1), which already records the stub teaching a false
     * EXCEPTION MODEL for two other contract terms — same file, same class of
     * problem, and it should be resolved once, across both lanes, rather than
     * twice in one of them.
     *
     * No L3 caller passes a blank `$entityRef` today (checked), so this is a
     * latent contract divergence, not a live break.
     */
    public function post(
        string $businessKey,
        int|string $entityRef,
        string $sourceType,
        int|string $sourceId,
        array $entries,
        ?string $correlationId = null,
        ?string $occurredAt = null,
    ): JournalBatch;

    /**
     * Post a NEW batch that reverses every entry of an existing one:
     * same accounts, same amounts, direction flipped, linked back through
     * `reverses_batch_id`. The original batch and its entries are never
     * touched (AC2, AC14).
     *
     * @param  string  $reason  Required, non-blank, and written to the
     *                          reversing entries' own `reference` note column — a correction with
     *                          no stated cause is unauditable. Must be free of restricted data,
     *                          the same discipline `Audit::record()`'s `$reason` carries.
     * @param  JournalReversalKind  $kind  Selects both the business-key prefix
     *                                     and the new batch's `source_type`, which is why they cannot drift
     *                                     apart.
     *
     * @throws UnknownJournalBatchException when no batch has that business key.
     * @throws JournalBatchAlreadyReversedException when the batch already has
     *                                              a reversal — see the implementation's doc block for why a second
     *                                              one is refused rather than allowed.
     * @throws InvalidJournalBatchException on a blank reason.
     */
    public function postReversal(
        string $originalBusinessKey,
        string $reason,
        JournalReversalKind $kind = JournalReversalKind::Reversal,
        ?string $correlationId = null,
        ?string $occurredAt = null,
    ): JournalBatch;
}

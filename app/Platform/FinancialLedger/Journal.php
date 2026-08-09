<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\FinancialLedger\Actions\PostJournalBatch;
use App\Platform\FinancialLedger\Contracts\Journal as JournalContract;
use App\Platform\FinancialLedger\Exceptions\InvalidJournalBatchException;
use App\Platform\FinancialLedger\Exceptions\JournalBatchAlreadyReversedException;
use App\Platform\FinancialLedger\Exceptions\UnknownJournalBatchException;
use App\Platform\FinancialLedger\Models\JournalBatch;
use App\Platform\FinancialLedger\Models\JournalEntry;
use App\Platform\IdentityAccess\ActorContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONE write API for `journal_batches`/`journal_entries` — the same role
 * `App\Platform\Audit\Audit` plays for `audit_events` and
 * `App\Platform\Outbox\Outbox` plays for `outbox_events`. Nothing else in this
 * codebase inserts a journal row.
 *
 * ---------------------------------------------------------------------------
 * `post()` does NOT open its own transaction — identical discipline to
 * `Outbox::record()` and `Audit::record()`
 * ---------------------------------------------------------------------------
 * Call `post()` from INSIDE an existing `DB::transaction()` that also performs
 * the state change the money movement belongs to (AC3). Called on its own,
 * outside any transaction, it still writes — but nothing pairs it with the
 * state change, and it is not even internally atomic: the batch row and the
 * entry rows are two statements (see `PostJournalBatch`'s doc block). The
 * pairing guarantee is the caller's transaction, not anything here. That limit
 * is stated rather than implied, following `Outbox`'s own doc block.
 *
 * `postReversal()` is the exception: its journal batch and sensitive audit
 * event are always wrapped in one transaction inside this API, even when the
 * caller has no surrounding state mutation to provide that boundary.
 *
 * There is no `wrap()` counterpart. `Audit::wrap()` earns its existence
 * because every audited mutation has the identical "run this closure, then
 * write one audit row" shape; a journal write's shape does not repeat that way
 * (business key, entity, source, and entry list differ every time, and one
 * state change may post more than one batch), so a wrapper here would add a
 * second API surface for no shared benefit yet.
 *
 * ---------------------------------------------------------------------------
 * The database is the balance authority (AC1), not this class
 * ---------------------------------------------------------------------------
 * PostgreSQL's `assert_balanced_batch` constraint trigger (Task 2) decides
 * whether a batch balances. `post()` deliberately performs NO debit/credit
 * sum: re-implementing it here would create a second, weaker gate that
 * disagrees with the real one on SQLite and drifts from it over time. What
 * `post()` does check is shape it can describe better than a `23514` can —
 * unknown account codes, a direction that is not `DR`/`CR`, a negative or
 * non-integer amount, an unprefixed business key, and an entry list that is
 * empty (the one check the trigger genuinely cannot make, since a batch with
 * no entry rows fires no row trigger at all).
 *
 * ---------------------------------------------------------------------------
 * Not bound in a service provider yet
 * ---------------------------------------------------------------------------
 * The plan gives `Contracts\Journal` -> `Journal` binding to a
 * `FinancialLedgerServiceProvider` registered in `bootstrap/providers.php`,
 * and gives Task 7 the cross-lane seam checkpoint with
 * `lane/l3-payment-adapter`. That registration is not made here: this task's
 * brief does not authorize it, `bootstrap/providers.php` is a shared file, and
 * four sibling lanes are in flight in their own worktrees right now. Until
 * Task 7 adds it, `app(Contracts\Journal::class)` will not resolve —
 * `app(Journal::class)` on the concrete class does, because every dependency
 * autowires. Flagged as a deliberate deferral, not an oversight.
 */
final class Journal implements JournalContract
{
    /**
     * `journal_batches.status` on insert, and forever after. Ruling 1 (Wave
     * 1b, user-approved): reversed-ness is derived from the existence of a
     * reversing batch, so this value is written once and never rewritten. Task
     * 6 revokes UPDATE on this table from the application role outright.
     */
    private const string POSTED_STATUS = 'posted';

    public function __construct(
        private readonly PostJournalBatch $postJournalBatch = new PostJournalBatch,
    ) {}

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
        $this->assertSourcePrefixed($businessKey);

        $batchId = (string) Str::uuid();
        $entryRows = $this->buildEntryRows($businessKey, $batchId, $entries);

        return ($this->postJournalBatch)(
            [
                'id' => $batchId,
                'business_key' => $businessKey,
                'entity_ref' => (string) $entityRef,
                'source_type' => $sourceType,
                'source_id' => (string) $sourceId,
                'correlation_id' => $this->resolveCorrelationId($correlationId),
                'occurred_at' => $this->resolveOccurredAt($occurredAt),
                'status' => self::POSTED_STATUS,
                'reverses_batch_id' => null,
            ],
            $entryRows,
        );

    }

    /**
     * ---------------------------------------------------------------------
     * Reversing a batch twice is REFUSED — the judgment call this task left
     * open, decided here
     * ---------------------------------------------------------------------
     * A reversal exactly cancels its original: same accounts, same amounts,
     * opposite directions. Applying a second one does not "correct harder", it
     * swings the accounts back past zero by the full original amount and
     * silently invents money that never moved. There is no reading of a
     * double-entry ledger on which two reversals of one batch are the right
     * record of anything, so this is refused rather than allowed.
     *
     * The refusal is enforced in two places, on purpose, and they are not the
     * same strength:
     *
     *  - The UNIQUE index on `journal_batches.reverses_batch_id` (this task's
     *    migration) is the authority. It holds even when this method is
     *    bypassed entirely, and it holds under concurrency.
     *  - The `isReversed()` lookup below is a read-then-insert check, so two
     *    concurrent transactions can both pass it. It exists only to turn a
     *    unique-violation `QueryException` into a message that names both
     *    batches and says what to do instead. Exactly the same relationship
     *    `post()` has with the balance trigger.
     *
     * The `business_key` UNIQUE constraint alone would NOT have been enough:
     * `reversal:{original}` and `refund:{original}` are different keys, so
     * reversing once as a reversal and again as a refund would have collided
     * with nothing. That gap is why the constraint is on the FK column.
     *
     * A batch may of course be corrected again after it is reversed — by
     * posting a new batch that describes the later economic event on its own
     * terms, which is what AC14's forward-only correction model means.
     */
    public function postReversal(
        string $originalBusinessKey,
        string $reason,
        JournalReversalKind $kind = JournalReversalKind::Reversal,
        ?string $correlationId = null,
        ?string $occurredAt = null,
    ): JournalBatch {
        $reason = trim($reason);

        if ($reason === '') {
            throw InvalidJournalBatchException::forMissingReversalReason($originalBusinessKey);
        }

        return DB::transaction(function () use (
            $originalBusinessKey,
            $reason,
            $kind,
            $correlationId,
            $occurredAt,
        ): JournalBatch {
            $original = JournalBatch::query()->where('business_key', $originalBusinessKey)->first();

            if (! $original instanceof JournalBatch) {
                throw UnknownJournalBatchException::forBusinessKey($originalBusinessKey);
            }

            $existingReversal = $original->reversedBy()->first();

            if ($existingReversal instanceof JournalBatch) {
                throw JournalBatchAlreadyReversedException::forBusinessKey(
                    $originalBusinessKey,
                    $existingReversal->business_key,
                );
            }

            $originalEntries = $original->entries()->orderBy('id')->get();

            if ($originalEntries->isEmpty()) {
                throw InvalidJournalBatchException::forEmptyEntries($originalBusinessKey);
            }

            $batchId = (string) Str::uuid();

            // The reason goes in `reference`, the human note column Task 2
            // designed for exactly this. It is NOT the reversal->original link —
            // that is `reverses_batch_id`, a real FK (Ruling 2). Callers are
            // responsible for keeping restricted data out of it, the same
            // discipline `Audit::record()`'s `$reason` carries.
            $entryRows = $originalEntries
                ->map(fn (JournalEntry $entry): array => [
                    'batch_id' => $batchId,
                    'account_code' => $entry->account_code,
                    'direction' => $entry->direction === 'DR' ? 'CR' : 'DR',
                    'amount_minor' => $entry->amount_minor,
                    'reference' => $reason,
                ])
                ->all();

            $reversal = ($this->postJournalBatch)(
                [
                    'id' => $batchId,
                    'business_key' => $kind->businessKeyFor($originalBusinessKey),
                    'entity_ref' => $original->entity_ref,
                    'source_type' => $kind->sourceType(),
                    'source_id' => $original->id,
                    'correlation_id' => $this->resolveCorrelationId($correlationId),
                    // A reversal is its own economic event at its own time. The
                    // original's `occurred_at` is deliberately not copied — doing
                    // so would backdate the correction into a period that has
                    // already been reported on.
                    'occurred_at' => $this->resolveOccurredAt($occurredAt),
                    'status' => self::POSTED_STATUS,
                    'reverses_batch_id' => $original->id,
                ],
                $entryRows,
            );

            $actor = app(ActorContext::class);
            $actorRole = $actor->roles[0] ?? ($actor->isAuthenticated() ? 'unresolved' : 'system');

            Audit::record(
                action: 'JOURNAL_REVERSAL',
                subject: new AuditSubject('journal_batch', $reversal->id),
                outcome: AuditOutcome::Allowed,
                actorRef: $actor->identityReference,
                actorRole: $actorRole,
                source: AuditSource::Panel,
                reason: $reason,
                correlationId: $this->resolveCorrelationId($correlationId),
                metadata: [
                    'reference_number' => $reversal->business_key,
                ],
            );

            return $reversal;
        });
    }

    /**
     * @param  list<array{account: string, direction: string, amountMinor: int, reference?: string|null}>  $entries
     * @return non-empty-list<array{batch_id: string, account_code: string, direction: string, amount_minor: int, reference: string|null}>
     */
    private function buildEntryRows(string $businessKey, string $batchId, array $entries): array
    {
        if ($entries === []) {
            throw InvalidJournalBatchException::forEmptyEntries($businessKey);
        }

        $rows = [];

        foreach (array_values($entries) as $index => $entry) {
            $account = $entry['account'] ?? null;

            if (! is_string($account) || $account === '') {
                throw InvalidJournalBatchException::forMissingAccount($index);
            }

            $direction = $entry['direction'] ?? null;

            if ($direction !== 'DR' && $direction !== 'CR') {
                throw InvalidJournalBatchException::forInvalidDirection($index, $direction);
            }

            $amountMinor = $entry['amountMinor'] ?? null;

            // `is_int` and not a cast: AC11 keeps money in integer minor units
            // end to end, and a weak caller passing a float or a decimal
            // string must be rejected rather than silently truncated. Same
            // reasoning as `Money`'s own constructor check.
            if (! is_int($amountMinor) || $amountMinor < 0) {
                throw InvalidJournalBatchException::forInvalidAmount($index, $amountMinor);
            }

            $reference = $entry['reference'] ?? null;

            $rows[] = [
                'batch_id' => $batchId,
                'account_code' => $account,
                'direction' => $direction,
                'amount_minor' => $amountMinor,
                'reference' => is_string($reference) ? $reference : null,
            ];
        }

        $this->assertAccountsExist($rows);

        return $rows;
    }

    /**
     * The `journal_entries.account_code` foreign key already rejects an
     * unknown account on both drivers. This lookup runs first only so the
     * message names the codes, instead of surfacing a raw FK violation.
     *
     * @param  non-empty-list<array{account_code: string, ...}>  $rows
     */
    private function assertAccountsExist(array $rows): void
    {
        $codes = array_values(array_unique(array_column($rows, 'account_code')));

        /** @var list<string> $known */
        $known = DB::table('coa_accounts')->whereIn('code', $codes)->pluck('code')->all();

        $missing = array_values(array_diff($codes, $known));

        if ($missing !== []) {
            throw InvalidJournalBatchException::forUnknownAccounts($missing);
        }
    }

    /**
     * Task 2's PostgreSQL CHECK (`position(':' in business_key) > 1`) is the
     * authority on this; the check here exists so a malformed key fails with
     * the documented formats in the message, and fails the same way on SQLite.
     */
    private function assertSourcePrefixed(string $businessKey): void
    {
        $separator = strpos($businessKey, ':');

        if ($separator === false || $separator < 1) {
            throw InvalidJournalBatchException::forUnprefixedBusinessKey($businessKey);
        }
    }

    /**
     * `Outbox::record()`'s pattern: read the ambient correlation id directly
     * rather than requiring every caller to thread it. Unlike `Outbox` this
     * seam also accepts an explicit id, because `lane/l3-payment-adapter`
     * posts from a webhook whose correlation id comes off the provider
     * envelope rather than off the current request. An explicit id wins; null
     * falls back to the context. A money row with no trace id is a
     * traceability gap (`AGENTS.md` §Observability).
     */
    private function resolveCorrelationId(?string $correlationId): ?string
    {
        return $correlationId ?? app(CorrelationContext::class)->current()?->value;
    }

    private function resolveOccurredAt(?string $occurredAt): CarbonImmutable
    {
        return $occurredAt === null
            ? CarbonImmutable::now()
            : CarbonImmutable::parse($occurredAt);
    }
}

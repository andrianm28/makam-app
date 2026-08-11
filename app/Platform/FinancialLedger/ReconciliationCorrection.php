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
 * `$sourceType` for an adjustment must be a member of
 * `self::CORRECTABLE_SOURCE_TYPES` — a deliberately NARROWER list than
 * `journal_batches`' own source-type closed list, not a restatement of it.
 * This is not the duplication `AGENTS.md` §Documentation forbids: the schema's
 * list answers "what may exist in the ledger at all", this one answers "what a
 * reconciliation decision may conjure", and they are different questions with
 * different answers. An unknown value is still refused by the PostgreSQL CHECK;
 * a KNOWN value that this seam has no business posting is refused here. See
 * that constant for why each excluded type is excluded.
 */
final readonly class ReconciliationCorrection
{
    private const string KIND_REVERSAL = 'reversal';

    private const string KIND_ADJUSTMENT = 'adjustment';

    /**
     * The source types an ADJUSTMENT may declare. Deliberately far narrower
     * than `journal_batches`' own closed list, and this is the guard that
     * actually closes slice-3 Minor M5.
     *
     * ---------------------------------------------------------------------
     * Why the prefix rule alone did not close M5
     * ---------------------------------------------------------------------
     * The first attempt required the business-key prefix to equal
     * `$sourceType`. That closes a MISMATCH (`manual_verification:x` declaring
     * `payout`) — but M5's own scenario was never a mismatch. It was
     * `sourceType: 'payout'` with key `payout:v-9:x`, where prefix and source
     * type agree perfectly and sail through. Confirmed by execution before
     * writing this: `adjustment()` accepted that exact input and posted a batch
     * the ledger reads as a payout. A guard that is correct for the case it was
     * tested against, and silent on the case that motivated it.
     *
     * ---------------------------------------------------------------------
     * Why `manual_verification` is the whole list
     * ---------------------------------------------------------------------
     * Every other member of `journal_batches_source_type_check` is OWNED by a
     * producer that carries controls a reconciliation correction does not have:
     *
     *  - `payout` — `Actions\ManualPayout`, which requires an authorizer, a
     *    document-vault proof, an approver, recent re-authentication, a
     *    `payouts` row and a `VENDOR_PAYOUT` audit event.
     *  - `vendor_payable` — `Actions\VendorPayable::accrue()`, behind its own
     *    authorization seam and `VENDOR_PAYABLE_ASSESSED` audit.
     *  - `reversal` / `refund` — `Journal::postReversal()`, reached from this
     *    same class via `reversalOf()`. Allowing them HERE would be actively
     *    harmful, not merely redundant: `adjustment()` posts through
     *    `Journal::post()`, which never sets `reverses_batch_id`, so a
     *    "reversal" posted this way would carry no link to what it reverses AND
     *    would evade the one-reversal-ever UNIQUE index — a database-level
     *    financial policy that is item 1 of the merge sign-off bundle. That is
     *    a second, uncontrolled reversal path, which is why this list is
     *    narrower than the review's suggestion rather than equal to it.
     *  - `payment`, `renewal`, `chargeback` — economic events other modules and
     *    lanes own (`AC7` gives refund and chargeback to the payment adapter).
     *
     * What is left is exactly what a corrective posting IS: a human verified a
     * discrepancy and posted a balancing entry for it. Every legitimate
     * `adjustment()` call in the tree already declares `manual_verification`
     * and nothing else — enumerated before this list was written, not after.
     *
     * Widening this is a deliberate, reviewable change and belongs to a human:
     * it is financial-authorization policy, `AGENTS.md` human-sign-off class.
     * Adding a member here must come with the argument for why that event type
     * may be conjured by a reconciliation decision rather than by the Action
     * that owns it.
     *
     * @var list<string>
     */
    public const array CORRECTABLE_SOURCE_TYPES = [
        'manual_verification',
    ];

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
        private ?string $subjectRef = null,
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
            subjectRef: trim($originalBusinessKey),
        );
    }

    /**
     * Post a new adjusting batch.
     *
     * @param  list<array{account: string, direction: string, amountMinor: int, reference?: string|null}>  $entries
     *                                                                                                               Validated by `Journal::post()` itself — account codes and integer minor
     *                                                                                                               units only, never customer PII.
     * @param  string  $subjectRef  The reconciliation exception subject this
     *                              correction is allowed to resolve.
     *
     * ---------------------------------------------------------------------
     * The business-key prefix must equal `$sourceType` (slice-3 Minor M5)
     * ---------------------------------------------------------------------
     * `journal_batches.source_type` and the business-key prefix are two
     * declarations of the same fact — what kind of economic event this batch
     * records. Nothing linked them: `Journal::assertSourcePrefixed()` only
     * requires a `:` after position 0, and the PostgreSQL
     * `journal_batches_business_key_prefix_check` is literally
     * `position(':' in business_key) > 1`. Both `sourceType` and `businessKey`
     * arrive here CALLER-SUPPLIED from a human resolving a reconciliation
     * exception, so this factory is the one human-reachable path on which a
     * batch could be posted claiming to be a payout — `sourceType: 'payout'`
     * is a legal value in the schema's closed list — with no `payouts` row, no
     * proof, no approver, no re-authentication and no `VENDOR_PAYOUT` audit
     * event behind it. `LedgerReport` and the reconciliation would then read it
     * as a payout.
     *
     * Not an AC9 bypass: no money moves, and `vendor_payables` still cannot
     * reach `paid` without a real `payouts` row (the deferred trigger enforces
     * that). What it costs is ledger LABELLING integrity.
     *
     * ---------------------------------------------------------------------
     * Why the check is HERE and deliberately NOT in `Journal::post()`
     * ---------------------------------------------------------------------
     * `Journal::post()` is the shared cross-lane seam `lane/l3-payment-adapter`
     * consumes through `Contracts\Journal`. Tightening its accepted inputs
     * would change documented contract behaviour for another lane mid-flight
     * AND make L3's `LedgerSeamStub` divergent from the real implementation a
     * SECOND way — reintroducing exactly the defect class already open as merge
     * sign-off bundle item 17 (E1). Trading one open cross-lane defect for two
     * is a bad deal. This class is this module's own value object, so the same
     * rule costs nothing outside it.
     *
     * The seam-level rule remains AVAILABLE BUT DELIBERATELY NOT TAKEN. If it
     * is ever wanted it must land as a contract term in `Contracts\Journal` AND
     * in L3's stub together, coordinated across both lanes — never in one of
     * them alone. That is a merge sign-off item, not a TODO for whoever reads
     * this next.
     *
     * All four production callers already conform (`vendor_payable:`/
     * `vendor_payable`, `payout:`/`payout`, `reversal:`/`reversal`, `refund:`/
     * `refund`), so this codifies the convention the module already keeps
     * rather than inventing one.
     *
     * @throws InvalidReconciliationException on a blank business key, an
     *                                        unprefixed business key, a prefix that disagrees with
     *                                        `$sourceType`, or an empty entry list.
     */
    public static function adjustment(
        string $businessKey,
        int|string $entityRef,
        string $subjectRef,
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

        if (trim($subjectRef) === '') {
            throw InvalidReconciliationException::forBlankCorrectionSubjectReference();
        }

        self::assertCorrectableSourceType($sourceType);
        self::assertPrefixDeclaresSourceType(trim($businessKey), $sourceType);

        return new self(
            kind: self::KIND_ADJUSTMENT,
            businessKey: trim($businessKey),
            sourceType: $sourceType,
            sourceId: (string) $sourceId,
            entries: $entries,
            entityRef: (string) $entityRef,
            subjectRef: trim($subjectRef),
        );
    }

    /**
     * `$sourceType` is compared UNTRIMMED on purpose. A padded value would
     * otherwise pass here and then fail later against the PostgreSQL
     * `journal_batches_source_type_check` as a raw constraint violation; this
     * way it fails at the seam with a message that names both halves. Trimming
     * it instead would ACCEPT input the database currently rejects, which is a
     * loosening this change has no business making.
     */
    /**
     * The guard that closes M5. Runs BEFORE the prefix check, so a correction
     * claiming to be a payout is refused for the reason that actually matters
     * — "a reconciliation correction may not declare itself a payout" — rather
     * than for a key-formatting reason that happens to catch some of the cases.
     */
    private static function assertCorrectableSourceType(string $sourceType): void
    {
        if (! in_array($sourceType, self::CORRECTABLE_SOURCE_TYPES, true)) {
            throw InvalidReconciliationException::forUncorrectableSourceType(
                $sourceType,
                self::CORRECTABLE_SOURCE_TYPES,
            );
        }
    }

    private static function assertPrefixDeclaresSourceType(string $businessKey, string $sourceType): void
    {
        $separator = strpos($businessKey, ':');

        if ($separator === false || $separator < 1) {
            throw InvalidReconciliationException::forCorrectionSourceTypeMismatch(
                $businessKey,
                '',
                $sourceType,
            );
        }

        $prefix = substr($businessKey, 0, $separator);

        if ($prefix !== $sourceType) {
            throw InvalidReconciliationException::forCorrectionSourceTypeMismatch(
                $businessKey,
                $prefix,
                $sourceType,
            );
        }
    }

    public function isReversal(): bool
    {
        return $this->kind === self::KIND_REVERSAL;
    }

    public function assertMatches(string $entityRef, string $subjectRef): void
    {
        if (! $this->isReversal() && (string) $this->entityRef !== $entityRef) {
            throw InvalidReconciliationException::forCorrectionEntityMismatch(
                expected: $entityRef,
                actual: (string) $this->entityRef,
            );
        }

        if ((string) $this->subjectRef !== $subjectRef) {
            throw InvalidReconciliationException::forCorrectionSubjectMismatch(
                expected: $subjectRef,
                actual: (string) $this->subjectRef,
            );
        }
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

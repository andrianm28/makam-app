<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Actions;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\FinancialLedger\Contracts\Journal as JournalContract;
use App\Platform\FinancialLedger\Contracts\VendorPayableAuthorizer;
use App\Platform\FinancialLedger\Exceptions\InvalidVendorPayableException;
use App\Platform\FinancialLedger\Exceptions\VendorPayableNotAuthorisedException;
use App\Platform\FinancialLedger\FinanceVendorPayableAuthorizer;
use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\Models\VendorPayable as VendorPayableModel;
use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\VendorPayableAssessmentTrigger;
use App\Platform\FinancialLedger\VendorPayableEligibility;
use App\Platform\FinancialLedger\VendorPayableState;
use App\Platform\IdentityAccess\ActorContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AC7/AC8: vendor payable as its own operation type, with an eligibility rule
 * that is explicitly SEPARATE from paid state.
 *
 * ---------------------------------------------------------------------------
 * The one rule this class exists to hold
 * ---------------------------------------------------------------------------
 * A paid order with no accepted fulfilment evidence stays `held`. Being paid
 * is one third of the rule, never a shortcut past the other two thirds. The
 * rule itself lives in `VendorPayableEligibility::isEligibleAt()` — a single
 * `AND` of three conditions with no branch, no early return and no `or` — so
 * that widening it requires editing a named, tested type rather than nudging
 * an `if` in here.
 *
 * ---------------------------------------------------------------------------
 * Eligible obligations accrue atomically
 * ---------------------------------------------------------------------------
 * The approved Wave 1b decision adds `2100 Utang Vendor`. The first eligible
 * assessment posts `DR 5000 / CR 2100` through the shared Journal API, inside
 * this Action's transaction with the payable row and its audit event. The
 * stable business key makes a repeated assessment idempotent; an already
 * payable row never accrues a second time.
 *
 * ---------------------------------------------------------------------------
 * The state machine only ever moves forward
 * ---------------------------------------------------------------------------
 * `held` -> `payable` -> `paid`, and never back. Re-assessing an already
 * eligible payable with failing inputs does NOT return it to `held`: evidence
 * withdrawn after we recognised a debt is a dispute or a correction, both of
 * which are decisions somebody makes and records, not a silent downgrade a
 * scheduled re-assessment performs on its own. Re-assessing a `paid` payable
 * changes nothing at all.
 *
 * ---------------------------------------------------------------------------
 * Authorization: server-side actor, same discipline as payout (added Task 9b)
 * ---------------------------------------------------------------------------
 * This Action writes the append-only ledger. Until Task 9b it did so with NO
 * authorization at all — the only such path in the module — and its audit row
 * recorded `actorRole: 'system'` whoever triggered it, so a human-triggered
 * assessment produced an audit trail asserting the system did it. AC7 requires
 * each operation type to have "its own authorization, journal shape, and
 * audit"; this had two of the three.
 *
 * It now matches the sibling pattern exactly: `ActorContext` as the first
 * constructor argument plus a real policy seam
 * (`Contracts\VendorPayableAuthorizer`), server-derived only, as
 * `ManualPayout`, `ResolveException` and `BulkFinancialExport` do.
 *
 * The legitimate automated trigger is preserved as its OWN explicitly-named
 * path — `VendorPayableAssessmentTrigger::UnattendedAssessment` — never as a
 * null or unauthenticated DEFAULT. The default is the human path, which fails
 * closed; the unattended path must be asked for by name and is refused if the
 * actor context shows a human is present. So `actorRole: 'system'` in an audit
 * row now means the system, by construction rather than by convention.
 */
final class VendorPayable
{
    private const string JOURNAL_SOURCE_TYPE = 'vendor_payable';

    private const string ACCOUNT_VENDOR_COST = '5000';

    private const string ACCOUNT_VENDOR_LIABILITY = '2100';

    /**
     * Audit action for the moment a debt is recognised. Deliberately NOT added
     * to `SensitiveActions::ACTIONS`: that list is a closed, human-reviewed
     * catalogue, a cross-lane coordination on that exact file is pending, and
     * an assessment is a machine-driven evaluation of recorded facts with no
     * human-authored justification behind it — requiring a reason would be a
     * caller-facing dead end, the same reasoning `SensitiveActions` already
     * records for the routine MFA outcomes. The payout, which IS a human
     * decision, is on that list already as `VENDOR_PAYOUT`.
     */
    public const string AUDIT_ACTION_ASSESSED = 'VENDOR_PAYABLE_ASSESSED';

    /**
     * Dependencies are explicit seams so the Action fails closed until the
     * sibling identity module provides an authoritative role source — the same
     * shape and the same wording as `ManualPayout` and `ResolveException`.
     */
    public function __construct(
        private readonly ActorContext $actorContext,
        private readonly VendorPayableAuthorizer $authorizer = new FinanceVendorPayableAuthorizer,
        private readonly JournalContract $journal = new Journal,
    ) {}

    /**
     * Record, or re-record, what we owe a vendor for one source record, and
     * decide from the three eligibility conditions whether that debt is `held`
     * or `payable`.
     *
     * Idempotent per `(vendorId, sourceType, sourceId)`: the UNIQUE index on
     * that triple is the authority, so a re-run updates the existing row
     * instead of opening a second obligation for work that was only done once.
     *
     * @param  string  $entityRef  The `badan usaha` this obligation belongs to,
     *                             frozen here so the payout batch's AC4 entity binding never has to
     *                             be re-derived from a mutable order row (AC6).
     * @param  Money  $amount  Integer minor units (AC11). Typed as `Money`
     *                         rather than `int` because the plan's Global Constraints make it
     *                         "the only money type crossing module boundaries", and because an
     *                         `int` parameter is NOT actually a guard: PHP's coercive mode
     *                         converts a numeric string at any call site whose own file does not
     *                         declare `strict_types`, so a decimal string reaching this Action
     *                         from a queued job payload or a form would be silently truncated.
     *                         `Money`'s constructor is the one place that check lives, and this
     *                         seam reuses it rather than restating it.
     * @param  VendorPayableAssessmentTrigger  $trigger  Who is causing this
     *                                                   assessment. Defaults to the HUMAN path, which is the one that
     *                                                   fails closed; the unattended path must be named explicitly and
     *                                                   is refused when a human actor is present on the context.
     *
     * @throws InvalidVendorPayableException on a blank identifier, a non-positive
     *                                       amount, or an attempt to restate the amount of a payable that is
     *                                       already eligible.
     * @throws VendorPayableNotAuthorisedException when the actor holds no
     *                                             finance authority for this vendor, or when the unattended path is
     *                                             requested while an authenticated actor is present.
     */
    public function assess(
        string $vendorId,
        string $entityRef,
        string $sourceType,
        int|string $sourceId,
        Money $amount,
        VendorPayableEligibility $eligibility,
        VendorPayableAssessmentTrigger $trigger = VendorPayableAssessmentTrigger::HumanDecision,
        ?string $correlationId = null,
        ?CarbonImmutable $now = null,
    ): VendorPayableModel {
        $sourceId = (string) $sourceId;
        $amountMinor = $amount->toMinorInt();

        $this->assertPresent('vendor_id', $vendorId);
        $this->assertPresent('entity_ref', $entityRef);
        $this->assertPresent('source_type', $sourceType);
        $this->assertPresent('source_id', $sourceId);

        if ($amountMinor <= 0) {
            throw InvalidVendorPayableException::forNonPositiveAmount($amountMinor);
        }

        // Authorization runs BEFORE the transaction opens and before any row is
        // read or written, so a refused assessment leaves no payable, no
        // journal batch and no audit row — the ordering
        // `ResolveException`/`ManualPayout` already follow.
        //
        // `$actorRole` is the authorizer's VERDICT, never a caller's claim, and
        // it is what the audit row records. `$actorRef` is null on the
        // unattended path only because the authorizer proved no human is
        // present.
        $actorRole = match ($trigger) {
            VendorPayableAssessmentTrigger::HumanDecision => $this->authorizer->authorize(
                $this->actorContext,
                $vendorId,
            ),
            VendorPayableAssessmentTrigger::UnattendedAssessment => $this->authorizer->authorizeUnattended(
                $this->actorContext,
                $vendorId,
            ),
        };

        // Derived through an explicit guard, NOT a cast — matching
        // `ManualPayout::actorReference()`, which the ruling said to follow
        // exactly. `FinanceVendorPayableAuthorizer::authorize()` does refuse a
        // null `identityReference` first, so the cast was correct today; but
        // that made the truthfulness of the audit row depend on an
        // IMPLEMENTATION of the seam rather than on this Action. An alternative
        // `VendorPayableAuthorizer` that returned a role without checking
        // identity presence would have written `actorRef: ''` — a blank actor
        // on a row recognising a debt — instead of failing closed.
        $actorRef = $trigger === VendorPayableAssessmentTrigger::HumanDecision
            ? $this->actorReference($vendorId)
            : null;

        $now ??= CarbonImmutable::now();
        $correlationId ??= app(CorrelationContext::class)->current()?->value;

        // One transaction so the row write and its audit row commit or roll
        // back together (AC4). `Audit::record()` opens none of its own, by the
        // same discipline `Journal::post()` follows.
        return DB::transaction(function () use (
            $vendorId,
            $entityRef,
            $sourceType,
            $sourceId,
            $amountMinor,
            $eligibility,
            $correlationId,
            $now,
            $actorRef,
            $actorRole,
        ): VendorPayableModel {
            $existing = VendorPayableModel::query()
                ->where('vendor_id', $vendorId)
                ->where('source_type', $sourceType)
                ->where('source_id', $sourceId)
                ->lockForUpdate()
                ->first();

            return $existing instanceof VendorPayableModel
                ? $this->reassess($existing, $amountMinor, $eligibility, $now, $correlationId, $actorRef, $actorRole)
                : $this->open(
                    $vendorId,
                    $entityRef,
                    $sourceType,
                    $sourceId,
                    $amountMinor,
                    $eligibility,
                    $correlationId,
                    $now,
                    $actorRef,
                    $actorRole,
                );
        });
    }

    private function open(
        string $vendorId,
        string $entityRef,
        string $sourceType,
        string $sourceId,
        int $amountMinor,
        VendorPayableEligibility $eligibility,
        ?string $correlationId,
        CarbonImmutable $now,
        ?string $actorRef,
        string $actorRole,
    ): VendorPayableModel {
        $eligible = $eligibility->isEligibleAt($now);
        $id = (string) Str::uuid();

        DB::table('vendor_payables')->insert([
            'id' => $id,
            'vendor_id' => $vendorId,
            'entity_ref' => $entityRef,
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'amount_minor' => $amountMinor,
            'state' => $eligible ? VendorPayableState::PAYABLE : VendorPayableState::HELD,
            'eligible_at' => $eligible ? $now : null,
            'paid_at' => null,
            'correlation_id' => $correlationId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $payable = VendorPayableModel::query()->findOrFail($id);

        if ($eligible) {
            $this->accrue($payable, $correlationId, $now);
        }

        $this->audit(
            $payable,
            previousState: null,
            correlationId: $correlationId,
            actorRef: $actorRef,
            actorRole: $actorRole,
        );

        return $payable;
    }

    private function reassess(
        VendorPayableModel $payable,
        int $amountMinor,
        VendorPayableEligibility $eligibility,
        CarbonImmutable $now,
        ?string $correlationId,
        ?string $actorRef,
        string $actorRole,
    ): VendorPayableModel {
        // A settled debt is finished. Nothing a later assessment discovers
        // re-opens it — a correction after payout is a new economic event with
        // its own record, not a rewrite of this one.
        if ($payable->state === VendorPayableState::PAID) {
            return $payable;
        }

        if ($payable->state === VendorPayableState::PAYABLE) {
            if ($payable->amount_minor !== $amountMinor) {
                throw InvalidVendorPayableException::forAmountChangeAfterEligibility(
                    (string) $payable->id,
                    (int) $payable->amount_minor,
                    $amountMinor,
                );
            }

            // Already recognised. Re-assessment never walks it back to `held`.
            return $payable;
        }

        $eligible = $eligibility->isEligibleAt($now);

        $payable->forceFill([
            'amount_minor' => $amountMinor,
            'state' => $eligible ? VendorPayableState::PAYABLE : VendorPayableState::HELD,
            'eligible_at' => $eligible ? $now : null,
            'updated_at' => $now,
        ])->save();

        if ($eligible) {
            // The CURRENT correlation id, not `$payable->correlation_id`.
            // That column holds the correlation of the FIRST assessment — the
            // run that decided NOT to recognise the debt — so tracing the
            // accrual and its audit row through it led to the wrong job.
            // `AGENTS.md` §Observability treats a money row's trace id as
            // load-bearing, and the row being traced here is the one that
            // recognised the liability.
            $this->accrue($payable, $correlationId, $now);
            $this->audit(
                $payable,
                previousState: VendorPayableState::HELD,
                correlationId: $correlationId,
                actorRef: $actorRef,
                actorRole: $actorRole,
            );
        }

        return $payable;
    }

    private function accrue(
        VendorPayableModel $payable,
        ?string $correlationId,
        CarbonImmutable $now,
    ): void {
        $this->journal->post(
            businessKey: $this->accrualBusinessKey(
                (string) $payable->vendor_id,
                (string) $payable->source_type,
                (string) $payable->source_id,
            ),
            entityRef: (string) $payable->entity_ref,
            sourceType: self::JOURNAL_SOURCE_TYPE,
            sourceId: (string) $payable->id,
            entries: [
                [
                    'account' => self::ACCOUNT_VENDOR_COST,
                    'direction' => 'DR',
                    'amountMinor' => (int) $payable->amount_minor,
                    'reference' => 'vendor_payable:'.(string) $payable->id,
                ],
                [
                    'account' => self::ACCOUNT_VENDOR_LIABILITY,
                    'direction' => 'CR',
                    'amountMinor' => (int) $payable->amount_minor,
                    'reference' => 'vendor_payable:'.(string) $payable->id,
                ],
            ],
            correlationId: $correlationId,
            occurredAt: $now->toIso8601String(),
        );
    }

    public function accrualBusinessKey(string $vendorId, string $sourceType, string $sourceId): string
    {
        return "vendor_payable:{$vendorId}:{$sourceType}:{$sourceId}";
    }

    /**
     * Metadata carries the two state names and nothing else — both keys are on
     * `MetadataAllowlist::ALLOWED_KEYS` already, so no allowlist change is
     * needed and no vendor, customer or monetary detail travels into an audit
     * payload (`AGENTS.md` §Observability).
     */
    private function audit(
        VendorPayableModel $payable,
        ?string $previousState,
        ?string $correlationId,
        ?string $actorRef,
        string $actorRole,
    ): void {
        $metadata = ['new_state' => (string) $payable->state];

        if ($previousState !== null) {
            $metadata['previous_state'] = $previousState;
        }

        Audit::record(
            action: self::AUDIT_ACTION_ASSESSED,
            subject: new AuditSubject('vendor_payable', (string) $payable->id),
            outcome: AuditOutcome::Allowed,
            // Both of these are the AUTHORIZER's verdict, threaded down from
            // `assess()`. They used to be hardcoded `null` / `'system'`, which
            // meant a human-triggered assessment wrote an audit row asserting
            // the system did it. Now `null`/`system` appears only when
            // `authorizeUnattended()` proved no human was present.
            //
            // `actorRole` is required even when `actorRef` is null —
            // `Audit::record()`'s own rule.
            actorRef: $actorRef,
            actorRole: $actorRole,
            // A panel action and a scheduled run are genuinely different
            // sources, and an audit reader filtering on `Job` should not see
            // human decisions in the results.
            source: $actorRef === null ? AuditSource::Job : AuditSource::Panel,
            correlationId: $correlationId,
            metadata: $metadata,
        );
    }

    /**
     * The acting human's server-derived reference, or a refusal. Never a cast.
     *
     * @throws VendorPayableNotAuthorisedException
     */
    private function actorReference(string $vendorId): string
    {
        if ($this->actorContext->identityReference === null) {
            throw VendorPayableNotAuthorisedException::forActorContext($vendorId);
        }

        return (string) $this->actorContext->identityReference;
    }

    private function assertPresent(string $field, string $value): void
    {
        if (trim($value) === '') {
            throw InvalidVendorPayableException::forBlankIdentifier($field);
        }
    }
}

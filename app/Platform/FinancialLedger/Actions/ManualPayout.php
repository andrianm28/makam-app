<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger\Actions;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\SensitiveActions;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\FinancialLedger\Contracts\Journal as JournalContract;
use App\Platform\FinancialLedger\Exceptions\InvalidPayoutException;
use App\Platform\FinancialLedger\Exceptions\PayoutNotAuthorisedException;
use App\Platform\FinancialLedger\Exceptions\PayoutReauthenticationRequiredException;
use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\Models\Payout;
use App\Platform\FinancialLedger\Models\VendorPayable as VendorPayableModel;
use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\PayoutMethod;
use App\Platform\FinancialLedger\PayoutProof;
use App\Platform\FinancialLedger\PayoutState;
use App\Platform\FinancialLedger\VendorPayableState;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * AC9: the manual payout. A human moved money through a bank, outside this
 * system; this Action records that it happened, who authorised it, what proves
 * it, and posts the ledger entry for it.
 *
 * ---------------------------------------------------------------------------
 * There is no automated transfer path, and that is structural
 * ---------------------------------------------------------------------------
 * `G-PAYOUT-01` is closed, and the plan's Global Constraints are explicit that
 * this means "no automated transfer code path exists at all (structural, not
 * just gated)". So there is nothing here to disable: no provider client, no
 * HTTP call, no `transfer()`/`disburse()` method, no config switch that would
 * turn one on, and no `PayoutMethod` member or `PayoutState` value describing
 * one. `tests/Feature/FinancialLedger/NoAutomatedPayoutPathTest.php` asserts
 * that absence over the whole `app/Platform/` tree, and proves its own
 * detector has teeth by running it against a synthetic sample.
 *
 * This Action's only outward effect is on this database.
 *
 * ---------------------------------------------------------------------------
 * What a payout must satisfy before anything is written
 * ---------------------------------------------------------------------------
 * Every one of these is checked BEFORE the transaction opens, so a refused
 * payout writes no payout row, changes no payable state, and posts no journal
 * batch. Ordering the sensitive-action reason check at the top follows the
 * precedent set when `RecordServiceDefinitionPriceVersion` was corrected: the
 * mandatory-reason guard belongs at the signature, not as a runtime throw from
 * inside `DB::transaction()`.
 *
 *  1. a non-blank reason — `VENDOR_PAYOUT` is on `SensitiveActions::ACTIONS`;
 *  2. a named approver;
 *  3. a proof reference (`PayoutProof` refuses to construct without one);
 *  4. a positive integer minor-unit amount (AC11);
 *  5. an approver holding payout authorisation for THIS vendor;
 *  6. that approver having re-authenticated recently.
 *
 * ---------------------------------------------------------------------------
 * How authorisation is decided, and why not with `ActorContext::hasRole()`
 * ---------------------------------------------------------------------------
 * `ActorContext::$roles` and `::$scopes` are permanently `[]` today — that
 * class's own doc block says so, and warns: "any consumer that needs
 * role-based checks before that gap is closed must not silently treat an empty
 * roles list as 'no roles required.'" A `hasRole('finance')` check would
 * therefore be theatre: it would read as a control while being backed by
 * nothing.
 *
 * `scope_assignments` IS real, populated, and queryable today. Authorisation
 * here is an active, non-revoked assignment for the approver on
 * `entity_type = 'vendor'`, `entity_id = {vendor}`, at grant level
 * `privileged` — `docs/security/rbac-matrix.md`'s "Payout/refund" row is `No`
 * for every role except a restricted admin and a dedicated finance role, so
 * `own`/`assigned`/`read` are deliberately not enough. This is exactly the
 * use `ScopeGrantLevel`'s own doc block anticipates: "carried on the row
 * anyway ... purely as forward-compatible metadata so a future Policy class
 * can read it without a schema change." This is that Policy layer.
 *
 * Fail-closed: no grant row means refused. A vendor nobody has been granted
 * payout authority over cannot be paid, which is the correct default for a
 * control that has not been configured.
 *
 * ---------------------------------------------------------------------------
 * How re-authentication freshness is decided
 * ---------------------------------------------------------------------------
 * `ReauthenticationService` is the repository's re-authentication mechanism and
 * no second one is invented here. It writes `reauthentication_events`:
 * `challenge()` when a sensitive action is refused pending a re-proof,
 * `satisfy()` once a controller has confirmed the actor re-proved identity.
 * What it does not expose is a "is this actor fresh?" question, because until
 * now its only caller was HTTP middleware reading
 * `ActorContext::$lastAuthenticatedAt` — a per-request value an Action invoked
 * from a job or a Filament page does not have.
 *
 * So freshness is read from that service's own event log: the approver needs a
 * `satisfied` event, for THIS action class, inside
 * `config('reauthentication.freshness_seconds')` — the same configured window
 * `RequireRecentAuthentication` uses, read on every call so a test override is
 * honoured. Requiring the reason to match `self::REAUTHENTICATION_REASON`
 * matters: re-proving identity to revoke a certificate must not silently
 * authorise a payout.
 *
 * When it is not satisfied, `ReauthenticationService::challenge()` is called
 * before refusing, so the stale attempt leaves a `reauthentication_events` row
 * and a `Denied` audit event rather than only an exception. That is precisely
 * the semantics `challenge()`'s own doc block gives it: "the sensitive action
 * itself is being refused until a fresh re-proof happens."
 *
 * ---------------------------------------------------------------------------
 * The journal posting, and one divergence from the brief's wording
 * ---------------------------------------------------------------------------
 * The batch is posted through `Contracts\Journal` — the ONE write API — from
 * inside this Action's own `DB::transaction()`, so the payout row, the payable
 * state change, the audit row and the ledger entries commit or roll back as
 * one (AC3). `Journal::post()` opens no transaction of its own by design.
 *
 * The plan and this task's brief describe the posting as "cash-out DR,
 * vendor-payable CR". That cannot be written as stated, for two reasons, so it
 * is implemented as the economically equivalent posting the approved chart of
 * accounts can actually express:
 *
 *  - There is no vendor-payable account. `ChartOfAccounts::MINIMAL_INITIAL_ACCOUNTS`
 *    holds seven codes and none of them is a vendor liability, and
 *    `journal_entries.account_code` is a real foreign key into `coa_accounts`,
 *    so a code that is not there cannot be posted at all.
 *  - Crediting a vendor-payable liability would INCREASE what we owe. Paying a
 *    debt down is a debit to it. There is also no liability balance to debit,
 *    because `VendorPayable::assess()` posts no accrual batch — see that
 *    class's doc block for why the accrual is left to the finance owner.
 *
 * What is posted is therefore `DR 5000 HPP / Komisi Vendor` against
 * `CR 7000 Rekening Kas/Bank`: the vendor cost is recognised and cash leaves
 * the business, which is the economic event AC9 is about. Both codes are
 * constants below so replacing them is a one-line change once FIN-DEC-03 and
 * FIN-DEC-05 are decided — `docs/domain/financial-model.md` §6 is explicit
 * that "conceptual posting names are defined by accounting approval, not
 * hard-coded in domain code", and both decisions are still TBD.
 *
 * Nothing here reads, edits or references the customer's original journal
 * rows. The payout is its own batch with its own business key.
 */
final class ManualPayout
{
    /**
     * Already present on `SensitiveActions::ACTIONS`, which is why recording a
     * payout without a reason throws. That list is NOT edited by this module —
     * a cross-lane coordination on it is pending — so
     * `tests/Feature/FinancialLedger/ManualPayoutTest.php` asserts the wiring
     * still holds instead.
     */
    public const string AUDIT_ACTION = 'VENDOR_PAYOUT';

    /**
     * The sensitive-action class a re-authentication must have been satisfied
     * for. Matches the vocabulary
     * `docs/security/authentication-and-mfa.md` §5 uses ("payment/refund/payout
     * approval") and the example
     * `App\Http\Middleware\RequireRecentAuthentication`'s own doc block gives
     * for a payout-approval route.
     */
    public const string REAUTHENTICATION_REASON = 'payout_approval';

    /**
     * `journal_batches.source_type`'s closed list (Task 2's PostgreSQL CHECK)
     * already contains `payout`; this is a reference to it, not a second list.
     */
    private const string JOURNAL_SOURCE_TYPE = 'payout';

    /**
     * `5000 HPP / Komisi Vendor` — the vendor cost, debited. See the class doc
     * block for why this and not a vendor-payable account.
     */
    private const string ACCOUNT_VENDOR_COST = '5000';

    /**
     * `7000 Rekening Kas/Bank` — cash, credited, because money left.
     */
    private const string ACCOUNT_CASH = '7000';

    /**
     * The grant levels that carry payout authority. A list rather than a
     * single constant so widening it is a deliberate, reviewable edit in one
     * place instead of a loosened comparison somewhere in a method.
     *
     * @var list<string>
     */
    private const array AUTHORISED_GRANT_LEVELS = [
        ScopeGrantLevel::PRIVILEGED,
    ];

    public function __construct(
        private readonly JournalContract $journal = new Journal,
        private readonly ReauthenticationService $reauthentication = new ReauthenticationService,
    ) {}

    /**
     * Record a manual payout against an eligible vendor payable.
     *
     * @param  Money  $amount  Integer minor units (AC11), and equal to the
     *                         payable's own amount: a payout discharges a payable in full,
     *                         because a partial one would mark the payable paid while leaving a
     *                         residual debt nothing tracks. Typed as `Money` for the same reason
     *                         `VendorPayable::assess()` is — an `int` parameter is coerced, not
     *                         checked, at any call site that does not declare `strict_types`.
     * @param  PayoutProof  $proof  A document-vault REFERENCE to the bank
     *                              transfer record. Never its contents.
     * @param  string  $reason  Mandatory and non-blank — `VENDOR_PAYOUT` is a
     *                          sensitive action. Must be free of restricted data, the same
     *                          discipline `Audit::record()`'s own `$reason` carries.
     *
     * @throws AuditReasonRequiredException on a blank reason.
     * @throws InvalidPayoutException on a blank approver, a non-positive
     *                                amount, an unknown payable, a payable that is not in state
     *                                `payable`, or an amount that does not match the payable.
     * @throws PayoutNotAuthorisedException when the approver holds no payout
     *                                      authorisation for this vendor.
     * @throws PayoutReauthenticationRequiredException when the approver has not
     *                                                 re-authenticated inside the configured freshness window.
     */
    public function pay(
        string $payableId,
        Money $amount,
        PayoutProof $proof,
        int|string $approverRef,
        string $approverRole,
        string $reason,
        ?string $correlationId = null,
        ?CarbonImmutable $occurredAt = null,
        string $ip = '0.0.0.0',
    ): Payout {
        $approverRef = (string) $approverRef;
        $amountMinor = $amount->toMinorInt();

        if (trim($reason) === '') {
            throw AuditReasonRequiredException::forAction(self::AUDIT_ACTION);
        }

        if (trim($approverRef) === '') {
            throw InvalidPayoutException::forBlankApprover('reference');
        }

        if (trim($approverRole) === '') {
            throw InvalidPayoutException::forBlankApprover('role');
        }

        if ($amountMinor <= 0) {
            throw InvalidPayoutException::forNonPositiveAmount($amountMinor);
        }

        $payable = VendorPayableModel::query()->find($payableId);

        if (! $payable instanceof VendorPayableModel) {
            throw InvalidPayoutException::forUnknownPayable($payableId);
        }

        $this->assertApproverMayPayOut($approverRef, (string) $payable->vendor_id);
        $this->assertApproverReauthenticatedRecently($approverRef, $approverRole, $ip);

        $occurredAt ??= CarbonImmutable::now();
        $correlationId ??= app(CorrelationContext::class)->current()?->value;

        return DB::transaction(function () use (
            $payableId,
            $amountMinor,
            $proof,
            $approverRef,
            $approverRole,
            $reason,
            $correlationId,
            $occurredAt,
        ): Payout {
            // Re-read under a row lock rather than trusting the instance
            // loaded before the guards ran: between those two points another
            // request may have paid this payable. On PostgreSQL the lock
            // serialises the two attempts; on SQLite it is a no-op, which is
            // why the UNIQUE indexes on `payouts.payable_id` and
            // `journal_batches.business_key` — not this check — are the real
            // authority for "a payable is paid out once".
            $payable = VendorPayableModel::query()->lockForUpdate()->findOrFail($payableId);

            if ($payable->state !== VendorPayableState::PAYABLE) {
                throw InvalidPayoutException::forPayableNotPayable(
                    $payableId,
                    (string) $payable->state,
                );
            }

            if ((int) $payable->amount_minor !== $amountMinor) {
                throw InvalidPayoutException::forAmountMismatch(
                    $payableId,
                    (int) $payable->amount_minor,
                    $amountMinor,
                );
            }

            $payoutId = (string) Str::uuid();
            $businessKey = $this->businessKeyFor((string) $payable->vendor_id, $payableId);

            DB::table('payouts')->insert([
                'id' => $payoutId,
                'payable_id' => $payableId,
                'vendor_id' => $payable->vendor_id,
                'entity_ref' => $payable->entity_ref,
                'amount_minor' => $amountMinor,
                'method' => PayoutMethod::MANUAL_BANK_TRANSFER,
                'state' => PayoutState::RECORDED,
                'proof_document_kind' => $proof->documentKind,
                'proof_document_ref' => $proof->documentReference,
                'approver_ref' => $approverRef,
                'approver_role' => $approverRole,
                'journal_business_key' => $businessKey,
                'correlation_id' => $correlationId,
                'occurred_at' => $occurredAt,
                'created_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ]);

            $payable->forceFill([
                'state' => VendorPayableState::PAID,
                'paid_at' => $occurredAt,
                'updated_at' => $occurredAt,
            ])->save();

            // Posted last on purpose: a retried payout collides on this
            // business key, the insert throws, and this whole transaction —
            // payout row and payable state change included — rolls back. AC5's
            // idempotency is the database's, not a check in here.
            $this->journal->post(
                businessKey: $businessKey,
                entityRef: (string) $payable->entity_ref,
                sourceType: self::JOURNAL_SOURCE_TYPE,
                sourceId: $payoutId,
                entries: [
                    [
                        'account' => self::ACCOUNT_VENDOR_COST,
                        'direction' => 'DR',
                        'amountMinor' => $amountMinor,
                        'reference' => 'vendor_payable:'.$payableId,
                    ],
                    [
                        'account' => self::ACCOUNT_CASH,
                        'direction' => 'CR',
                        'amountMinor' => $amountMinor,
                        'reference' => 'vendor_payable:'.$payableId,
                    ],
                ],
                correlationId: $correlationId,
                occurredAt: $occurredAt->toIso8601String(),
            );

            Audit::record(
                action: self::AUDIT_ACTION,
                subject: new AuditSubject('payout', $payoutId),
                outcome: AuditOutcome::Allowed,
                actorRef: $approverRef,
                actorRole: $approverRole,
                source: AuditSource::Panel,
                reason: $reason,
                correlationId: $correlationId,
                // Every key is already on `MetadataAllowlist::ALLOWED_KEYS`, so
                // no allowlist edit is needed. All three values are references
                // and state names — no amount, no proof content, no identity.
                metadata: [
                    'previous_state' => VendorPayableState::PAYABLE,
                    'new_state' => VendorPayableState::PAID,
                    'reference_number' => $businessKey,
                ],
            );

            return Payout::query()->findOrFail($payoutId);
        });
    }

    /**
     * The business key `payout:{vendor}:{payable}`, source-prefixed as
     * `journal_batches`' own CHECK requires. Derived from the payable, never
     * from caller input, so a retry of the same payout cannot produce a
     * different key and slip past the UNIQUE index.
     */
    public function businessKeyFor(string $vendorId, string $payableId): string
    {
        return "payout:{$vendorId}:{$payableId}";
    }

    private function assertApproverMayPayOut(string $approverRef, string $vendorId): void
    {
        $authorised = ScopeAssignment::query()
            ->where('actor_identifier', $approverRef)
            ->where('entity_type', ScopeEntityType::VENDOR)
            ->where('entity_id', $vendorId)
            ->whereIn('grant_level', self::AUTHORISED_GRANT_LEVELS)
            ->whereNull('revoked_at')
            ->exists();

        if (! $authorised) {
            throw PayoutNotAuthorisedException::forApprover($approverRef, $vendorId);
        }
    }

    private function assertApproverReauthenticatedRecently(
        string $approverRef,
        string $approverRole,
        string $ip,
    ): void {
        $freshnessSeconds = (int) config('reauthentication.freshness_seconds');

        $fresh = ReauthenticationEvent::query()
            ->where('actor_ref', $approverRef)
            ->where('outcome', ReauthenticationOutcome::SATISFIED)
            ->where('reason', self::REAUTHENTICATION_REASON)
            ->where('occurred_at', '>=', CarbonImmutable::now()->subSeconds($freshnessSeconds))
            ->exists();

        if ($fresh) {
            return;
        }

        // Record the refusal through the service that owns re-authentication,
        // so a stale approval attempt is visible in `reauthentication_events`
        // and in the audit log, not only in whatever caught the exception.
        $this->reauthentication->challenge(
            actorRef: $approverRef,
            actorRole: $approverRole,
            reason: self::REAUTHENTICATION_REASON,
            source: AuditSource::Panel,
            ip: $ip,
        );

        throw PayoutReauthenticationRequiredException::forApprover($approverRef, $freshnessSeconds);
    }

    /**
     * Kept so a reader checking "is `VENDOR_PAYOUT` really on the sensitive
     * list" has an answer in this file rather than a cross-reference, without
     * this module restating the list itself.
     */
    public static function requiresAuditReason(): bool
    {
        return SensitiveActions::requiresReason(self::AUDIT_ACTION);
    }
}

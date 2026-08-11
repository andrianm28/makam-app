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
use App\Platform\FinancialLedger\Contracts\PayoutAuthorizer;
use App\Platform\FinancialLedger\Contracts\PayoutProofVerifier;
use App\Platform\FinancialLedger\Exceptions\InvalidPayoutException;
use App\Platform\FinancialLedger\Exceptions\PayoutActorMismatchException;
use App\Platform\FinancialLedger\Exceptions\PayoutNotAuthorisedException;
use App\Platform\FinancialLedger\Exceptions\PayoutReauthenticationRequiredException;
use App\Platform\FinancialLedger\FinanceOrRestrictedAdminPayoutAuthorizer;
use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\Models\Payout;
use App\Platform\FinancialLedger\Models\VendorPayable as VendorPayableModel;
use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\PayoutMethod;
use App\Platform\FinancialLedger\PayoutProof;
use App\Platform\FinancialLedger\PayoutState;
use App\Platform\FinancialLedger\RejectingPayoutProofVerifier;
use App\Platform\FinancialLedger\VendorPayableState;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
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
 * How authorisation is decided
 * ---------------------------------------------------------------------------
 * `FinanceOrRestrictedAdminPayoutAuthorizer` is an explicit policy seam. It
 * requires both a real finance/restricted-admin role from the server-side
 * `ActorContext` and an active vendor-scoped privileged assignment. Caller
 * supplied identity and role values never participate in that decision. The
 * current local adapter exposes no real roles, so it fails closed.
 *
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
 * The journal posting
 * ---------------------------------------------------------------------------
 * The batch is posted through `Contracts\Journal` — the ONE write API — from
 * inside this Action's own `DB::transaction()`, so the payout row, the payable
 * state change, the audit row and the ledger entries commit or roll back as
 * one (AC3). `Journal::post()` opens no transaction of its own by design.
 *
 * The approved accounting decision is a vendor-liability settlement:
 * `DR 2100 Utang Vendor / CR 7000 Rekening Kas/Bank`. Assessment separately
 * accrues `DR 5000 / CR 2100`.
 *
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
     * contains `payout`.
     */
    private const string JOURNAL_SOURCE_TYPE = 'payout';

    /**
     * `2100 Liabilitas — Utang Vendor` — the liability, debited at payout.
     */
    private const string ACCOUNT_VENDOR_LIABILITY = '2100';

    /**
     * `7000 Rekening Kas/Bank` — cash, credited, because money left.
     */
    private const string ACCOUNT_CASH = '7000';

    /**
     * Dependencies are explicit seams so the action can fail closed until the
     * sibling modules provide their authoritative bindings.
     */
    public function __construct(
        private readonly ActorContext $actorContext,
        private readonly PayoutAuthorizer $authorizer = new FinanceOrRestrictedAdminPayoutAuthorizer,
        private readonly PayoutProofVerifier $proofVerifier = new RejectingPayoutProofVerifier,
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
        ?string $approverRef = null,
        ?string $approverRole = null,
        string $reason = '',
        ?string $correlationId = null,
        ?CarbonImmutable $occurredAt = null,
        string $ip = '0.0.0.0',
    ): Payout {
        $amountMinor = $amount->toMinorInt();

        if (trim($reason) === '') {
            throw AuditReasonRequiredException::forAction(self::AUDIT_ACTION);
        }

        if ($amountMinor <= 0) {
            throw InvalidPayoutException::forNonPositiveAmount($amountMinor);
        }

        $payable = VendorPayableModel::query()->find($payableId);

        // Minor M6: an unknown id and an id outside this actor's scope must be
        // indistinguishable, or `pay()` is an existence oracle over payable
        // UUIDs. Same shape `ResolveException` already uses.
        if (! $payable instanceof VendorPayableModel) {
            throw PayoutNotAuthorisedException::forUnavailablePayable();
        }

        // Both of these are inside ONE catch on purpose. Authorising first and
        // resolving the actor second would re-open the oracle from the other
        // side: an unauthenticated caller would get "no actor context" for a
        // real payable and "unavailable" for a fictitious one.
        try {
            $actorRef = $this->actorReference();
            $actorRole = $this->authorizer->authorize($this->actorContext, (string) $payable->vendor_id);
        } catch (PayoutNotAuthorisedException) {
            throw PayoutNotAuthorisedException::forUnavailablePayable();
        }

        if ($approverRef !== null && trim($approverRef) !== $actorRef) {
            throw PayoutActorMismatchException::forField('reference');
        }

        if ($approverRole !== null && trim($approverRole) !== $actorRole) {
            throw PayoutActorMismatchException::forField('role');
        }

        // The sibling DocumentVault adapter is the only implementation that
        // may allow this. Until L1 binds it, the constructor's rejecting
        // verifier ensures an opaque reference can never pass by itself.
        $this->proofVerifier->assertAcceptedPrivateRecordScoped(
            $proof,
            recordType: 'vendor_payable',
            recordId: $payableId,
        );
        $this->assertApproverReauthenticatedRecently($actorRef, $actorRole, $ip);

        $occurredAt ??= CarbonImmutable::now();
        $correlationId ??= app(CorrelationContext::class)->current()?->value;

        return DB::transaction(function () use (
            $payableId,
            $amountMinor,
            $proof,
            $actorRef,
            $actorRole,
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
                'approver_ref' => $actorRef,
                'approver_role' => $actorRole,
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
                        'account' => self::ACCOUNT_VENDOR_LIABILITY,
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
                actorRef: $actorRef,
                actorRole: $actorRole,
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

    private function actorReference(): string
    {
        if ($this->actorContext->identityReference === null) {
            throw PayoutNotAuthorisedException::forActorContext('unknown');
        }

        return (string) $this->actorContext->identityReference;
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

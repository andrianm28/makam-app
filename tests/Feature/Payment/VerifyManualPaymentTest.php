<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\Marketplace\Exceptions\MarketplacePaymentAmountMismatchException;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\PaymentState;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FinancialLedger\Actions\VendorPayable;
use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\VendorPayableAssessmentTrigger;
use App\Platform\FinancialLedger\VendorPayableEligibility;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\Payment\Exceptions\PaymentVerificationAlreadyDecidedException;
use App\Platform\Payment\Exceptions\PaymentVerificationMissingLinkageException;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\PaymentVerification;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentVerificationDecision;
use App\Platform\Payment\PaymentVerificationStatus;
use App\Platform\Payment\VerifyManualPayment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `VerifyManualPayment` — Task 5's safe slice, Wave 1c Append-Correction
 * (`task-5-brief.md`), extended by PAY-02
 * (`docs/testing/release-gates.md` §C) to actually mark the linked
 * marketplace order paid on approval. Proves AC8's "separate authorized
 * action": a `payment_verifications` row moves from `SUBMITTED` to
 * `VERIFIED`/`REJECTED` exactly once, with a mandatory reason enforced by
 * `Audit::record()`'s own `SensitiveActions` check (not re-implemented
 * here); proves PAY-02's amount-matched order transition and its
 * all-or-nothing rollback on mismatch; and structurally proves the
 * remaining hard prohibitions: no `payment_sessions` write, no
 * `Journal::post()`, no `app/Domain/OrderWorkflow/` reference (booking
 * orders never flow through this table — see PAY-02's migration doc block).
 * `VerifyManualPaymentRouteTest` covers the HTTP/re-authentication half.
 */
final class VerifyManualPaymentTest extends TestCase
{
    use RefreshDatabase;

    private const int TOTAL_MINOR = 325_000_00;

    /**
     * A marketplace order placed through the checkout shape (BELUM_DIBAYAR,
     * vendor payable opened HELD) plus a SUBMITTED `payment_verifications`
     * row linked to it with a matching stated amount — same fixture shape
     * `WebhookPaidEffectsTest::marketplaceOrder()` already establishes for
     * the sibling (webhook) paid path.
     */
    private function submittedVerificationForANewOrder(?int $statedAmountMinor = null): array
    {
        $vendor = Vendor::query()->create(['name' => 'Toko Bunga', 'is_active' => true]);

        $order = MarketplaceOrder::query()->create([
            'order_number' => 'MKT-'.Str::upper(Str::random(10)),
            'customer_ref' => 'cust-1',
            'entity_ref' => 'BU-JKT-01',
            'vendor_id' => $vendor->id,
            'subtotal_minor' => self::TOTAL_MINOR,
            'delivery_fee_minor' => 0,
            'total_minor' => self::TOTAL_MINOR,
            'payment_state' => PaymentState::BELUM_DIBAYAR,
            'idempotency_key' => 'mkt-'.Str::lower(Str::random(12)),
            'placed_at' => CarbonImmutable::now(),
        ]);

        (new VendorPayable(actorContext: ActorContext::guest()))->assess(
            vendorId: $vendor->id,
            entityRef: 'BU-JKT-01',
            sourceType: 'marketplace_order',
            sourceId: $order->id,
            amount: new Money(self::TOTAL_MINOR),
            eligibility: new VendorPayableEligibility(
                orderPaid: false,
                fulfilmentEvidenceAccepted: false,
                disputeWindowEndsAt: null,
            ),
            trigger: VendorPayableAssessmentTrigger::UnattendedAssessment,
            now: CarbonImmutable::now(),
        );

        $verification = PaymentVerification::createSubmitted([
            'reference' => $order->order_number,
            'order_id' => $order->id,
            'amount_minor' => $statedAmountMinor ?? self::TOTAL_MINOR,
            'currency' => 'IDR',
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'TRX-1',
            'instructions' => null,
            'submitted_at' => CarbonImmutable::now(),
        ]);

        return [$verification, $order];
    }

    private function submittedVerification(): PaymentVerification
    {
        [$verification] = $this->submittedVerificationForANewOrder();

        return $verification;
    }

    public function test_approving_transitions_to_verified_and_records_the_decision(): void
    {
        $verification = $this->submittedVerification();

        (new VerifyManualPayment)->verify(
            verification: $verification,
            decision: PaymentVerificationDecision::Approve,
            reason: 'Proof matched provider statement',
            actorRef: 9,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $verification->refresh();

        $this->assertSame(PaymentVerificationStatus::Verified, $verification->status());
        $this->assertNotNull($verification->decided_at);
        $this->assertSame('Proof matched provider statement', $verification->decided_reason);
        $this->assertSame('9', $verification->decided_by_actor_ref);
    }

    public function test_rejecting_transitions_to_rejected_and_records_the_reason(): void
    {
        $verification = $this->submittedVerification();

        (new VerifyManualPayment)->verify(
            verification: $verification,
            decision: PaymentVerificationDecision::Reject,
            reason: 'Amount does not match',
            actorRef: 9,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $verification->refresh();

        $this->assertSame(PaymentVerificationStatus::Rejected, $verification->status());
        $this->assertSame('Amount does not match', $verification->decided_reason);
    }

    public function test_the_audit_event_carries_the_mandatory_reason_and_the_right_outcome(): void
    {
        $approved = $this->submittedVerification();

        (new VerifyManualPayment)->verify(
            verification: $approved,
            decision: PaymentVerificationDecision::Approve,
            reason: 'Proof matched provider statement',
            actorRef: 9,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $approvedEvent = AuditEvent::query()
            ->where('action', PaymentAuditActions::MANUAL_VERIFICATION)
            ->where('subject_id', $approved->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($approvedEvent);
        $this->assertSame(AuditOutcome::Allowed->value, $approvedEvent->outcome);
        $this->assertSame('Proof matched provider statement', $approvedEvent->reason);

        $rejected = $this->submittedVerification();

        (new VerifyManualPayment)->verify(
            verification: $rejected,
            decision: PaymentVerificationDecision::Reject,
            reason: 'Amount does not match',
            actorRef: 9,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $rejectedEvent = AuditEvent::query()
            ->where('action', PaymentAuditActions::MANUAL_VERIFICATION)
            ->where('subject_id', $rejected->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($rejectedEvent);
        $this->assertSame(AuditOutcome::Denied->value, $rejectedEvent->outcome);
    }

    public function test_a_blank_reason_is_refused_by_audits_own_sensitive_action_check_and_rolls_back(): void
    {
        $verification = $this->submittedVerification();

        try {
            (new VerifyManualPayment)->verify(
                verification: $verification,
                decision: PaymentVerificationDecision::Approve,
                reason: '',
                actorRef: 9,
                actorRole: 'admin',
                source: AuditSource::Panel,
            );

            $this->fail('Expected AuditReasonRequiredException for a blank reason.');
        } catch (AuditReasonRequiredException) {
            $verification->refresh();

            $this->assertSame(
                PaymentVerificationStatus::Submitted,
                $verification->status(),
                'A missing-reason audit failure must roll back the decide() mutation too (Audit::wrap same-transaction guarantee).'
            );
        }
    }

    public function test_a_verification_can_only_be_decided_once(): void
    {
        $verification = $this->submittedVerification();

        (new VerifyManualPayment)->verify(
            verification: $verification,
            decision: PaymentVerificationDecision::Approve,
            reason: 'First decision',
            actorRef: 9,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->expectException(PaymentVerificationAlreadyDecidedException::class);

        (new VerifyManualPayment)->verify(
            verification: $verification,
            decision: PaymentVerificationDecision::Reject,
            reason: 'Second decision, must not be allowed',
            actorRef: 9,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );
    }

    /**
     * The concurrency gap IMPORTANT-1 found: two SEPARATE PHP instances of
     * the same row (simulating two concurrent requests — a double-click or
     * a retried form submit on the real route) must not both succeed. The
     * fix re-fetches the row via `lockForUpdate()` inside `Audit::wrap()`'s
     * transaction and decides THAT freshly-locked instance rather than the
     * caller's unlocked instance, so a second `verify()` call sees the
     * already-decided status and throws — instead of silently overwriting
     * the first decision, which is what happened before the fix.
     *
     * SQLite (this suite's default) compiles `lockForUpdate()` to a no-op,
     * the same caveat `task-4-report.md` already documents for
     * `ProcessWebhookEvent`'s own concurrency test. Because the two
     * `verify()` calls here run sequentially (not from two real concurrent
     * connections), this test proves the sequential-correctness half of the
     * fix — the second call's re-fetch sees the first call's already-committed
     * decision and refuses — not true concurrent-request serialization
     * under contention, which requires PostgreSQL and is not exercised
     * locally.
     */
    public function test_two_separately_loaded_instances_cannot_both_decide_the_same_row(): void
    {
        $verification = $this->submittedVerification();

        $firstInstance = PaymentVerification::find($verification->id);
        $secondInstance = PaymentVerification::find($verification->id);

        (new VerifyManualPayment)->verify(
            verification: $firstInstance,
            decision: PaymentVerificationDecision::Approve,
            reason: 'First concurrent request wins',
            actorRef: 9,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        try {
            (new VerifyManualPayment)->verify(
                verification: $secondInstance,
                decision: PaymentVerificationDecision::Reject,
                reason: 'Second concurrent request must be refused, not silently overwrite the first',
                actorRef: 9,
                actorRole: 'admin',
                source: AuditSource::Panel,
            );

            $this->fail('Expected PaymentVerificationAlreadyDecidedException for the second concurrent decision.');
        } catch (PaymentVerificationAlreadyDecidedException) {
            $verification->refresh();

            $this->assertSame(
                PaymentVerificationStatus::Verified,
                $verification->status(),
                'The first decision must stand; the second must not silently overwrite it.'
            );
            $this->assertSame('First concurrent request wins', $verification->decided_reason);
        }
    }

    public function test_it_never_references_payment_sessions_the_journal_or_the_booking_order_aggregate(): void
    {
        $source = $this->withoutComments((string) file_get_contents(base_path('app/Platform/Payment/VerifyManualPayment.php')));

        foreach ([
            'payment_sessions',
            'PaymentSession',
            'SessionState::Paid',
            'Journal::post',
            'OrderWorkflow',
        ] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source, "VerifyManualPayment.php references [{$forbidden}]");
        }
    }

    public function test_deciding_leaves_payment_sessions_untouched(): void
    {
        $verification = $this->submittedVerification();

        (new VerifyManualPayment)->verify(
            verification: $verification,
            decision: PaymentVerificationDecision::Approve,
            reason: 'Proof matched provider statement',
            actorRef: 9,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->assertSame(0, PaymentSession::query()->count());
    }

    // -----------------------------------------------------------------
    // PAY-02: approving links to a real order and marks it paid.
    // -----------------------------------------------------------------

    public function test_approving_marks_the_linked_marketplace_order_paid_with_the_stated_amount(): void
    {
        [$verification, $order] = $this->submittedVerificationForANewOrder();

        (new VerifyManualPayment)->verify(
            verification: $verification,
            decision: PaymentVerificationDecision::Approve,
            reason: 'Matches bank statement',
            actorRef: 9,
            actorRole: 'finance',
            source: AuditSource::Panel,
        );

        $order->refresh();
        $this->assertSame(PaymentState::DIBAYAR, $order->payment_state);

        $paymentStateChanged = AuditEvent::query()
            ->where('action', 'MARKETPLACE_ORDER_PAYMENT_STATE_CHANGED')
            ->where('subject_id', $order->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($paymentStateChanged);
    }

    public function test_an_amount_mismatch_refuses_the_whole_approval_and_rolls_back(): void
    {
        [$verification, $order] = $this->submittedVerificationForANewOrder(statedAmountMinor: self::TOTAL_MINOR - 1);

        try {
            (new VerifyManualPayment)->verify(
                verification: $verification,
                decision: PaymentVerificationDecision::Approve,
                reason: 'Looks right',
                actorRef: 9,
                actorRole: 'finance',
                source: AuditSource::Panel,
            );

            $this->fail('Expected MarketplacePaymentAmountMismatchException for a stated amount that does not match the order total.');
        } catch (MarketplacePaymentAmountMismatchException) {
            $verification->refresh();
            $order->refresh();

            $this->assertSame(
                PaymentVerificationStatus::Submitted,
                $verification->status(),
                'An amount mismatch must roll back the decide() mutation too, not just refuse the order transition.'
            );
            $this->assertSame(PaymentState::BELUM_DIBAYAR, $order->payment_state);
            $this->assertSame(
                0,
                AuditEvent::query()->where('action', PaymentAuditActions::MANUAL_VERIFICATION)->count(),
                'No audit row for a decision that never committed.'
            );
        }
    }

    public function test_rejecting_never_touches_the_linked_order(): void
    {
        [$verification, $order] = $this->submittedVerificationForANewOrder();

        (new VerifyManualPayment)->verify(
            verification: $verification,
            decision: PaymentVerificationDecision::Reject,
            reason: 'Reference does not match any transfer we received',
            actorRef: 9,
            actorRole: 'finance',
            source: AuditSource::Panel,
        );

        $order->refresh();
        $this->assertSame(PaymentState::BELUM_DIBAYAR, $order->payment_state);
    }

    public function test_re_approving_an_already_verified_row_does_not_double_apply(): void
    {
        [$verification, $order] = $this->submittedVerificationForANewOrder();

        (new VerifyManualPayment)->verify(
            verification: $verification,
            decision: PaymentVerificationDecision::Approve,
            reason: 'First approval',
            actorRef: 9,
            actorRole: 'finance',
            source: AuditSource::Panel,
        );

        $this->expectException(PaymentVerificationAlreadyDecidedException::class);

        try {
            (new VerifyManualPayment)->verify(
                verification: PaymentVerification::findOrFail($verification->id),
                decision: PaymentVerificationDecision::Approve,
                reason: 'Second approval attempt',
                actorRef: 9,
                actorRole: 'finance',
                source: AuditSource::Panel,
            );
        } finally {
            $this->assertSame(
                1,
                AuditEvent::query()->where('action', 'MARKETPLACE_ORDER_PAYMENT_STATE_CHANGED')->where('subject_id', $order->id)->count(),
                'A second approve attempt on an already-decided row must not mark the order paid a second time.'
            );
        }
    }

    public function test_a_row_missing_order_linkage_cannot_be_approved(): void
    {
        // Simulates a row that predates PAY-02 — created without the new
        // columns, which stayed nullable at the schema level for exactly
        // this reason (see the migration's own doc block).
        $verification = PaymentVerification::createSubmitted([
            'reference' => 'legacy-order-token',
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'TRX-legacy',
            'instructions' => null,
            'submitted_at' => CarbonImmutable::now(),
        ]);

        $this->expectException(PaymentVerificationMissingLinkageException::class);

        (new VerifyManualPayment)->verify(
            verification: $verification,
            decision: PaymentVerificationDecision::Approve,
            reason: 'Trying anyway',
            actorRef: 9,
            actorRole: 'finance',
            source: AuditSource::Panel,
        );
    }

    public function test_a_row_missing_order_linkage_can_still_be_rejected(): void
    {
        $verification = PaymentVerification::createSubmitted([
            'reference' => 'legacy-order-token',
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'TRX-legacy',
            'instructions' => null,
            'submitted_at' => CarbonImmutable::now(),
        ]);

        (new VerifyManualPayment)->verify(
            verification: $verification,
            decision: PaymentVerificationDecision::Reject,
            reason: 'Cannot be verified: predates order linkage',
            actorRef: 9,
            actorRole: 'finance',
            source: AuditSource::Panel,
        );

        $this->assertSame(PaymentVerificationStatus::Rejected, $verification->fresh()->status());
    }

    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}

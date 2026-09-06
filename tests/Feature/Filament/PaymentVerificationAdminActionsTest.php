<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\PaymentState;
use App\Filament\Admin\Resources\PaymentVerifications\Actions\DecidePaymentVerificationAction;
use App\Filament\Admin\Resources\PaymentVerifications\Actions\RecordPaymentReversalAction;
use App\Filament\Admin\Resources\PaymentVerifications\Pages\ViewPaymentVerification;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FinancialLedger\Actions\VendorPayable;
use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\VendorPayableAssessmentTrigger;
use App\Platform\FinancialLedger\VendorPayableEligibility;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\Payment\Models\PaymentReversal;
use App\Platform\Payment\Models\PaymentVerification;
use App\Platform\Payment\PaymentReversalType;
use App\Platform\Payment\PaymentVerificationDecision;
use App\Platform\Payment\PaymentVerificationStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * FIL-04 remediation — `PaymentVerificationsResource`'s View page decision
 * header actions (`Actions\DecidePaymentVerificationAction`/`Actions\
 * RecordPaymentReversalAction`), exercised through the real Filament panel
 * (`Livewire::test(ViewPaymentVerification::class, ...)->callAction(...)`),
 * never by calling either action class directly — proving the whole chain
 * (route -> Livewire component -> action -> `VerifyManualPayment`/
 * `ReversalService`) genuinely works, the same standard
 * `RenewalOrderResourceTest`/`CertificateAdminTest` already hold this
 * codebase's Filament actions to.
 *
 * The stale-session regression test matches the pattern
 * `fix/batch2a-stepup-authentication` established for `CertificateAdminTest`
 * ('Cabut'/'Ganti' with a stale `ActorSession'): `actingUserWithRole()`
 * always seeds a FRESH session first (so the later 403/redirect can only be
 * the freshness check, never a role failure), then a second
 * `seedActorSession()` call overwrites it as stale.
 */
final class PaymentVerificationAdminActionsTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private const int TOTAL_MINOR = 150_000_00;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function actingUserWithRole(string $role): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, $role);
        $this->actingAs($user);
        $this->seedActorSession($user, CarbonImmutable::now());
        $this->forgetResolvedActorContext();

        return $user;
    }

    /**
     * `updateOrCreate`, not `create`: a test calls this twice for the same
     * user — once via `actingUserWithRole()` (fresh) and again to overwrite
     * it as stale — and `(user_id, session_id)` is uniquely constrained.
     */
    private function seedActorSession(User $user, CarbonImmutable $lastAuthenticatedAt): ActorSession
    {
        return ActorSession::query()->updateOrCreate(
            ['user_id' => $user->id, 'session_id' => 'test-session-'.$user->id],
            ['guard' => 'web', 'last_authenticated_at' => $lastAuthenticatedAt, 'revoked_at' => null],
        );
    }

    /**
     * @return array{0: PaymentVerification, 1: MarketplaceOrder}
     */
    private function submittedVerificationForANewOrder(): array
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
            'amount_minor' => self::TOTAL_MINOR,
            'currency' => 'IDR',
            'payment_method' => 'bank_transfer',
            'payment_reference' => 'TRX-1',
            'instructions' => null,
            'submitted_at' => CarbonImmutable::now(),
        ]);

        return [$verification, $order];
    }

    // =====================================================================
    // Approve / reject through the real panel
    // =====================================================================

    public function test_finance_can_approve_a_manual_payment_verification_through_the_panel(): void
    {
        [$verification, $order] = $this->submittedVerificationForANewOrder();
        $this->actingUserWithRole(ActorRole::FINANCE);

        Livewire::test(ViewPaymentVerification::class, ['record' => $verification->getKey()])
            ->callAction('approve_payment_verification', data: ['reason' => 'Terkonfirmasi sesuai mutasi rekening.'])
            ->assertNotified('Keputusan verifikasi pembayaran dicatat.');

        $this->assertSame(PaymentVerificationStatus::Verified, $verification->fresh()->status());
        $this->assertSame(PaymentState::DIBAYAR, $order->fresh()->payment_state);
        $this->assertDatabaseHas('audit_events', ['action' => 'PAYMENT_MANUAL_VERIFICATION']);
    }

    public function test_finance_can_reject_a_manual_payment_verification_through_the_panel(): void
    {
        [$verification, $order] = $this->submittedVerificationForANewOrder();
        $this->actingUserWithRole(ActorRole::FINANCE);

        Livewire::test(ViewPaymentVerification::class, ['record' => $verification->getKey()])
            ->callAction('reject_payment_verification', data: ['reason' => 'Bukti transfer tidak cocok.'])
            ->assertNotified('Keputusan verifikasi pembayaran dicatat.');

        $this->assertSame(PaymentVerificationStatus::Rejected, $verification->fresh()->status());
        $this->assertSame(PaymentState::BELUM_DIBAYAR, $order->fresh()->payment_state);
        $this->assertDatabaseHas('audit_events', ['action' => 'PAYMENT_MANUAL_VERIFICATION']);
    }

    public function test_operator_cannot_run_the_decide_actions(): void
    {
        [$verification] = $this->submittedVerificationForANewOrder();
        $this->actingUserWithRole(ActorRole::OPERATOR);

        $this->assertFalse(DecidePaymentVerificationAction::make($verification, PaymentVerificationDecision::Approve)->isAuthorized());
        $this->assertFalse(DecidePaymentVerificationAction::make($verification, PaymentVerificationDecision::Reject)->isAuthorized());
    }

    // =====================================================================
    // Stale re-authentication session — regression pattern from
    // fix/batch2a-stepup-authentication's CertificateAdminTest
    // =====================================================================

    public function test_approving_with_a_stale_session_redirects_and_writes_nothing(): void
    {
        [$verification, $order] = $this->submittedVerificationForANewOrder();
        $user = $this->actingUserWithRole(ActorRole::FINANCE);
        $this->seedActorSession($user, CarbonImmutable::now()->subHour());
        $this->forgetResolvedActorContext();

        Livewire::test(ViewPaymentVerification::class, ['record' => $verification->getKey()])
            ->callAction('approve_payment_verification', data: ['reason' => 'Percobaan tanpa sesi segar.'])
            ->assertNotified('Perlu verifikasi ulang')
            ->assertRedirect(route('filament.admin.pages.verifikasi-ulang-kata-sandi'));

        $this->assertSame(PaymentVerificationStatus::Submitted, $verification->fresh()->status());
        $this->assertSame(PaymentState::BELUM_DIBAYAR, $order->fresh()->payment_state);
        $this->assertSame(0, AuditEvent::query()->where('action', 'PAYMENT_MANUAL_VERIFICATION')->count());
    }

    public function test_rejecting_with_a_stale_session_redirects_and_writes_nothing(): void
    {
        [$verification] = $this->submittedVerificationForANewOrder();
        $user = $this->actingUserWithRole(ActorRole::FINANCE);
        $this->seedActorSession($user, CarbonImmutable::now()->subHour());
        $this->forgetResolvedActorContext();

        Livewire::test(ViewPaymentVerification::class, ['record' => $verification->getKey()])
            ->callAction('reject_payment_verification', data: ['reason' => 'Percobaan tanpa sesi segar.'])
            ->assertNotified('Perlu verifikasi ulang')
            ->assertRedirect(route('filament.admin.pages.verifikasi-ulang-kata-sandi'));

        $this->assertSame(PaymentVerificationStatus::Submitted, $verification->fresh()->status());
        $this->assertSame(0, AuditEvent::query()->where('action', 'PAYMENT_MANUAL_VERIFICATION')->count());
    }

    // =====================================================================
    // Refund / chargeback through the real panel
    // =====================================================================

    private function verifiedVerification(): PaymentVerification
    {
        [$verification] = $this->submittedVerificationForANewOrder();
        $this->actingUserWithRole(ActorRole::FINANCE);

        Livewire::test(ViewPaymentVerification::class, ['record' => $verification->getKey()])
            ->callAction('approve_payment_verification', data: ['reason' => 'Terkonfirmasi sesuai mutasi rekening.']);

        return $verification->fresh();
    }

    public function test_finance_can_record_a_refund_against_a_verified_payment_through_the_panel(): void
    {
        $verification = $this->verifiedVerification();
        // Approving already consumed the fresh session's rate-limit-free
        // window but not its freshness; re-seed to be explicit and isolate
        // this action's own behaviour from the prior call's side effects.
        $this->seedActorSession(auth()->user(), CarbonImmutable::now());
        $this->forgetResolvedActorContext();

        Livewire::test(ViewPaymentVerification::class, ['record' => $verification->getKey()])
            ->callAction('record_refund', data: [
                'reference' => $verification->reference,
                'amount_minor' => self::TOTAL_MINOR,
                'reason' => 'Pelanggan membatalkan pesanan setelah pembayaran.',
            ])
            ->assertNotified('Pembalikan pembayaran dicatat.');

        $this->assertDatabaseHas('payment_reversals', [
            'reversal_type' => PaymentReversalType::Refund->value,
            'reference' => $verification->reference,
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'PAYMENT_REFUND']);
    }

    public function test_a_duplicate_reversal_shows_a_friendly_notification_instead_of_failing_hard(): void
    {
        $verification = $this->verifiedVerification();
        $this->seedActorSession(auth()->user(), CarbonImmutable::now());
        $this->forgetResolvedActorContext();

        Livewire::test(ViewPaymentVerification::class, ['record' => $verification->getKey()])
            ->callAction('record_refund', data: [
                'reference' => $verification->reference,
                'reason' => 'Pembalikan pertama.',
            ])
            ->assertNotified('Pembalikan pembayaran dicatat.');

        $this->seedActorSession(auth()->user(), CarbonImmutable::now());
        $this->forgetResolvedActorContext();

        Livewire::test(ViewPaymentVerification::class, ['record' => $verification->getKey()])
            ->callAction('record_refund', data: [
                'reference' => $verification->reference,
                'reason' => 'Percobaan duplikat referensi yang sama.',
            ])
            ->assertNotified('Pembalikan sudah pernah dicatat');

        $this->assertSame(1, PaymentReversal::query()->where('reversal_type', PaymentReversalType::Refund->value)->count());
    }

    public function test_operator_cannot_run_the_reversal_actions(): void
    {
        $verification = $this->verifiedVerification();
        $this->actingUserWithRole(ActorRole::OPERATOR);

        $this->assertFalse(RecordPaymentReversalAction::make($verification, PaymentReversalType::Refund)->isAuthorized());
        $this->assertFalse(RecordPaymentReversalAction::make($verification, PaymentReversalType::Chargeback)->isAuthorized());
    }
}

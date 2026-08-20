<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\Actions\GuardMarketplacePaymentOpening;
use App\Domain\Marketplace\Models\MarketplaceOrder;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\PaymentState;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FinancialLedger\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Unit-level coverage for `GuardMarketplacePaymentOpening`'s four conditions
 * — see that class's own doc block for why it is four, not booking's six,
 * and why it is a marketplace-owned type rather than a reuse of
 * `GuardPaymentSession`'s booking-typed contract.
 */
final class GuardMarketplacePaymentOpeningTest extends TestCase
{
    use RefreshDatabase;

    private const int TOTAL_MINOR = 200_000_00;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'payment.merchant_ref' => 'mk-merchant-dev',
            'payment.badan_usaha_ref' => 'badan-usaha-dev',
        ]);
    }

    private function withPaymentGate(bool $open): void
    {
        $source = new class($open) implements GateRegistrySource
        {
            public function __construct(private readonly bool $open) {}

            public function load(): GateRegistrySnapshot
            {
                return new GateRegistrySnapshot([
                    'G-PAY-01' => GateState::fromRecord('G-PAY-01', open: $this->open),
                ]);
            }
        };

        $this->app->instance(ModeResolver::class, new ModeResolver(new FeatureGateResolver($source)));
    }

    private function makeOrder(
        string $paymentState = PaymentState::BELUM_DIBAYAR,
        int $totalMinor = self::TOTAL_MINOR,
    ): MarketplaceOrder {
        $vendor = Vendor::create(['name' => 'Toko Bunga', 'is_active' => true]);

        return MarketplaceOrder::query()->create([
            'order_number' => 'MKT-GUARD-1',
            'customer_ref' => 'customer:test-1',
            'entity_ref' => 'badan-usaha-marketplace',
            'vendor_id' => $vendor->id,
            'subtotal_minor' => $totalMinor,
            'delivery_fee_minor' => 0,
            'total_minor' => $totalMinor,
            'payment_state' => $paymentState,
            'idempotency_key' => (string) Str::uuid(),
            'placed_at' => CarbonImmutable::now(),
        ]);
    }

    public function test_all_conditions_hold_returns_allowed(): void
    {
        $this->withPaymentGate(open: true);
        $order = $this->makeOrder();

        $result = app(GuardMarketplacePaymentOpening::class)($order, new Money(self::TOTAL_MINOR));

        $this->assertTrue($result->isAllowed());
    }

    public function test_a_closed_gate_denies(): void
    {
        $this->withPaymentGate(open: false);
        $order = $this->makeOrder();

        $result = app(GuardMarketplacePaymentOpening::class)($order, new Money(self::TOTAL_MINOR));

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('Online payment is not currently available', $result->denialReason());
    }

    #[DataProvider('notAwaitingPaymentStates')]
    public function test_an_order_not_in_belum_dibayar_denies(string $state): void
    {
        $this->withPaymentGate(open: true);
        $order = $this->makeOrder($state);

        $result = app(GuardMarketplacePaymentOpening::class)($order, new Money(self::TOTAL_MINOR));

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('not awaiting payment', $result->denialReason());
    }

    /**
     * @return list<array{string}>
     */
    public static function notAwaitingPaymentStates(): array
    {
        return [
            [PaymentState::DIBAYAR],
            [PaymentState::MENUNGGU_VERIFIKASI],
            [PaymentState::GAGAL],
            [PaymentState::DIKEMBALIKAN],
        ];
    }

    public function test_an_amount_below_the_order_total_denies(): void
    {
        $this->withPaymentGate(open: true);
        $order = $this->makeOrder();

        $result = app(GuardMarketplacePaymentOpening::class)($order, new Money(self::TOTAL_MINOR - 1));

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('does not match the order total', $result->denialReason());
    }

    public function test_an_amount_above_the_order_total_denies(): void
    {
        $this->withPaymentGate(open: true);
        $order = $this->makeOrder();

        $result = app(GuardMarketplacePaymentOpening::class)($order, new Money(self::TOTAL_MINOR + 1));

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('does not match the order total', $result->denialReason());
    }

    public function test_an_unbound_merchant_denies(): void
    {
        $this->withPaymentGate(open: true);
        config(['payment.merchant_ref' => '', 'payment.badan_usaha_ref' => '']);
        $order = $this->makeOrder();

        $result = app(GuardMarketplacePaymentOpening::class)($order, new Money(self::TOTAL_MINOR));

        $this->assertTrue($result->isDenied());
        $this->assertStringContainsString('merchant and business-entity binding', $result->denialReason());
    }

    public function test_a_denial_is_audited_with_the_order_as_subject(): void
    {
        $this->withPaymentGate(open: false);
        $order = $this->makeOrder();

        app(GuardMarketplacePaymentOpening::class)($order, new Money(self::TOTAL_MINOR));

        $event = AuditEvent::query()
            ->where('action', 'MARKETPLACE_PAYMENT_OPENING_DENIED')
            ->sole();

        $this->assertSame(AuditOutcome::Denied->value, $event->outcome);
        $this->assertSame('marketplace_order', $event->subject_type);
        $this->assertSame((string) $order->getKey(), $event->subject_id);
    }

    public function test_an_allowed_evaluation_writes_no_audit_event(): void
    {
        $this->withPaymentGate(open: true);
        $order = $this->makeOrder();

        app(GuardMarketplacePaymentOpening::class)($order, new Money(self::TOTAL_MINOR));

        $this->assertSame(0, AuditEvent::query()->where('action', 'MARKETPLACE_PAYMENT_OPENING_DENIED')->count());
    }
}

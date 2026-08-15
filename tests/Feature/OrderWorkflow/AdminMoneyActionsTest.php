<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\OrderWorkflow\Actions\CancelOrder;
use App\Domain\OrderWorkflow\Actions\ExpireOrder;
use App\Domain\OrderWorkflow\Actions\GrantOrderPaymentOpening;
use App\Domain\OrderWorkflow\Actions\ManualPaymentVerification;
use App\Domain\OrderWorkflow\Actions\MarkOrderPaid;
use App\Domain\OrderWorkflow\Actions\RejectOrder;
use App\Domain\OrderWorkflow\Exceptions\PaidAmountDoesNotMatchQuoteException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Actions\AcceptQuote;
use App\Domain\Quotation\Actions\IssueQuote;
use App\Domain\Quotation\Models\Quote;
use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\PriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminMoneyActionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
        ]);
    }

    private function issueAndAcceptQuote(Order $order): Quote
    {
        $definition = ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
        $price = PriceVersion::query()
            ->where('priceable_type', ServiceDefinition::class)
            ->where('priceable_id', $definition->id)
            ->sole();

        $quote = app(IssueQuote::class)(
            $order,
            [
                [
                    'service_definition_id' => $definition->id,
                    'price_version_id' => $price->id,
                    'price_version_number' => $price->version_number,
                    'quantity' => 1,
                    'unit_amount' => (string) $price->amount,
                    'currency' => (string) $price->currency,
                    'fulfillment_owner' => FulfillmentOwner::CEMETERY_OPERATOR,
                ],
            ],
            CarbonImmutable::now()->addDays(30),
            'system',
            'system',
        );
        app(AcceptQuote::class)($quote, 'system');

        return $quote;
    }

    public function test_reject_requires_reason(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);
        $this->expectException(\InvalidArgumentException::class);
        app(RejectOrder::class)($order, 'user:1', 'operator', '');
    }

    public function test_reject_transitions_with_reason(): void
    {
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI);
        app(RejectOrder::class)($order, 'user:1', 'operator', 'Data tidak lengkap');
        $this->assertSame(OrderStatus::DITOLAK, $order->status());
        $this->assertDatabaseHas('audit_events', ['action' => 'DITOLAK']);
    }

    public function test_cancel_and_expire(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);
        app(CancelOrder::class)($order, 'user:1', 'operator');
        $this->assertSame(OrderStatus::DIBATALKAN, $order->status());
        $expiring = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);
        app(ExpireOrder::class)($expiring, 'user:1', 'operator');
        $this->assertSame(OrderStatus::KEDALUWARSA, $expiring->status());
    }

    public function test_grant_payment_opening_creates_order_scope_grant_and_transitions(): void
    {
        $order = $this->makeOrder(OrderStatus::DISETUJUI_PEMESAN);
        app(GrantOrderPaymentOpening::class)($order, 'user:99', 'user:1', 'finance');

        $grant = ScopeAssignment::query()
            ->where('actor_identifier', 'user:99')
            ->where('entity_type', ScopeEntityType::ORDER)
            ->where('entity_id', (string) $order->getKey())
            ->first();
        $this->assertNotNull($grant);
        $this->assertNull($grant->revoked_at);
        $this->assertSame(OrderStatus::MENUNGGU_PEMBAYARAN, $order->status());
        $this->assertDatabaseHas('audit_events', ['action' => 'ORDER_PAYMENT_OPENING_AUTHORIZED']);
    }

    public function test_manual_payment_verification_records_note(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_PEMBAYARAN);
        app(ManualPaymentVerification::class)($order, 'user:1', 'finance', 'Transfer BCA 250000 atas nama UAT');
        $this->assertSame(OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN, $order->status());
        $this->assertDatabaseHas('order_status_events', [
            'order_id' => $order->getKey(),
            'to_status' => 'MENUNGGU_VERIFIKASI_PEMBAYARAN',
            'reason' => 'Transfer BCA 250000 atas nama UAT',
        ]);
        $this->assertDatabaseHas('audit_events', ['action' => 'ORDER_MANUAL_PAYMENT_VERIFICATION_STARTED']);
    }

    public function test_mark_order_paid_reaches_dibayar_and_stamps_source(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN);
        $this->issueAndAcceptQuote($order);

        $paid = app(MarkOrderPaid::class)($order, 'user:1', 'finance');

        $this->assertSame(OrderStatus::DIBAYAR, $paid->status());
        $this->assertSame('manual_verification', $paid->paid_via);
        $this->assertSame('manual:user:1', $paid->paid_source_ref);
        $this->assertDatabaseHas('outbox_events', ['aggregate_type' => 'order']);
    }

    public function test_mark_order_paid_without_quote_throws(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN);
        $this->expectException(PaidAmountDoesNotMatchQuoteException::class);
        app(MarkOrderPaid::class)($order, 'user:1', 'finance');
    }
}

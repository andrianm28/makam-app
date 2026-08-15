<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\Booking\BookingServiceType;
use App\Domain\Booking\Models\BookingDraft;
use App\Domain\OrderWorkflow\Actions\CompleteOrder;
use App\Domain\OrderWorkflow\Actions\IssueOrderQuote;
use App\Domain\OrderWorkflow\Actions\ProcessOrder;
use App\Domain\OrderWorkflow\Actions\RecordBuyerApproval;
use App\Domain\OrderWorkflow\Actions\RequestAvailability;
use App\Domain\OrderWorkflow\Actions\VerifyOrder;
use App\Domain\OrderWorkflow\Exceptions\IllegalOrderTransitionException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Models\Quote;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\ServiceCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminOperatorActionsTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(OrderStatus $status, ?BookingDraft $draft = null): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => $status->value,
            'booking_draft_id' => $draft?->getKey(),
        ]);
    }

    private function makePricedService(): ServiceDefinition
    {
        return ServiceDefinition::findByCode(ServiceCode::GRAVE_DIGGING);
    }

    public function test_verify_order_transitions_to_diverifikasi(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);
        $event = app(VerifyOrder::class)($order, 'user:1', 'operator');
        $this->assertSame(OrderStatus::DIVERIFIKASI->value, $event->to_status);
        $this->assertSame(OrderStatus::DIVERIFIKASI, $order->status());
        $this->assertDatabaseHas('order_status_events', ['order_id' => $order->getKey(), 'to_status' => 'DIVERIFIKASI']);
        $this->assertDatabaseHas('audit_events', ['subject_type' => 'order', 'subject_id' => $order->getKey()]);
        $this->assertDatabaseHas('outbox_events', ['aggregate_type' => 'order', 'aggregate_id' => $order->getKey()]);
    }

    public function test_request_availability_from_diverifikasi(): void
    {
        $order = $this->makeOrder(OrderStatus::DIVERIFIKASI);
        app(RequestAvailability::class)($order, 'user:1', 'operator');
        $this->assertSame(OrderStatus::MENUNGGU_KETERSEDIAAN, $order->status());
    }

    public function test_issue_order_quote_composes_from_draft_and_issues_quote(): void
    {
        $service = $this->makePricedService();
        $draft = BookingDraft::query()->create([
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [['code' => $service->code, 'quantity' => 1]],
            'customer_full_name' => 'UAT Penerima',
        ]);
        $order = $this->makeOrder(OrderStatus::MENUNGGU_KETERSEDIAAN, $draft);

        app(IssueOrderQuote::class)($order, CarbonImmutable::now()->addDays(30), 'user:1', 'operator');

        $quote = Quote::currentFor($order);
        $this->assertNotNull($quote);
        $this->assertSame(OrderStatus::PENAWARAN_TERKIRIM, $order->status());
        $this->assertCount(1, $quote->lines);
    }

    public function test_issue_order_quote_refuses_order_without_draft(): void
    {
        $order = $this->makeOrder(OrderStatus::MENUNGGU_KETERSEDIAAN);
        $this->expectException(\InvalidArgumentException::class);
        app(IssueOrderQuote::class)($order, CarbonImmutable::now()->addDays(30), 'user:1', 'operator');
    }

    public function test_record_buyer_approval_accepts_current_quote_and_transitions(): void
    {
        $service = $this->makePricedService();
        $draft = BookingDraft::query()->create([
            'service_type' => BookingServiceType::NEW_GRAVE,
            'selected_services' => [['code' => $service->code, 'quantity' => 1]],
            'customer_full_name' => 'UAT Penerima',
        ]);
        $order = $this->makeOrder(OrderStatus::MENUNGGU_KETERSEDIAAN, $draft);

        app(IssueOrderQuote::class)($order, CarbonImmutable::now()->addDays(30), 'user:1', 'operator');
        $this->assertSame(OrderStatus::PENAWARAN_TERKIRIM, $order->status());

        app(RecordBuyerApproval::class)($order, 'user:1', 'operator');

        $this->assertSame(OrderStatus::DISETUJUI_PEMESAN, $order->status());
        $quote = Quote::currentFor($order);
        $this->assertNotNull($quote);
        $this->assertTrue($quote->isAcceptedAndUnexpired(CarbonImmutable::now()));
    }

    public function test_record_buyer_approval_without_quote_throws(): void
    {
        $order = $this->makeOrder(OrderStatus::PENAWARAN_TERKIRIM);
        $this->expectException(\InvalidArgumentException::class);
        app(RecordBuyerApproval::class)($order, 'user:1', 'operator');
    }

    public function test_process_and_complete(): void
    {
        $order = $this->makeOrder(OrderStatus::DIBAYAR);
        app(ProcessOrder::class)($order, 'user:1', 'operator');
        $this->assertSame(OrderStatus::DIPROSES, $order->status());
        app(CompleteOrder::class)($order, 'user:1', 'operator');
        $this->assertSame(OrderStatus::SELESAI, $order->status());
    }

    public function test_illegal_edge_from_matrix_is_rejected(): void
    {
        $order = $this->makeOrder(OrderStatus::MASUK);
        $this->expectException(IllegalOrderTransitionException::class);
        app(ProcessOrder::class)($order, 'user:1', 'operator');
    }
}

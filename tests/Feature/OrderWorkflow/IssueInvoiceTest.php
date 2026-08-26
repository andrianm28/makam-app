<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\OrderWorkflow\Actions\IssueInvoice;
use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderInvoice;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\OrderWorkflowAuditActions;
use App\Domain\OrderWorkflow\PaidTrigger;
use App\Domain\OrderWorkflow\PaidTriggerSource;
use App\Domain\OrderWorkflow\ProductType;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FinancialLedger\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `Actions\IssueInvoice` — the single writer of `order_invoices`, closing
 * NOTIF-02's "no invoice/receipt concept exists" gap for the online-payment
 * path. See that Action's own doc block and
 * `App\Domain\OrderWorkflow\Actions\ApplyPaidEffects`'s wiring of it.
 *
 * These tests exercise the Action directly rather than only through
 * `ApplyPaidEffects` — `ApplyPaidEffectsTest` already covers the
 * end-to-end wiring (invoice created, referenced in the outbox payload,
 * one row even on replay); this file pins the Action's own contract in
 * isolation, including a real double-invocation for the same order.
 */
final class IssueInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private const int TOTAL_MINOR = 1_500_000_00;

    public function test_it_issues_an_invoice_with_the_trigger_amount_and_currency(): void
    {
        $order = $this->makeOrder();

        $invoice = app(IssueInvoice::class)($order, $this->trigger());

        self::assertInstanceOf(OrderInvoice::class, $invoice);
        self::assertSame($order->getKey(), $invoice->order_id);
        self::assertSame(self::TOTAL_MINOR, (int) $invoice->amount_minor);
        self::assertSame('IDR', $invoice->currency);
        self::assertStringStartsWith('INV-', $invoice->reference);
        self::assertNotNull($invoice->issued_at);
        self::assertStringContainsString($order->reference, $invoice->summary);
    }

    public function test_it_writes_one_audit_row_for_a_real_issuance(): void
    {
        $order = $this->makeOrder();

        $invoice = app(IssueInvoice::class)($order, $this->trigger());

        $audit = AuditEvent::query()
            ->where('action', OrderWorkflowAuditActions::INVOICE_ISSUED)
            ->where('subject_id', $invoice->getKey())
            ->sole();

        self::assertSame('order_invoice', $audit->subject_type);
        self::assertSame('allowed', $audit->outcome);
    }

    /**
     * Calling the Action twice for the SAME order — the exact "re-processing
     * the same paid event" scenario the task asks to prove — returns the
     * SAME invoice row rather than creating a second one, and writes no
     * second audit row.
     */
    public function test_calling_it_twice_for_the_same_order_returns_the_same_invoice_and_writes_no_second_row(): void
    {
        $order = $this->makeOrder();

        $first = app(IssueInvoice::class)($order, $this->trigger());
        $second = app(IssueInvoice::class)($order, $this->trigger(sourceId: 'evt_replay'));

        self::assertSame($first->getKey(), $second->getKey());
        self::assertSame($first->reference, $second->reference);
        self::assertSame(1, OrderInvoice::query()->where('order_id', $order->getKey())->count());
        self::assertSame(
            1,
            AuditEvent::query()->where('action', OrderWorkflowAuditActions::INVOICE_ISSUED)->count(),
        );
    }

    public function test_the_invoice_is_retrievable_by_its_own_reference(): void
    {
        $order = $this->makeOrder();

        $invoice = app(IssueInvoice::class)($order, $this->trigger());

        $found = OrderInvoice::query()->where('reference', $invoice->reference)->sole();
        self::assertSame($invoice->getKey(), $found->getKey());
    }

    // -----------------------------------------------------------------
    // Fixtures.
    // -----------------------------------------------------------------

    private function trigger(string $sourceId = 'evt_webhook_1'): PaidTrigger
    {
        return new PaidTrigger(
            source: PaidTriggerSource::Webhook,
            sourceId: $sourceId,
            businessKey: "payment:{$sourceId}",
            amount: new Money(self::TOTAL_MINOR),
            currency: 'IDR',
            occurredAt: CarbonImmutable::now(),
            actorRef: $sourceId,
            actorRole: 'provider',
        );
    }

    private function makeOrder(): Order
    {
        $order = Order::query()->create([
            'reference' => 'MK-INV-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
        ]);

        app(RecordOrderStatusChange::class)->initial($order, 'actor:admin-1', 'admin');

        return $order;
    }
}

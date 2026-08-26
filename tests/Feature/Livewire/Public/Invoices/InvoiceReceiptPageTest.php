<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Invoices;

use App\Domain\OrderWorkflow\Actions\ApplyPaidEffects;
use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderInvoice;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\PaidTrigger;
use App\Domain\OrderWorkflow\PaidTriggerSource;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use App\Livewire\Public\Invoices\InvoiceReceiptPage;
use App\Platform\FinancialLedger\Money;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `InvoiceReceiptPage` — `/kwitansi/{reference}`, the public unauthenticated
 * receipt view a `payment.received.v1` "+ invoice" link points at. See that
 * component's own doc block.
 */
final class InvoiceReceiptPageTest extends TestCase
{
    use RefreshDatabase;

    private const int TOTAL_MINOR = 1_500_000_00;

    public function test_the_receipt_page_renders_for_a_real_invoice(): void
    {
        $order = $this->paidOrder();
        $invoice = OrderInvoice::query()->where('order_id', $order->getKey())->sole();

        Livewire::test(InvoiceReceiptPage::class, ['reference' => $invoice->reference])
            ->assertOk()
            ->assertSee($invoice->reference)
            ->assertSee($order->reference)
            ->assertSee('1.500.000');
    }

    /**
     * A real HTTP GET, not just a `Livewire::test()` mount — the route is
     * wired, not only the component. `abort(404)` fires in `mount()` before
     * the view (and its `@vite` asset tags) is ever rendered, so this does
     * not need a built frontend asset manifest — same reasoning the sibling
     * `CertificateStatusPageTest`/`SubscriptionStatusPageTest` 404 routes
     * tests rely on. A full successful GET through `layouts.app` needs a
     * real built Vite manifest (CI builds it; this host deliberately does
     * not — `CLAUDE.md` §Scope note), so the successful-render path is
     * covered via `Livewire::test()` above instead, matching every sibling
     * public-page test in this suite.
     */
    public function test_the_receipt_page_404s_on_an_unknown_reference(): void
    {
        $this->get(route('invoice.show', ['reference' => 'INV-UNKNOWN00']))
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Fixtures — a real order walked to DIBAYAR via ApplyPaidEffects, the
    // same fixture shape `ApplyPaidEffectsTest` uses.
    // -----------------------------------------------------------------

    private function paidOrder(): Order
    {
        $order = Order::query()->create([
            'reference' => 'MK-KWITANSI-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
        ]);

        app(RecordOrderStatusChange::class)->initial($order, 'actor:admin-1', 'admin');

        foreach ([
            OrderStatus::DIVERIFIKASI,
            OrderStatus::MENUNGGU_KETERSEDIAAN,
            OrderStatus::PENAWARAN_TERKIRIM,
            OrderStatus::DISETUJUI_PEMESAN,
            OrderStatus::MENUNGGU_PEMBAYARAN,
        ] as $status) {
            app(RecordOrderStatusChange::class)($order, $status, 'actor:admin-1', 'admin');
        }

        $quote = Quote::query()->create([
            'order_id' => $order->getKey(),
            'version_number' => 1,
            'status' => QuoteStatus::ISSUED->value,
            'total_minor' => self::TOTAL_MINOR,
            'currency' => 'IDR',
            'issued_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addDays(7),
            'issued_by_ref' => 'actor:admin-1',
            'issued_by_role' => 'admin',
        ]);
        $quote->accept(CarbonImmutable::now(), 'actor:admin-1');

        app(ApplyPaidEffects::class)($order, new PaidTrigger(
            source: PaidTriggerSource::Webhook,
            sourceId: 'evt_kwitansi_1',
            businessKey: 'payment:evt_kwitansi_1',
            amount: new Money(self::TOTAL_MINOR),
            currency: 'IDR',
            occurredAt: CarbonImmutable::now(),
            actorRef: 'evt_kwitansi_1',
            actorRole: 'provider',
        ));

        return $order->fresh();
    }
}

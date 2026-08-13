<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\OrderWorkflow\Actions\ApplyPaidEffects;
use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Exceptions\IllegalOrderTransitionException;
use App\Domain\OrderWorkflow\Exceptions\OrderIsGuardedException;
use App\Domain\OrderWorkflow\Exceptions\PaidAmountDoesNotMatchQuoteException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderStatusEvent;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\PaidTrigger;
use App\Domain\OrderWorkflow\PaidTriggerSource;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use App\Platform\FinancialLedger\Money;
use App\Platform\Outbox\Models\OutboxEvent;
use App\Platform\Outbox\OutboxClassification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Task 7 (`task-7-brief.md`) — `ApplyPaidEffects`, the ONLY writer of
 * `DIBAYAR` and of `orders.paid_via` / `orders.paid_source_ref` (AC9).
 *
 * Every order here is created at `MASUK` through the one open write path
 * (`Order::query()->create()`) and then walked forward along real
 * `OrderTransition` edges with `RecordOrderStatusChange`. The status column
 * is never written directly — the model guard forbids it, and a fixture that
 * cheated past the guard would be asserting against a state this codebase
 * cannot actually produce.
 */
final class ApplyPaidEffectsTest extends TestCase
{
    use RefreshDatabase;

    private const TOTAL_MINOR = 1_500_000_00;

    // -----------------------------------------------------------------
    // 1-2. Each trigger path applies the effects exactly once.
    // -----------------------------------------------------------------

    public function test_a_webhook_trigger_applies_paid_effects_once(): void
    {
        $order = $this->orderAwaitingPayment();
        $this->acceptedQuote($order, self::TOTAL_MINOR);

        $returned = app(ApplyPaidEffects::class)($order, $this->webhookTrigger());

        self::assertSame(OrderStatus::DIBAYAR->value, $returned->status);
        self::assertSame(OrderStatus::DIBAYAR->value, $order->fresh()->status);
        self::assertSame(1, $this->paidEventCount($order));

        $fresh = $order->fresh();
        self::assertSame('webhook', $fresh->paid_via);
        self::assertSame('evt_webhook_1', $fresh->paid_source_ref);

        self::assertSame(1, $this->paymentReceivedCount($order));

        $outbox = OutboxEvent::query()
            ->where('event_name', 'payment.received.v1')
            ->where('aggregate_id', $order->getKey())
            ->sole();

        self::assertSame(1, $outbox->event_version);
        self::assertSame('order', $outbox->aggregate_type);
        self::assertSame(OutboxClassification::Internal->value, $outbox->classification);
        self::assertSame('paid_effects:payment:evt_webhook_1', $outbox->idempotency_key);
        // Order-insensitive: `payload` is a jsonb column and PostgreSQL is free
        // to serialize key order however it likes (SQLite happened to preserve
        // insertion order, PostgreSQL does not — Task 10). The contract is the
        // payload CONTENT, never its storage ordering.
        $this->assertEqualsCanonicalizing([
            'order_id' => $order->getKey(),
            'trigger_source' => 'webhook',
            'source_id' => 'evt_webhook_1',
            'amount_minor' => self::TOTAL_MINOR,
            'currency' => 'IDR',
        ], $outbox->payload);
    }

    public function test_a_manual_verification_trigger_applies_paid_effects_once(): void
    {
        $order = $this->orderAwaitingVerification();
        $this->acceptedQuote($order, self::TOTAL_MINOR);

        app(ApplyPaidEffects::class)($order, $this->manualTrigger());

        self::assertSame(OrderStatus::DIBAYAR->value, $order->fresh()->status);
        self::assertSame(1, $this->paidEventCount($order));
        self::assertSame('manual_verification', $order->fresh()->paid_via);
        self::assertSame('pv_1', $order->fresh()->paid_source_ref);
        self::assertSame(1, $this->paymentReceivedCount($order));
    }

    // -----------------------------------------------------------------
    // 3-4. Exactly-once, within one path and across the two paths.
    // -----------------------------------------------------------------

    public function test_the_same_webhook_delivered_twice_yields_one_status_event_and_one_outbox_row(): void
    {
        $order = $this->orderAwaitingPayment();
        $this->acceptedQuote($order, self::TOTAL_MINOR);

        app(ApplyPaidEffects::class)($order, $this->webhookTrigger());
        $second = app(ApplyPaidEffects::class)($order->fresh(), $this->webhookTrigger());

        // The redelivery returns the order, it does not throw.
        self::assertSame(OrderStatus::DIBAYAR->value, $second->status);
        self::assertSame($order->getKey(), $second->getKey());

        self::assertSame(1, $this->paidEventCount($order));
        self::assertSame(1, $this->paymentReceivedCount($order));
    }

    public function test_a_manual_verification_followed_by_a_webhook_yields_one_status_event_and_one_outbox_row(): void
    {
        $order = $this->orderAwaitingVerification();
        $this->acceptedQuote($order, self::TOTAL_MINOR);

        app(ApplyPaidEffects::class)($order, $this->manualTrigger());
        $second = app(ApplyPaidEffects::class)($order->fresh(), $this->webhookTrigger());

        self::assertSame(OrderStatus::DIBAYAR->value, $second->status);
        self::assertSame(1, $this->paidEventCount($order));
        self::assertSame(1, $this->paymentReceivedCount($order));

        // The FIRST trigger is the one recorded; the loser never overwrites it.
        self::assertSame('manual_verification', $order->fresh()->paid_via);
        self::assertSame('pv_1', $order->fresh()->paid_source_ref);
    }

    // -----------------------------------------------------------------
    // 5. `paid_via` records which trigger won — the other direction too.
    // -----------------------------------------------------------------

    public function test_paid_via_records_whichever_trigger_won_in_either_direction(): void
    {
        $webhookFirst = $this->orderAwaitingPayment();
        $this->acceptedQuote($webhookFirst, self::TOTAL_MINOR);
        app(ApplyPaidEffects::class)($webhookFirst, $this->webhookTrigger());
        app(ApplyPaidEffects::class)($webhookFirst->fresh(), $this->manualTrigger());

        self::assertSame('webhook', $webhookFirst->fresh()->paid_via);
        self::assertSame('evt_webhook_1', $webhookFirst->fresh()->paid_source_ref);
        self::assertSame(1, $this->paidEventCount($webhookFirst));
        self::assertSame(1, $this->paymentReceivedCount($webhookFirst));

        // Its own provider event and its own verification — a second order
        // never reuses the first order's source ids.
        $manualFirst = $this->orderAwaitingVerification();
        $this->acceptedQuote($manualFirst, self::TOTAL_MINOR);
        app(ApplyPaidEffects::class)($manualFirst, $this->manualTrigger(sourceId: 'pv_2'));
        app(ApplyPaidEffects::class)($manualFirst->fresh(), $this->webhookTrigger(sourceId: 'evt_webhook_2'));

        self::assertSame('manual_verification', $manualFirst->fresh()->paid_via);
        self::assertSame('pv_2', $manualFirst->fresh()->paid_source_ref);
        self::assertSame(1, $this->paidEventCount($manualFirst));
        self::assertSame(1, $this->paymentReceivedCount($manualFirst));
    }

    /**
     * The race path, and the ONLY path on which
     * `order_status_events_paid_once` — not the transition graph — is what
     * rejects the second arrival.
     *
     * A competing writer that has committed its `DIBAYAR` event row but
     * whose `orders.status` update this session has not yet observed is
     * exactly the interleaving the partial unique index exists for. It is
     * reproduced here by committing the event row directly (the same
     * technique `RecordOrderStatusChangeTest::
     * test_at_most_one_paid_event_can_exist_per_order` uses, and the reason
     * `OrderStatusEvent::create()` is deliberately unguarded), because
     * `lockForUpdate()` is a no-op on this suite's SQLite driver and a
     * genuine two-connection race is Task 10's.
     */
    public function test_a_paid_event_committed_by_a_competing_writer_returns_the_same_order(): void
    {
        $order = $this->orderAwaitingPayment();
        $this->acceptedQuote($order, self::TOTAL_MINOR);

        OrderStatusEvent::query()->create([
            'order_id' => $order->getKey(),
            'from_status' => OrderStatus::MENUNGGU_PEMBAYARAN->value,
            'to_status' => OrderStatus::DIBAYAR->value,
            'actor_ref' => 'actor:competing-writer',
            'actor_role' => 'system',
            'occurred_at' => now(),
        ]);

        $returned = app(ApplyPaidEffects::class)($order, $this->webhookTrigger());

        self::assertSame($order->getKey(), $returned->getKey());
        self::assertSame(1, $this->paidEventCount($order));
        self::assertSame(0, $this->paymentReceivedCount($order));
        self::assertNull($order->fresh()->paid_via);
    }

    /**
     * The duplicate swallow must stay narrow. An order that never reached a
     * payable state is an ILLEGAL transition, not a replay, and must still
     * surface as one.
     */
    public function test_a_paid_trigger_on_an_order_that_never_reached_payment_still_throws(): void
    {
        $order = $this->makeOrder();
        $this->acceptedQuote($order, self::TOTAL_MINOR);

        $this->expectException(IllegalOrderTransitionException::class);

        app(ApplyPaidEffects::class)($order, $this->webhookTrigger());
    }

    // -----------------------------------------------------------------
    // 6-7. The amount precondition, and the quote it is measured against.
    // -----------------------------------------------------------------

    public function test_an_amount_one_minor_unit_off_the_quote_total_throws_and_writes_nothing(): void
    {
        $order = $this->orderAwaitingPayment();
        $this->acceptedQuote($order, self::TOTAL_MINOR);

        try {
            app(ApplyPaidEffects::class)(
                $order,
                $this->webhookTrigger(amountMinor: self::TOTAL_MINOR - 1),
            );
            self::fail('Expected PaidAmountDoesNotMatchQuoteException');
        } catch (PaidAmountDoesNotMatchQuoteException) {
            // expected
        }

        $this->assertNothingWasApplied($order);

        // And one minor unit OVER is rejected too, so no `>=`-shaped check
        // can pass this test.
        try {
            app(ApplyPaidEffects::class)(
                $order,
                $this->webhookTrigger(amountMinor: self::TOTAL_MINOR + 1),
            );
            self::fail('Expected PaidAmountDoesNotMatchQuoteException');
        } catch (PaidAmountDoesNotMatchQuoteException) {
            // expected
        }

        $this->assertNothingWasApplied($order);
    }

    public function test_a_currency_that_differs_from_the_quote_throws_and_writes_nothing(): void
    {
        $order = $this->orderAwaitingPayment();
        $this->acceptedQuote($order, self::TOTAL_MINOR);

        $this->expectException(PaidAmountDoesNotMatchQuoteException::class);

        try {
            app(ApplyPaidEffects::class)($order, $this->webhookTrigger(currency: 'USD'));
        } finally {
            $this->assertNothingWasApplied($order);
        }
    }

    public function test_an_order_with_no_quote_at_all_throws_and_writes_nothing(): void
    {
        $order = $this->orderAwaitingPayment();

        $this->expectException(PaidAmountDoesNotMatchQuoteException::class);

        try {
            app(ApplyPaidEffects::class)($order, $this->webhookTrigger());
        } finally {
            $this->assertNothingWasApplied($order);
        }
    }

    public function test_an_unaccepted_quote_throws_and_writes_nothing(): void
    {
        $order = $this->orderAwaitingPayment();
        $this->issuedQuote($order, self::TOTAL_MINOR);

        $this->expectException(PaidAmountDoesNotMatchQuoteException::class);

        try {
            app(ApplyPaidEffects::class)($order, $this->webhookTrigger());
        } finally {
            $this->assertNothingWasApplied($order);
        }
    }

    public function test_an_expired_accepted_quote_throws_and_writes_nothing(): void
    {
        $order = $this->orderAwaitingPayment();
        $quote = $this->acceptedQuote($order, self::TOTAL_MINOR);

        // Expiry is evaluated lazily at guard time, so moving the clock past
        // `expires_at` is enough — no row is rewritten.
        CarbonImmutable::setTestNow($quote->expires_at->addSecond());

        try {
            app(ApplyPaidEffects::class)($order, $this->webhookTrigger());
            self::fail('Expected PaidAmountDoesNotMatchQuoteException');
        } catch (PaidAmountDoesNotMatchQuoteException) {
            // expected
        } finally {
            CarbonImmutable::setTestNow();
        }

        $this->assertNothingWasApplied($order);
    }

    // -----------------------------------------------------------------
    // 8. The second authorized door refuses everything but a DIBAYAR token.
    // -----------------------------------------------------------------

    public function test_stamp_paid_source_refuses_a_non_paid_event_token(): void
    {
        $order = $this->orderAwaitingPayment();

        $token = OrderStatusEvent::query()
            ->where('order_id', $order->getKey())
            ->where('to_status', OrderStatus::MENUNGGU_PEMBAYARAN->value)
            ->sole();

        $this->expectException(OrderIsGuardedException::class);

        try {
            $order->stampPaidSource($token, 'webhook', 'evt_webhook_1');
        } finally {
            self::assertNull($order->fresh()->paid_via);
        }
    }

    public function test_stamp_paid_source_refuses_an_event_belonging_to_another_order(): void
    {
        $mine = $this->orderAwaitingPayment();

        $theirs = $this->orderAwaitingPayment();
        $this->acceptedQuote($theirs, self::TOTAL_MINOR);
        app(ApplyPaidEffects::class)($theirs, $this->webhookTrigger(sourceId: 'evt_webhook_theirs'));

        $theirToken = OrderStatusEvent::query()
            ->where('order_id', $theirs->getKey())
            ->where('to_status', OrderStatus::DIBAYAR->value)
            ->sole();

        $this->expectException(OrderIsGuardedException::class);

        try {
            $mine->stampPaidSource($theirToken, 'webhook', 'evt_webhook_1');
        } finally {
            self::assertNull($mine->fresh()->paid_via);
        }
    }

    public function test_stamp_paid_source_refuses_an_unsaved_event(): void
    {
        $order = $this->orderAwaitingPayment();

        $unsaved = new OrderStatusEvent([
            'order_id' => $order->getKey(),
            'from_status' => OrderStatus::MENUNGGU_PEMBAYARAN->value,
            'to_status' => OrderStatus::DIBAYAR->value,
            'actor_ref' => 'actor:forged',
            'actor_role' => 'system',
        ]);

        $this->expectException(OrderIsGuardedException::class);

        try {
            $order->stampPaidSource($unsaved, 'webhook', 'evt_webhook_1');
        } finally {
            self::assertNull($order->fresh()->paid_via);
        }
    }

    // -----------------------------------------------------------------
    // 9. The new door must not have opened the general update path.
    // -----------------------------------------------------------------

    public function test_the_general_update_path_is_still_guarded_for_the_paid_columns(): void
    {
        $order = $this->orderAwaitingPayment();

        try {
            $order->update(['paid_via' => 'x']);
            self::fail('Expected OrderIsGuardedException');
        } catch (OrderIsGuardedException) {
            // expected
        }

        // And direct assignment + save, which routes through performUpdate().
        try {
            $order->paid_source_ref = 'x';
            $order->save();
            self::fail('Expected OrderIsGuardedException');
        } catch (OrderIsGuardedException) {
            // expected
        }

        self::assertNull($order->fresh()->paid_via);
        self::assertNull($order->fresh()->paid_source_ref);
    }

    // -----------------------------------------------------------------
    // The value object's own preconditions.
    // -----------------------------------------------------------------

    public function test_a_paid_trigger_requires_a_source_prefixed_business_key_and_a_non_blank_actor(): void
    {
        $rejected = 0;
        $overrides = [
            ['businessKey' => 'no-prefix'],
            ['businessKey' => ':leading'],
            ['actorRef' => ' '],
            ['actorRole' => ''],
        ];

        foreach ($overrides as $override) {
            try {
                $this->webhookTrigger(...$override);
                self::fail('Expected InvalidArgumentException for '.json_encode($override));
            } catch (InvalidArgumentException) {
                $rejected++;
            }
        }

        self::assertSame(count($overrides), $rejected);

        // The valid shape still constructs, so the check above cannot be
        // satisfied by a constructor that simply always throws.
        self::assertSame('payment:evt_webhook_1', $this->webhookTrigger()->businessKey);
    }

    // -----------------------------------------------------------------
    // Fixtures.
    // -----------------------------------------------------------------

    /**
     * A provider event id is globally unique in reality — one provider event
     * belongs to one payment on one order — so `$sourceId` is a parameter
     * rather than a constant: a test needing two orders gives each its own
     * event, instead of leaning on a cross-order key collision it never
     * meant to assert.
     */
    private function webhookTrigger(
        int $amountMinor = self::TOTAL_MINOR,
        string $currency = 'IDR',
        string $sourceId = 'evt_webhook_1',
        ?string $businessKey = null,
        ?string $actorRef = null,
        string $actorRole = 'provider',
    ): PaidTrigger {
        return new PaidTrigger(
            source: PaidTriggerSource::Webhook,
            sourceId: $sourceId,
            businessKey: $businessKey ?? "payment:{$sourceId}",
            amount: new Money($amountMinor),
            currency: $currency,
            // A provider holds no credential of ours, so the most specific
            // true statement about the actor is the provider event id.
            actorRef: $actorRef ?? $sourceId,
            occurredAt: CarbonImmutable::now(),
            actorRole: $actorRole,
        );
    }

    private function manualTrigger(
        int $amountMinor = self::TOTAL_MINOR,
        string $sourceId = 'pv_1',
    ): PaidTrigger {
        return new PaidTrigger(
            source: PaidTriggerSource::ManualVerification,
            sourceId: $sourceId,
            businessKey: "manual_verify:{$sourceId}",
            amount: new Money($amountMinor),
            currency: 'IDR',
            occurredAt: CarbonImmutable::now(),
            actorRef: 'actor:finance-1',
            actorRole: 'finance',
        );
    }

    private function makeOrder(): Order
    {
        $order = Order::query()->create([
            'reference' => 'MK-PAID-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
        ]);

        app(RecordOrderStatusChange::class)->initial($order, 'actor:admin-1', 'admin');

        return $order;
    }

    /**
     * Walk a real `OrderTransition` path to the status named. `DIBAYAR` has
     * exactly two legal predecessors — `MENUNGGU_PEMBAYARAN` (the online
     * path) and `MENUNGGU_VERIFIKASI_PEMBAYARAN` (the manual one) — and both
     * are reached the long way, through the graph.
     */
    private function walkTo(Order $order, OrderStatus $target): Order
    {
        $chain = [
            OrderStatus::DIVERIFIKASI,
            OrderStatus::MENUNGGU_KETERSEDIAAN,
            OrderStatus::PENAWARAN_TERKIRIM,
            OrderStatus::DISETUJUI_PEMESAN,
            OrderStatus::MENUNGGU_PEMBAYARAN,
            OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN,
        ];

        foreach ($chain as $status) {
            app(RecordOrderStatusChange::class)($order, $status, 'actor:admin-1', 'admin');

            if ($status === $target) {
                break;
            }
        }

        return $order;
    }

    private function orderAwaitingPayment(): Order
    {
        return $this->walkTo($this->makeOrder(), OrderStatus::MENUNGGU_PEMBAYARAN);
    }

    private function orderAwaitingVerification(): Order
    {
        return $this->walkTo($this->makeOrder(), OrderStatus::MENUNGGU_VERIFIKASI_PEMBAYARAN);
    }

    private function acceptedQuote(Order $order, int $totalMinor): Quote
    {
        $quote = $this->issuedQuote($order, $totalMinor);

        $quote->accept(CarbonImmutable::now(), 'actor:admin-1');

        return $quote->fresh();
    }

    private function issuedQuote(Order $order, int $totalMinor): Quote
    {
        return Quote::query()->create([
            'order_id' => $order->getKey(),
            'version_number' => 1,
            'status' => QuoteStatus::ISSUED->value,
            'total_minor' => $totalMinor,
            'currency' => 'IDR',
            'issued_at' => CarbonImmutable::now(),
            'expires_at' => CarbonImmutable::now()->addDays(7),
            'issued_by_ref' => 'actor:admin-1',
            'issued_by_role' => 'admin',
        ]);
    }

    private function paidEventCount(Order $order): int
    {
        return OrderStatusEvent::query()
            ->where('order_id', $order->getKey())
            ->where('to_status', OrderStatus::DIBAYAR->value)
            ->count();
    }

    private function paymentReceivedCount(Order $order): int
    {
        return OutboxEvent::query()
            ->where('event_name', 'payment.received.v1')
            ->where('aggregate_id', $order->getKey())
            ->count();
    }

    private function assertNothingWasApplied(Order $order): void
    {
        $fresh = $order->fresh();

        self::assertNotSame(OrderStatus::DIBAYAR->value, $fresh->status);
        self::assertNull($fresh->paid_via);
        self::assertNull($fresh->paid_source_ref);
        self::assertSame(0, $this->paidEventCount($order));
        self::assertSame(0, $this->paymentReceivedCount($order));
    }
}

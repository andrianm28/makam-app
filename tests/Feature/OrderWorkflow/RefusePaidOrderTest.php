<?php

declare(strict_types=1);

namespace Tests\Feature\OrderWorkflow;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Actions\ConfirmPaidOrder;
use App\Domain\OrderWorkflow\Actions\RecordOrderStatusChange;
use App\Domain\OrderWorkflow\Actions\RefusePaidOrder;
use App\Domain\OrderWorkflow\Exceptions\RefusalWithoutRefundObligationException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Domain\PlotReservation\Actions\ReleasePlotReservation;
use App\Domain\PlotReservation\Actions\ReservePlot;
use App\Domain\PlotReservation\Exceptions\PlotReservationOrderAlreadyPaidException;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationAuditActions;
use App\Domain\PlotReservation\PlotReservationState;
use App\Domain\RefundObligation\Actions\OpenRefundObligation;
use App\Domain\RefundObligation\Exceptions\RefundObligationAlreadyOpenException;
use App\Domain\RefundObligation\Models\RefundObligation;
use App\Domain\RefundObligation\RefundObligationAuditActions;
use App\Domain\RefundObligation\RefundObligationStatus;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Stage R1 of `docs/superpowers/plans/2026-09-13-sistem-refund.md` — the
 * single guarded door by which a paid order can be refused.
 *
 * What is being defended: the customer has already paid in full, an admin
 * refuses the order, and the only thing standing between a grieving family
 * and a forgotten debt is that the refusal cannot be recorded without the
 * debt being recorded in the same transaction.
 */
final class RefusePaidOrderTest extends TestCase
{
    use RefreshDatabase;

    private const REASON = 'petak tidak lagi tersedia saat admin meninjau pesanan';

    // ------------------------------------------------------------------
    // The guard itself
    // ------------------------------------------------------------------

    public function test_the_status_cannot_be_written_when_no_refund_obligation_exists(): void
    {
        $order = $this->paidAwaitingConfirmation();

        $this->expectException(RefusalWithoutRefundObligationException::class);

        app(RecordOrderStatusChange::class)(
            $order,
            OrderStatus::DITOLAK_SETELAH_BAYAR,
            'actor:admin-1',
            'admin',
            self::REASON,
        );
    }

    /**
     * The guard must not merely throw — it must leave nothing behind. A
     * refusal that rolled back its status but kept its event row would be a
     * worse outcome than the one the guard prevents.
     */
    public function test_a_refused_write_leaves_the_order_and_its_history_untouched(): void
    {
        $order = $this->paidAwaitingConfirmation();

        try {
            app(RecordOrderStatusChange::class)(
                $order,
                OrderStatus::DITOLAK_SETELAH_BAYAR,
                'actor:admin-1',
                'admin',
                self::REASON,
            );
            self::fail('The refusal should have been rejected.');
        } catch (RefusalWithoutRefundObligationException) {
            // expected
        }

        self::assertSame(OrderStatus::DIBAYAR_MENUNGGU_KONFIRMASI, $order->fresh()->status());
        self::assertDatabaseMissing('order_status_events', [
            'order_id' => $order->getKey(),
            'to_status' => OrderStatus::DITOLAK_SETELAH_BAYAR->value,
        ]);
        self::assertSame(0, AuditEvent::query()->where('action', OrderStatus::DITOLAK_SETELAH_BAYAR->value)->count());
    }

    /**
     * The message must tell a caller what to do instead, not merely that they
     * were wrong — the door it names is the only one that opens.
     */
    public function test_the_refusal_message_names_the_action_that_would_work(): void
    {
        $order = $this->paidAwaitingConfirmation();

        try {
            app(RecordOrderStatusChange::class)(
                $order,
                OrderStatus::DITOLAK_SETELAH_BAYAR,
                'actor:admin-1',
                'admin',
                self::REASON,
            );
            self::fail('The refusal should have been rejected.');
        } catch (RefusalWithoutRefundObligationException $exception) {
            self::assertStringContainsString('RefusePaidOrder', $exception->getMessage());
        }
    }

    /**
     * The precondition is satisfied by DATA, not by the caller's identity. An
     * obligation opened by any path — here, directly — is enough, which is
     * what makes it an invariant rather than a convention about who calls
     * whom.
     */
    public function test_the_status_can_be_written_once_the_debt_exists(): void
    {
        $order = $this->paidAwaitingConfirmation();
        $this->openObligation($order);

        app(RecordOrderStatusChange::class)(
            $order,
            OrderStatus::DITOLAK_SETELAH_BAYAR,
            'actor:admin-1',
            'admin',
            self::REASON,
        );

        self::assertSame(OrderStatus::DITOLAK_SETELAH_BAYAR, $order->fresh()->status());
    }

    // ------------------------------------------------------------------
    // The door
    // ------------------------------------------------------------------

    public function test_refusing_a_paid_order_records_the_debt_and_the_refusal_together(): void
    {
        $order = $this->paidAwaitingConfirmation();

        app(RefusePaidOrder::class)->handle(
            order: $order,
            refundAmountMinor: 3_750_000_00,
            currency: 'IDR',
            paymentSessionId: null,
            reason: self::REASON,
            actorRef: 'actor:admin-1',
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        self::assertSame(OrderStatus::DITOLAK_SETELAH_BAYAR, $order->fresh()->status());

        $obligation = RefundObligation::query()->where('order_id', $order->getKey())->sole();
        self::assertSame(RefundObligationStatus::TERUTANG, $obligation->status);
        self::assertSame(3_750_000_00, $obligation->amount_minor);
        self::assertSame('IDR', $obligation->currency);
        self::assertSame(self::REASON, $obligation->opened_reason);
    }

    /**
     * Two audit events, not one and not three: opening a debt and refusing an
     * order are two different things that happened, and a reader of the trail
     * needs both. Both are on `SensitiveActions::ACTIONS`, so both carry the
     * mandatory reason.
     */
    public function test_the_refusal_writes_both_audit_events_with_the_stated_reason(): void
    {
        $order = $this->paidAwaitingConfirmation();

        app(RefusePaidOrder::class)->handle(
            order: $order,
            refundAmountMinor: 1_000_000_00,
            currency: 'IDR',
            paymentSessionId: null,
            reason: self::REASON,
            actorRef: 'actor:admin-1',
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $refusal = AuditEvent::query()
            ->where('action', OrderStatus::DITOLAK_SETELAH_BAYAR->value)
            ->sole();
        self::assertSame(self::REASON, $refusal->reason);
        self::assertSame('order', $refusal->subject_type);

        $opened = AuditEvent::query()
            ->where('action', RefundObligationAuditActions::OPENED)
            ->sole();
        self::assertSame(self::REASON, $opened->reason);
        self::assertSame('refund_obligation', $opened->subject_type);
    }

    /**
     * A refusal is the one transition on this branch that the system will not
     * record without being told why — it is the only answer available to the
     * customer whose payment is being sent back.
     */
    public function test_a_blank_reason_is_refused_before_anything_is_written(): void
    {
        $order = $this->paidAwaitingConfirmation();

        try {
            app(RefusePaidOrder::class)->handle(
                order: $order,
                refundAmountMinor: 1_000_000_00,
                currency: 'IDR',
                paymentSessionId: null,
                reason: '   ',
                actorRef: 'actor:admin-1',
                actorRole: 'admin',
                source: AuditSource::Panel,
            );
            self::fail('A blank reason should have been refused.');
        } catch (InvalidArgumentException) {
            // expected
        }

        self::assertSame(OrderStatus::DIBAYAR_MENUNGGU_KONFIRMASI, $order->fresh()->status());
        self::assertDatabaseCount('refund_obligations', 0);
    }

    /**
     * `refund_obligations.order_id` is UNIQUE, so a second refusal cannot
     * open a second debt. The exception is deliberately not softened: an
     * order with an existing debt has already been refused.
     */
    public function test_refusing_the_same_order_twice_is_rejected_and_changes_nothing(): void
    {
        $order = $this->paidAwaitingConfirmation();

        app(RefusePaidOrder::class)->handle(
            order: $order,
            refundAmountMinor: 500_000_00,
            currency: 'IDR',
            paymentSessionId: null,
            reason: self::REASON,
            actorRef: 'actor:admin-1',
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->expectException(RefundObligationAlreadyOpenException::class);

        app(RefusePaidOrder::class)->handle(
            order: $order->fresh(),
            refundAmountMinor: 999_999_00,
            currency: 'IDR',
            paymentSessionId: null,
            reason: 'mencoba menolak untuk kedua kalinya',
            actorRef: 'actor:admin-2',
            actorRole: 'admin',
            source: AuditSource::Panel,
        );
    }

    /**
     * Ordering is enforced by the guard, not by convention. Doing the two
     * steps the other way round — status first, debt second — is exactly what
     * the guard exists to stop, so it must fail rather than produce a subtly
     * different audit trail.
     */
    public function test_doing_the_two_steps_in_the_wrong_order_fails_on_the_guard(): void
    {
        $order = $this->paidAwaitingConfirmation();

        $this->expectException(RefusalWithoutRefundObligationException::class);

        DB::transaction(function () use ($order): void {
            app(RecordOrderStatusChange::class)(
                $order,
                OrderStatus::DITOLAK_SETELAH_BAYAR,
                'actor:admin-1',
                'admin',
                self::REASON,
            );

            $this->openObligation($order);
        });
    }

    // ------------------------------------------------------------------
    // The plot comes back
    // ------------------------------------------------------------------

    /**
     * A refused paid order must return its plot to inventory exactly as a
     * refused unpaid one does. Because `DITOLAK_SETELAH_BAYAR` answers `true`
     * to `isPaidOrLater()`, that release only happens if
     * `RecordOrderStatusChange` passes `overridePaidOrder: true` — without it
     * DOM-08's guard throws and the whole refusal rolls back, stranding the
     * plot forever.
     */
    public function test_refusing_a_paid_order_returns_its_plot_to_inventory(): void
    {
        $plot = $this->makePlot();
        $order = $this->paidAwaitingConfirmation();
        (new ReservePlot)($plot, $order, "order:{$order->getKey()}", 'system');

        app(RefusePaidOrder::class)->handle(
            order: $order,
            refundAmountMinor: 2_000_000_00,
            currency: 'IDR',
            paymentSessionId: null,
            reason: self::REASON,
            actorRef: 'actor:admin-1',
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $head = PlotReservation::query()
            ->where('plot_id', $plot->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        self::assertSame(PlotReservationState::RELEASED, $head->state);
        self::assertSame(PlotState::AVAILABLE, $plot->fresh()->plot_state);
    }

    /**
     * DOM-08 again, from the audit side: returning a PAID customer's plot to
     * inventory must be recorded under the distinct override action, never
     * under the routine one. Auditing it as a routine release would hide a
     * money-adjacent decision among ordinary operator work.
     */
    public function test_the_plot_release_is_audited_as_a_paid_order_override(): void
    {
        $plot = $this->makePlot();
        $order = $this->paidAwaitingConfirmation();
        (new ReservePlot)($plot, $order, "order:{$order->getKey()}", 'system');

        app(RefusePaidOrder::class)->handle(
            order: $order,
            refundAmountMinor: 2_000_000_00,
            currency: 'IDR',
            paymentSessionId: null,
            reason: self::REASON,
            actorRef: 'actor:admin-1',
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        self::assertSame(1, AuditEvent::query()
            ->where('action', PlotReservationAuditActions::PLOT_RESERVATION_RELEASED_PAID_ORDER_OVERRIDE)
            ->count());
        self::assertSame(0, AuditEvent::query()
            ->where('action', PlotReservationAuditActions::PLOT_RESERVATION_RELEASED)
            ->count());
    }

    /**
     * DOM-08's protection, asserted where it actually bites rather than on
     * the closed list that backs it.
     *
     * An order sitting at `DIBAYAR_MENUNGGU_KONFIRMASI` has the customer's
     * money. Releasing its plot hold without the explicit paid-order override
     * must be refused — that refusal is what stops an operator quietly
     * returning a paid-for plot to available inventory, and it exists ONLY
     * because `isPaidOrLater()` answers `true` for this status. Drop the
     * status from that list and this test is what notices: the release
     * silently succeeds instead of throwing.
     */
    public function test_a_paid_but_unconfirmed_order_still_guards_its_plot_hold(): void
    {
        $plot = $this->makePlot();
        $order = $this->paidAwaitingConfirmation();
        $reservation = (new ReservePlot)($plot, $order, "order:{$order->getKey()}", 'system');

        $this->expectException(PlotReservationOrderAlreadyPaidException::class);

        app(ReleasePlotReservation::class)(
            $reservation,
            'actor:operator-1',
            'cemetery_operator',
            'operator mencoba melepas petak yang sudah dibayar',
            AuditSource::Panel,
        );
    }

    // ------------------------------------------------------------------
    // The acceptance half
    // ------------------------------------------------------------------

    public function test_confirming_a_paid_order_moves_it_forward_without_a_debt(): void
    {
        $order = $this->paidAwaitingConfirmation();

        app(ConfirmPaidOrder::class)($order, 'actor:admin-1', 'admin');

        self::assertSame(OrderStatus::DIKONFIRMASI, $order->fresh()->status());
        self::assertDatabaseCount('refund_obligations', 0);
    }

    /**
     * A confirmed order keeps its plot. The release branch is keyed on the
     * terminal non-completed statuses, and acceptance is not one of them.
     */
    public function test_confirming_a_paid_order_keeps_its_plot_reserved(): void
    {
        $plot = $this->makePlot();
        $order = $this->paidAwaitingConfirmation();
        (new ReservePlot)($plot, $order, "order:{$order->getKey()}", 'system');

        app(ConfirmPaidOrder::class)($order, 'actor:admin-1', 'admin');

        $head = PlotReservation::query()
            ->where('plot_id', $plot->getKey())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        self::assertSame(PlotReservationState::HELD, $head->state);
        self::assertSame(PlotState::RESERVED, $plot->fresh()->plot_state);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    /**
     * An order sitting on the pay-first flow's landing state, reached the
     * only way it can be — through the real transition path, so the status
     * always has its `order_status_events` row behind it.
     */
    private function paidAwaitingConfirmation(): Order
    {
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MENUNGGU_PEMBAYARAN->value,
        ]);

        app(RecordOrderStatusChange::class)(
            $order,
            OrderStatus::DIBAYAR_MENUNGGU_KONFIRMASI,
            'actor:system',
            'system',
        );

        return $order;
    }

    private function openObligation(Order $order): RefundObligation
    {
        return app(OpenRefundObligation::class)->handle(
            order: $order,
            amountMinor: 1_000_000_00,
            currency: 'IDR',
            paymentSessionId: null,
            reason: self::REASON,
            actorRef: 'actor:admin-1',
            actorRole: 'admin',
            source: AuditSource::Panel,
        );
    }

    private function makePlot(): GravePlot
    {
        $cemetery = Cemetery::query()->create([
            'type' => CemeteryType::TPU,
            'publication_status' => CemeteryPublicationStatus::DRAFT,
            'name' => 'TPU Uji Coba',
            'slug' => 'tpu-uji-coba-'.Str::lower(Str::random(6)),
            'city' => LaunchCityCode::JAKARTA,
            'address' => 'Jl. Contoh No. 1',
        ]);

        $block = CemeteryBlock::query()->create([
            'cemetery_id' => $cemetery->getKey(),
            'code' => 'BLOK-A',
            'name' => 'Blok A',
            'capacity' => 1,
        ]);

        return GravePlot::query()->create([
            'block_id' => $block->getKey(),
            'slot' => '001',
            'plot_state' => 'available',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\RefundObligation;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\RefundObligation\Actions\OpenRefundObligation;
use App\Domain\RefundObligation\Exceptions\RefundObligationAlreadyOpenException;
use App\Domain\RefundObligation\Models\RefundObligation;
use App\Domain\RefundObligation\RefundObligationStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\SensitiveActions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LogicException;
use Tests\TestCase;

/**
 * Stage R0 of `docs/superpowers/plans/2026-09-13-sistem-refund.md` — the book
 * of debts.
 *
 * What is being defended here: money has already left a customer's hands, an
 * admin has refused their order, and the only thing standing between them and
 * a forgotten debt is this row.
 */
final class OpenRefundObligationTest extends TestCase
{
    use RefreshDatabase;

    public function test_opening_an_obligation_records_the_debt_with_its_deadline(): void
    {
        // Friday — the case where working days and calendar days diverge.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-11 16:30:00'));

        $order = $this->makeOrder();

        $obligation = app(OpenRefundObligation::class)->handle(
            order: $order,
            amountMinor: 2_500_000_00,
            currency: 'IDR',
            paymentSessionId: null,
            reason: 'petak sudah tidak tersedia saat admin meninjau pesanan',
            actorRef: 'actor:admin-1',
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        self::assertSame(RefundObligationStatus::TERUTANG, $obligation->status);
        self::assertSame(2_500_000_00, $obligation->amount_minor);
        self::assertSame((string) $order->getKey(), $obligation->order_id);

        // The deadline is the owner's decision made concrete: 3 working days,
        // so Friday 16:30 is due Wednesday 16:30 — not Monday.
        self::assertSame('2026-09-16 16:30:00', $obligation->due_at->format('Y-m-d H:i:s'));

        CarbonImmutable::setTestNow();
    }

    /**
     * The deadline is stored, not derived. An obligation keeps the deadline it
     * was born with, so changing the rule later cannot silently move the date
     * on debts that already exist — nor un-flag ones already overdue.
     */
    public function test_the_deadline_is_stored_not_recomputed_on_read(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 09:00:00'));

        $obligation = $this->open($this->makeOrder());
        $storedDue = $obligation->due_at;

        // Time moves on; the obligation's deadline does not.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 09:00:00'));

        self::assertSame(
            $storedDue->format('Y-m-d H:i:s'),
            $obligation->fresh()->due_at->format('Y-m-d H:i:s')
        );

        CarbonImmutable::setTestNow();
    }

    public function test_the_opening_is_audited_with_its_mandatory_reason(): void
    {
        $order = $this->makeOrder();

        $obligation = $this->open($order, reason: 'kapasitas pemakaman penuh');

        self::assertDatabaseHas('audit_events', [
            'action' => 'REFUND_OBLIGATION_OPENED',
            'subject_type' => 'refund_obligation',
            'subject_id' => (string) $obligation->getKey(),
            'outcome' => 'allowed',
            'reason' => 'kapasitas pemakaman penuh',
        ]);
    }

    /**
     * Without this, the audit action could be dropped from the list and the
     * suite would stay green while debts started being opened with no stated
     * cause — the one thing a customer asking "why?" can be told.
     */
    public function test_the_audit_action_requires_a_reason(): void
    {
        self::assertTrue(SensitiveActions::requiresReason('REFUND_OBLIGATION_OPENED'));
    }

    public function test_an_obligation_cannot_be_opened_without_a_reason(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->open($this->makeOrder(), reason: '   ');
    }

    /**
     * A blank reason is refused above; this proves the audit layer refuses it
     * independently, so removing the Action's own check does not silently open
     * the door. Two guards, not one.
     */
    public function test_the_audit_layer_refuses_a_reasonless_opening_independently(): void
    {
        $this->expectException(AuditReasonRequiredException::class);

        Audit::record(
            action: 'REFUND_OBLIGATION_OPENED',
            subject: new AuditSubject('refund_obligation', (string) Str::uuid()),
            outcome: AuditOutcome::Allowed,
            actorRef: 'actor:admin-1',
            actorRole: 'admin',
            source: AuditSource::Panel,
            reason: null,
        );
    }

    public function test_a_zero_amount_is_not_a_debt(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(OpenRefundObligation::class)->handle(
            order: $this->makeOrder(),
            amountMinor: 0,
            currency: 'IDR',
            paymentSessionId: null,
            reason: 'apa pun',
            actorRef: 'actor:admin-1',
            actorRole: 'admin',
            source: AuditSource::Panel,
        );
    }

    /**
     * The constraint Stage R1 leans on. Two rejections of one order must not
     * be able to open two debts against it — the customer is owed one refund,
     * and paying twice is as much a failure as paying never.
     */
    public function test_an_order_carries_at_most_one_obligation(): void
    {
        $order = $this->makeOrder();
        $this->open($order);

        $this->expectException(RefundObligationAlreadyOpenException::class);

        $this->open($order, reason: 'alasan kedua yang berbeda');
    }

    /**
     * The plan's binding invariant, as a structural fact rather than a
     * convention: nothing closes an obligation except a recorded execution.
     * There is no status value available for "mark it done".
     */
    public function test_an_obligation_cannot_be_marked_settled_without_an_execution_stamp(): void
    {
        $obligation = $this->open($this->makeOrder());

        $obligation->status = RefundObligationStatus::DIEKSEKUSI;

        $this->expectException(LogicException::class);

        $obligation->save();
    }

    public function test_the_status_machine_does_not_skip_execution(): void
    {
        $obligation = $this->open($this->makeOrder());

        // Even WITH both stamps present, TERUTANG may not jump to
        // TERKONFIRMASI: the transition itself is refused, not merely the
        // missing evidence.
        $obligation->forceFill([
            'executed_at' => CarbonImmutable::now(),
            'confirmed_at' => CarbonImmutable::now(),
            'status' => RefundObligationStatus::TERKONFIRMASI,
        ]);

        $this->expectException(LogicException::class);

        $obligation->save();
    }

    public function test_an_outstanding_obligation_past_its_deadline_is_overdue(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07 09:00:00'));

        $obligation = $this->open($this->makeOrder());

        self::assertFalse($obligation->isOverdue());
        self::assertSame(0, RefundObligation::query()->overdue()->count());

        // One second past the stored deadline.
        CarbonImmutable::setTestNow($obligation->due_at->addSecond());

        self::assertTrue($obligation->fresh()->isOverdue());
        self::assertSame(1, RefundObligation::query()->overdue()->count());

        CarbonImmutable::setTestNow();
    }

    private function open(Order $order, string $reason = 'pesanan ditolak admin'): RefundObligation
    {
        return app(OpenRefundObligation::class)->handle(
            order: $order,
            amountMinor: 1_000_000_00,
            currency: 'IDR',
            paymentSessionId: null,
            reason: $reason,
            actorRef: 'actor:admin-1',
            actorRole: 'admin',
            source: AuditSource::Panel,
        );
    }

    private function makeOrder(): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
        ]);
    }
}

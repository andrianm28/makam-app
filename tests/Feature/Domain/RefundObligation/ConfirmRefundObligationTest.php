<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\RefundObligation;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\RefundObligation\Actions\ConfirmRefundObligation;
use App\Domain\RefundObligation\Actions\ExecuteRefundObligation;
use App\Domain\RefundObligation\Actions\OpenRefundObligation;
use App\Domain\RefundObligation\Exceptions\RefundObligationTransitionNotAllowedException;
use App\Domain\RefundObligation\Models\RefundObligation;
use App\Domain\RefundObligation\RefundObligationStatus;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Audit\SensitiveActions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Stage R2's terminal transition — `DIEKSEKUSI` → `TERKONFIRMASI`.
 *
 * The distinction these tests exist to keep alive: "we sent it" is not "they
 * got it". A manual bank transfer can go to a mistyped account, bounce back
 * days later, or sit unposted over a weekend. Only the confirmation means a
 * grieving family actually has their money back.
 */
final class ConfirmRefundObligationTest extends TestCase
{
    use RefreshDatabase;

    private const AMOUNT_MINOR = 1_750_000_00;

    public function test_confirming_receipt_closes_the_obligation(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 12:00:00'));

        $obligation = $this->executed(executedAt: CarbonImmutable::parse('2026-09-14 09:00:00'));

        $confirmed = $this->confirm($obligation, confirmedAt: CarbonImmutable::parse('2026-09-14 11:30:00'));

        self::assertSame(RefundObligationStatus::TERKONFIRMASI, $confirmed->status);
        self::assertSame('2026-09-14 11:30:00', $confirmed->confirmed_at?->format('Y-m-d H:i:s'));
        self::assertSame('actor:finance-1', $confirmed->confirmed_by_actor_ref);

        // The execution stamps survive untouched — the confirmation adds to
        // the record, it never rewrites what the execution wrote.
        self::assertSame('TRX-55001', $confirmed->execution_reference);
        self::assertSame('refund-execution-evidence/bukti.pdf', $confirmed->execution_evidence_path);

        CarbonImmutable::setTestNow();
    }

    public function test_the_confirmation_is_audited_with_its_mandatory_reason(): void
    {
        $obligation = $this->executed();

        $this->confirm($obligation, reason: 'keluarga mengonfirmasi dana masuk lewat telepon');

        self::assertDatabaseHas('audit_events', [
            'action' => 'REFUND_OBLIGATION_CONFIRMED',
            'subject_type' => 'refund_obligation',
            'subject_id' => (string) $obligation->getKey(),
            'outcome' => 'allowed',
            'reason' => 'keluarga mengonfirmasi dana masuk lewat telepon',
        ]);
    }

    public function test_exactly_one_audit_row_is_written_for_the_confirmation(): void
    {
        $obligation = $this->executed();

        $this->confirm($obligation);

        self::assertSame(
            1,
            AuditEvent::query()
                ->where('action', 'REFUND_OBLIGATION_CONFIRMED')
                ->where('subject_id', (string) $obligation->getKey())
                ->count()
        );
    }

    /**
     * The three events in a debt's life share one subject and one searchable
     * order reference, so an operator asked "what happened to this refund?"
     * gets the whole story from one lookup.
     */
    public function test_the_three_lifecycle_events_share_one_subject(): void
    {
        $obligation = $this->executed();
        $this->confirm($obligation);

        $actions = AuditEvent::query()
            ->where('subject_type', 'refund_obligation')
            ->where('subject_id', (string) $obligation->getKey())
            ->orderBy('occurred_at')
            ->pluck('action')
            ->all();

        self::assertSame(
            [
                'REFUND_OBLIGATION_OPENED',
                'REFUND_OBLIGATION_EXECUTED',
                'REFUND_OBLIGATION_CONFIRMED',
            ],
            $actions
        );
    }

    public function test_the_audit_action_requires_a_reason(): void
    {
        self::assertTrue(SensitiveActions::requiresReason('REFUND_OBLIGATION_CONFIRMED'));
    }

    public function test_the_audit_layer_refuses_a_reasonless_confirmation_independently(): void
    {
        $this->expectException(AuditReasonRequiredException::class);

        Audit::record(
            action: 'REFUND_OBLIGATION_CONFIRMED',
            subject: new AuditSubject('refund_obligation', (string) Str::uuid()),
            outcome: AuditOutcome::Allowed,
            actorRef: 'actor:finance-1',
            actorRole: 'finance',
            source: AuditSource::Panel,
            reason: null,
        );
    }

    // -----------------------------------------------------------------------
    // The invariant, from the other side
    // -----------------------------------------------------------------------

    /**
     * The escape hatch the plan exists to deny: closing a debt by confirming
     * it, with no execution and therefore no evidence beneath it. This is the
     * "admin yang menandai selesai" case in its most tempting form — the
     * queue gets shorter and nobody transferred anything.
     */
    public function test_an_outstanding_obligation_cannot_be_confirmed_without_an_execution(): void
    {
        $obligation = $this->open();

        try {
            $this->confirm($obligation);
            self::fail('Confirming a TERUTANG obligation must be refused.');
        } catch (RefundObligationTransitionNotAllowedException) {
            // expected
        }

        $fresh = $obligation->fresh();
        self::assertNotNull($fresh);
        self::assertSame(RefundObligationStatus::TERUTANG, $fresh->status);
        self::assertNull($fresh->confirmed_at);
        self::assertNull($fresh->executed_at);
        self::assertTrue($fresh->status->isOutstanding());

        self::assertDatabaseMissing('audit_events', [
            'action' => 'REFUND_OBLIGATION_CONFIRMED',
            'subject_id' => (string) $obligation->getKey(),
        ]);
    }

    public function test_a_confirmed_obligation_cannot_be_confirmed_again(): void
    {
        $obligation = $this->executed();
        $this->confirm($obligation);

        $this->expectException(RefundObligationTransitionNotAllowedException::class);

        $this->confirm($obligation->fresh() ?? $obligation);
    }

    public function test_a_stale_instance_cannot_re_confirm_an_obligation(): void
    {
        $obligation = $this->executed();

        $stale = RefundObligation::query()->findOrFail($obligation->getKey());

        $this->confirm($obligation);

        self::assertSame(RefundObligationStatus::DIEKSEKUSI, $stale->status);

        $this->expectException(RefundObligationTransitionNotAllowedException::class);

        $this->confirm($stale);
    }

    // -----------------------------------------------------------------------
    // Dates and reasons
    // -----------------------------------------------------------------------

    /**
     * A confirmation dated before the transfer describes something that did
     * not happen.
     */
    public function test_a_confirmation_cannot_precede_the_execution_it_confirms(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 12:00:00'));

        $obligation = $this->executed(executedAt: CarbonImmutable::parse('2026-09-14 09:00:00'));

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->confirm($obligation, confirmedAt: CarbonImmutable::parse('2026-09-14 08:59:59'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_a_confirmation_cannot_be_dated_in_the_future(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 12:00:00'));

        $obligation = $this->executed();

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->confirm($obligation, confirmedAt: CarbonImmutable::parse('2026-09-14 12:00:01'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_a_confirmation_without_a_reason_is_refused(): void
    {
        $obligation = $this->executed();

        $this->expectException(InvalidArgumentException::class);

        $this->confirm($obligation, reason: '   ');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function confirm(
        RefundObligation $obligation,
        ?CarbonImmutable $confirmedAt = null,
        string $reason = 'mutasi rekening menunjukkan dana sudah diterima',
    ): RefundObligation {
        return app(ConfirmRefundObligation::class)->handle(
            obligation: $obligation,
            confirmedAt: $confirmedAt ?? CarbonImmutable::now(),
            reason: $reason,
            actorRef: 'actor:finance-1',
            actorRole: 'finance',
            source: AuditSource::Panel,
        );
    }

    private function executed(?CarbonImmutable $executedAt = null): RefundObligation
    {
        return app(ExecuteRefundObligation::class)->handle(
            obligation: $this->open(),
            amountMinor: self::AMOUNT_MINOR,
            executedAt: $executedAt ?? CarbonImmutable::now(),
            executionReference: 'TRX-55001',
            evidencePath: 'refund-execution-evidence/bukti.pdf',
            reason: 'transfer manual sudah dikirim',
            actorRef: 'actor:finance-1',
            actorRole: 'finance',
            source: AuditSource::Panel,
        );
    }

    private function open(): RefundObligation
    {
        return app(OpenRefundObligation::class)->handle(
            order: $this->makeOrder(),
            amountMinor: self::AMOUNT_MINOR,
            currency: 'IDR',
            paymentSessionId: null,
            reason: 'pesanan terbayar ditolak admin',
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

<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\RefundObligation;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\RefundObligation\Actions\ConfirmRefundObligation;
use App\Domain\RefundObligation\Actions\ExecuteRefundObligation;
use App\Domain\RefundObligation\Actions\OpenRefundObligation;
use App\Domain\RefundObligation\Exceptions\RefundObligationAmountMismatchException;
use App\Domain\RefundObligation\Exceptions\RefundObligationEvidenceRequiredException;
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
 * Stage R2 of `docs/superpowers/plans/2026-09-13-sistem-refund.md` — manual
 * execution, with evidence.
 *
 * What is being defended here: the system never sees this money move. SumoPod
 * cannot refund, so the transfer happens in an operator's banking app, outside
 * everything this codebase can observe. The row these tests guard is therefore
 * not a record OF the payment — it is the only evidence the payment happened
 * at all, and a grieving family is on the other end of it.
 */
final class ExecuteRefundObligationTest extends TestCase
{
    use RefreshDatabase;

    private const AMOUNT_MINOR = 2_500_000_00;

    public function test_recording_an_execution_stamps_the_transfer_and_its_evidence(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 10:00:00'));

        $obligation = $this->open();

        $executed = $this->execute($obligation, executedAt: CarbonImmutable::parse('2026-09-14 09:15:00'));

        self::assertSame(RefundObligationStatus::DIEKSEKUSI, $executed->status);
        self::assertSame('2026-09-14 09:15:00', $executed->executed_at?->format('Y-m-d H:i:s'));
        self::assertSame('actor:finance-1', $executed->executed_by_actor_ref);
        self::assertSame('TRX-99001', $executed->execution_reference);
        self::assertSame('refund-execution-evidence/bukti.pdf', $executed->execution_evidence_path);

        // Persisted, not merely set on the in-memory instance.
        $fresh = $executed->fresh();
        self::assertNotNull($fresh);
        self::assertSame(RefundObligationStatus::DIEKSEKUSI, $fresh->status);
        self::assertSame('TRX-99001', $fresh->execution_reference);

        CarbonImmutable::setTestNow();
    }

    /**
     * An executed obligation is no longer chasing a deadline. The operator has
     * done the thing the deadline measures; what remains is the bank's.
     */
    public function test_an_executed_obligation_stops_being_outstanding_and_stops_being_overdue(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-01 09:00:00'));

        $obligation = $this->open();
        $this->execute($obligation);

        // Well past the stored deadline.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-01 09:00:00'));

        $fresh = $obligation->fresh();
        self::assertNotNull($fresh);
        self::assertFalse($fresh->status->isOutstanding());
        self::assertFalse($fresh->isOverdue());
        self::assertSame(0, RefundObligation::query()->overdue()->count());

        CarbonImmutable::setTestNow();
    }

    public function test_the_execution_is_audited_with_its_mandatory_reason(): void
    {
        $obligation = $this->open();

        $this->execute($obligation, reason: 'transfer manual BCA ke rekening ahli waris');

        self::assertDatabaseHas('audit_events', [
            'action' => 'REFUND_OBLIGATION_EXECUTED',
            'subject_type' => 'refund_obligation',
            'subject_id' => (string) $obligation->getKey(),
            'outcome' => 'allowed',
            'reason' => 'transfer manual BCA ke rekening ahli waris',
        ]);
    }

    /**
     * Exactly one audit row per execution — not zero (a silent money write)
     * and not two (a trail that double-counts a debt being discharged).
     */
    public function test_exactly_one_audit_row_is_written_for_the_execution(): void
    {
        $obligation = $this->open();

        $this->execute($obligation);

        self::assertSame(
            1,
            AuditEvent::query()
                ->where('action', 'REFUND_OBLIGATION_EXECUTED')
                ->where('subject_id', (string) $obligation->getKey())
                ->count()
        );
    }

    /**
     * The audit payload carries the order reference an operator searches by
     * and the transition — and nothing about the transfer itself. The bank
     * reference, the amount and the evidence path all stay on the row the
     * event points at (`AGENTS.md` §Observability, §Documentation).
     */
    public function test_the_audit_payload_carries_no_transfer_detail(): void
    {
        $obligation = $this->open();
        $this->execute($obligation);

        $event = AuditEvent::query()
            ->where('action', 'REFUND_OBLIGATION_EXECUTED')
            ->where('subject_id', (string) $obligation->getKey())
            ->firstOrFail();

        self::assertSame(
            ['reference_number', 'previous_state', 'new_state'],
            array_keys($event->metadata)
        );
        self::assertSame('TERUTANG', $event->metadata['previous_state']);
        self::assertSame('DIEKSEKUSI', $event->metadata['new_state']);

        $encoded = json_encode($event->metadata, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('TRX-99001', $encoded);
        self::assertStringNotContainsString('refund-execution-evidence', $encoded);
        self::assertStringNotContainsString((string) self::AMOUNT_MINOR, $encoded);
    }

    public function test_the_audit_action_requires_a_reason(): void
    {
        self::assertTrue(SensitiveActions::requiresReason('REFUND_OBLIGATION_EXECUTED'));
    }

    /**
     * Two guards, not one: the Action refuses a blank reason above, and the
     * audit layer refuses it independently, so deleting the Action's own check
     * does not silently open the door.
     */
    public function test_the_audit_layer_refuses_a_reasonless_execution_independently(): void
    {
        $this->expectException(AuditReasonRequiredException::class);

        Audit::record(
            action: 'REFUND_OBLIGATION_EXECUTED',
            subject: new AuditSubject('refund_obligation', (string) Str::uuid()),
            outcome: AuditOutcome::Allowed,
            actorRef: 'actor:finance-1',
            actorRole: 'finance',
            source: AuditSource::Panel,
            reason: null,
        );
    }

    // -----------------------------------------------------------------------
    // The plan's binding invariant
    // -----------------------------------------------------------------------

    /**
     * THE invariant, stated in the plan's §Invarian: *"tidak ada yang menutup
     * kewajiban kecuali eksekusi yang tercatat beserta buktinya."*
     *
     * Not merely "the call throws" — the obligation must be untouched
     * afterwards. A refusal that still advanced the status, or half-wrote the
     * stamps, would satisfy a naive assertion while leaving a debt marked paid
     * with nothing behind it.
     */
    public function test_an_obligation_cannot_be_closed_without_evidence(): void
    {
        $obligation = $this->open();

        try {
            $this->execute($obligation, evidencePath: '   ');
            self::fail('An execution with no evidence must be refused.');
        } catch (RefundObligationEvidenceRequiredException) {
            // expected
        }

        $fresh = $obligation->fresh();
        self::assertNotNull($fresh);
        self::assertSame(RefundObligationStatus::TERUTANG, $fresh->status);
        self::assertNull($fresh->executed_at);
        self::assertNull($fresh->execution_evidence_path);
        self::assertNull($fresh->execution_reference);
        self::assertTrue($fresh->status->isOutstanding());

        // And nothing was audited, because nothing happened.
        self::assertDatabaseMissing('audit_events', [
            'action' => 'REFUND_OBLIGATION_EXECUTED',
            'subject_id' => (string) $obligation->getKey(),
        ]);
    }

    public function test_an_execution_without_a_transfer_reference_is_refused(): void
    {
        $obligation = $this->open();

        $this->expectException(RefundObligationEvidenceRequiredException::class);

        $this->execute($obligation, executionReference: '  ');
    }

    // -----------------------------------------------------------------------
    // Illegal transitions
    // -----------------------------------------------------------------------

    /**
     * The second operator working the same queue. Without the refusal, they
     * would overwrite the first operator's transfer reference and evidence,
     * leaving one real bank transfer with no record and a customer paid twice.
     */
    public function test_an_already_executed_obligation_cannot_be_executed_again(): void
    {
        $obligation = $this->open();
        $this->execute($obligation);

        $this->expectException(RefundObligationTransitionNotAllowedException::class);

        $this->execute($obligation->fresh() ?? $obligation, executionReference: 'TRX-DUPLICATE');
    }

    public function test_a_confirmed_obligation_is_terminal_and_cannot_be_executed(): void
    {
        $obligation = $this->open();
        $this->execute($obligation);

        app(ConfirmRefundObligation::class)->handle(
            obligation: $obligation->fresh() ?? $obligation,
            confirmedAt: CarbonImmutable::now(),
            reason: 'keluarga mengonfirmasi dana diterima',
            actorRef: 'actor:finance-1',
            actorRole: 'finance',
            source: AuditSource::Panel,
        );

        $this->expectException(RefundObligationTransitionNotAllowedException::class);

        $this->execute($obligation->fresh() ?? $obligation);
    }

    /**
     * The refusal is taken against the LOCKED row, not the caller's instance.
     * A stale in-memory copy still reading `TERUTANG` must not be able to talk
     * the Action into a second execution.
     */
    public function test_a_stale_instance_cannot_re_execute_an_obligation(): void
    {
        $obligation = $this->open();

        // Deliberately kept as it was BEFORE the execution below.
        $stale = RefundObligation::query()->findOrFail($obligation->getKey());

        $this->execute($obligation);

        self::assertSame(RefundObligationStatus::TERUTANG, $stale->status);

        $this->expectException(RefundObligationTransitionNotAllowedException::class);

        $this->execute($stale, executionReference: 'TRX-FROM-STALE');
    }

    // -----------------------------------------------------------------------
    // The amount is a control
    // -----------------------------------------------------------------------

    public function test_an_amount_that_is_not_the_debt_is_refused(): void
    {
        $obligation = $this->open();

        $this->expectException(RefundObligationAmountMismatchException::class);

        // One rupiah short — the transposed-digit case the field exists for.
        $this->execute($obligation, amountMinor: self::AMOUNT_MINOR - 100);
    }

    public function test_an_amount_mismatch_leaves_the_obligation_untouched(): void
    {
        $obligation = $this->open();

        try {
            $this->execute($obligation, amountMinor: 1);
        } catch (RefundObligationAmountMismatchException) {
            // expected
        }

        $fresh = $obligation->fresh();
        self::assertNotNull($fresh);
        self::assertSame(RefundObligationStatus::TERUTANG, $fresh->status);
        self::assertNull($fresh->executed_at);
    }

    // -----------------------------------------------------------------------
    // Dates and reasons
    // -----------------------------------------------------------------------

    public function test_an_execution_cannot_be_dated_in_the_future(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-14 10:00:00'));

        $obligation = $this->open();

        $this->expectException(InvalidArgumentException::class);

        try {
            $this->execute($obligation, executedAt: CarbonImmutable::parse('2026-09-14 10:00:01'));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_an_execution_without_a_reason_is_refused(): void
    {
        $obligation = $this->open();

        $this->expectException(InvalidArgumentException::class);

        $this->execute($obligation, reason: '   ');
    }

    // -----------------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------------

    private function execute(
        RefundObligation $obligation,
        ?int $amountMinor = null,
        ?CarbonImmutable $executedAt = null,
        string $executionReference = 'TRX-99001',
        string $evidencePath = 'refund-execution-evidence/bukti.pdf',
        string $reason = 'transfer manual sudah dikirim',
    ): RefundObligation {
        return app(ExecuteRefundObligation::class)->handle(
            obligation: $obligation,
            amountMinor: $amountMinor ?? self::AMOUNT_MINOR,
            executedAt: $executedAt ?? CarbonImmutable::now(),
            executionReference: $executionReference,
            evidencePath: $evidencePath,
            reason: $reason,
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

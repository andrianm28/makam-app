<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Payment\Actions\RecordChargeback;
use App\Platform\Payment\Actions\RecordRefund;
use App\Platform\Payment\Exceptions\PaymentReversalAlreadyRecordedException;
use App\Platform\Payment\Models\PaymentReversal;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentReversalType;
use App\Platform\Payment\ReversalService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `ReversalService` + `Actions\RecordRefund`/`RecordChargeback` — Task 6's
 * safe slice, Wave 1d Append-Correction (`task-6-brief.md`). Proves the
 * reachable pieces named by the ruling: a `payment_reversals` row is
 * recorded exactly once per `(reversal_type, reference)` pair, the
 * mandatory-reason audit is enforced by `Audit::record()`'s own
 * `SensitiveActions` check (not re-implemented here), and behaviourally
 * proves the hard prohibitions by observing every statement the service
 * actually executed: no payment-session, journal, provider or order-
 * aggregate access at all.
 */
final class ReversalServiceTest extends TestCase
{
    use RefreshDatabase;

    private function service(): ReversalService
    {
        return app(ReversalService::class);
    }

    public function test_recording_a_refund_creates_a_reversal_row(): void
    {
        $reversal = $this->service()->record(
            type: PaymentReversalType::Refund,
            reference: 'TRX-refund-1',
            amountMinor: 15_000_00,
            reason: 'Customer cancelled within cooling-off period',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->assertInstanceOf(PaymentReversal::class, $reversal);
        $this->assertSame(PaymentReversalType::Refund, $reversal->reversalType());
        $this->assertSame('TRX-refund-1', $reversal->reference);
        $this->assertSame(15_000_00, $reversal->amount_minor);
        $this->assertSame('Customer cancelled within cooling-off period', $reversal->reason);
        $this->assertSame('7', $reversal->recorded_by_actor_ref);
        $this->assertNotNull($reversal->recorded_at);
    }

    public function test_recording_a_chargeback_creates_a_reversal_row(): void
    {
        $reversal = $this->service()->record(
            type: PaymentReversalType::Chargeback,
            reference: 'TRX-chargeback-1',
            amountMinor: null,
            reason: 'Card issuer disputed the transaction',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->assertSame(PaymentReversalType::Chargeback, $reversal->reversalType());
        $this->assertNull($reversal->amount_minor);
        $this->assertSame('Card issuer disputed the transaction', $reversal->reason);
    }

    public function test_the_refund_audit_event_carries_the_mandatory_reason_and_allowed_outcome(): void
    {
        $reversal = $this->service()->record(
            type: PaymentReversalType::Refund,
            reference: 'TRX-refund-2',
            amountMinor: 5_000_00,
            reason: 'Duplicate charge',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $event = AuditEvent::query()
            ->where('action', PaymentAuditActions::REFUND)
            ->where('subject_id', $reversal->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(AuditOutcome::Allowed->value, $event->outcome);
        $this->assertSame('Duplicate charge', $event->reason);
    }

    public function test_the_chargeback_audit_event_carries_the_mandatory_reason_and_allowed_outcome(): void
    {
        $reversal = $this->service()->record(
            type: PaymentReversalType::Chargeback,
            reference: 'TRX-chargeback-2',
            amountMinor: null,
            reason: 'Unauthorized transaction reported',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $event = AuditEvent::query()
            ->where('action', PaymentAuditActions::CHARGEBACK)
            ->where('subject_id', $reversal->id)
            ->latest('id')
            ->first();

        $this->assertNotNull($event);
        $this->assertSame(AuditOutcome::Allowed->value, $event->outcome);
        $this->assertSame('Unauthorized transaction reported', $event->reason);
    }

    public function test_a_blank_reason_is_refused_by_audits_own_sensitive_action_check_and_rolls_back(): void
    {
        try {
            $this->service()->record(
                type: PaymentReversalType::Refund,
                reference: 'TRX-refund-blank-reason',
                amountMinor: null,
                reason: '',
                actorRef: 7,
                actorRole: 'admin',
                source: AuditSource::Panel,
            );

            $this->fail('Expected AuditReasonRequiredException for a blank reason.');
        } catch (AuditReasonRequiredException) {
            $this->assertSame(
                0,
                PaymentReversal::query()->where('reference', 'TRX-refund-blank-reason')->count(),
                'A missing-reason audit failure must roll back the createRecorded() mutation too (Audit::wrap same-transaction guarantee).'
            );
        }
    }

    /**
     * Fix round 1 (task-6 scoped re-review, MINOR-1) — direct Action-layer
     * coverage of `assertNotBlank()`'s throw path for `RecordRefund`,
     * matching Task 5's own precedent
     * (`SubmitManualPaymentTest::test_a_blank_required_field_is_rejected()`)
     * of testing the Action directly rather than only through HTTP
     * validation (`RecordPaymentReversalRouteTest`).
     */
    public function test_record_refund_rejects_a_blank_reference_directly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RecordRefund)->handle(
            reference: '   ',
            amountMinor: null,
            reason: 'Attempted with a blank reference',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->assertSame(0, PaymentReversal::query()->count());
    }

    /**
     * Same as above, `RecordChargeback` half — fix round 1, MINOR-1.
     */
    public function test_record_chargeback_rejects_a_blank_reference_directly(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new RecordChargeback)->handle(
            reference: '',
            amountMinor: null,
            reason: 'Attempted with a blank reference',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->assertSame(0, PaymentReversal::query()->count());
    }

    public function test_a_second_refund_for_the_same_reference_is_refused(): void
    {
        $this->service()->record(
            type: PaymentReversalType::Refund,
            reference: 'TRX-duplicate',
            amountMinor: null,
            reason: 'First refund',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->expectException(PaymentReversalAlreadyRecordedException::class);

        $this->service()->record(
            type: PaymentReversalType::Refund,
            reference: 'TRX-duplicate',
            amountMinor: null,
            reason: 'Second refund attempt, must not be allowed',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );
    }

    public function test_a_duplicate_attempt_does_not_leave_a_second_row_or_a_second_audit_event(): void
    {
        $this->service()->record(
            type: PaymentReversalType::Chargeback,
            reference: 'TRX-duplicate-2',
            amountMinor: null,
            reason: 'First chargeback',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        try {
            $this->service()->record(
                type: PaymentReversalType::Chargeback,
                reference: 'TRX-duplicate-2',
                amountMinor: null,
                reason: 'Second chargeback attempt, must not be allowed',
                actorRef: 7,
                actorRole: 'admin',
                source: AuditSource::Panel,
            );

            $this->fail('Expected PaymentReversalAlreadyRecordedException.');
        } catch (PaymentReversalAlreadyRecordedException) {
            $this->assertSame(
                1,
                PaymentReversal::query()->where('reversal_type', PaymentReversalType::Chargeback->value)->where('reference', 'TRX-duplicate-2')->count()
            );
            $this->assertSame(
                1,
                AuditEvent::query()->where('action', PaymentAuditActions::CHARGEBACK)->count(),
                'The rolled-back second attempt must not have written its own audit row.'
            );
        }
    }

    public function test_the_uniqueness_scope_is_the_pair_not_the_reference_alone(): void
    {
        // The SAME reference may have both a REFUND and a CHARGEBACK
        // recorded against it — the UNIQUE constraint is on
        // (reversal_type, reference), not reference alone.
        $refund = $this->service()->record(
            type: PaymentReversalType::Refund,
            reference: 'TRX-shared-reference',
            amountMinor: null,
            reason: 'Refund side',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $chargeback = $this->service()->record(
            type: PaymentReversalType::Chargeback,
            reference: 'TRX-shared-reference',
            amountMinor: null,
            reason: 'Chargeback side',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->assertNotSame($refund->id, $chargeback->id);
        $this->assertSame(2, PaymentReversal::query()->where('reference', 'TRX-shared-reference')->count());
    }

    public function test_recording_reversals_leaves_payment_sessions_untouched(): void
    {
        $this->service()->record(
            type: PaymentReversalType::Refund,
            reference: 'TRX-untouched-1',
            amountMinor: null,
            reason: 'Refund',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->service()->record(
            type: PaymentReversalType::Chargeback,
            reference: 'TRX-untouched-2',
            amountMinor: null,
            reason: 'Chargeback',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->assertSame(0, PaymentSession::query()->count());
    }

    /**
     * Replaces a `file_get_contents()` + `assertStringNotContainsString()`
     * scan of the four reversal source files.
     *
     * A source-text grep cannot see indirection: the moment the prohibition
     * is broken through a collaborator, a variable table name, a facade, or
     * a queued job, the grep still passes while the behaviour it was meant
     * to protect is gone. It also breaks spuriously on any rename — the
     * forbidden strings are class and constant names, not behaviour. Worse,
     * two entries in the old list (`Contracts\PaymentProvider`,
     * `PaymentProvider::refund`) name a contract that has never existed in
     * this branch, so those assertions could never fail for any reason.
     *
     * This runs the real service for both reversal types and observes what
     * the database actually saw, which is the claim the ruling cares about:
     * a reversal writes its own row and its own audit event, and never
     * reads or writes payment sessions, the financial journal, or any order
     * aggregate.
     */
    public function test_recording_reversals_touches_only_its_own_tables_and_never_sessions_the_journal_or_an_order(): void
    {
        // A provider-side refund (the plan's dropped `PaymentProvider::
        // refund()`) would have to leave this process as an outbound
        // request; faking the HTTP client lets us assert none was made.
        Http::fake();

        $statements = [];
        DB::listen(function (QueryExecuted $query) use (&$statements): void {
            $statements[] = $query->sql;
        });

        $this->service()->record(
            type: PaymentReversalType::Refund,
            reference: 'TRX-scope-refund',
            amountMinor: 15_000_00,
            reason: 'Customer cancelled within cooling-off period',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        $this->service()->record(
            type: PaymentReversalType::Chargeback,
            reference: 'TRX-scope-chargeback',
            amountMinor: null,
            reason: 'Card issuer disputed the transaction',
            actorRef: 7,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        // Anchor: an empty capture would make every assertion below pass
        // for the wrong reason.
        $this->assertNotEmpty($statements, 'The reversal service executed no queries at all.');

        $written = $this->writtenTables($statements);

        $this->assertSame(
            [],
            array_values(array_diff($written, ['payment_reversals', 'audit_events'])),
            'A reversal may write its own row and its own audit event, nothing else.'
        );
        $this->assertContains('payment_reversals', $written, 'The reversal row itself was never written.');

        foreach ([
            // `payment_sessions` / `PaymentSession` / `SessionState::Paid`.
            'payment_sessions',
            'payment_intents',
            // `Journal::post` / `Journal::postReversal`.
            'journal_batches',
            'journal_entries',
            // `OrderWorkflow` — the booking order aggregate.
            'orders',
            'order_status_events',
            'order_parties',
            'order_documents',
            'order_invoices',
            // `DIBAYAR` — the marketplace payment state a reversal must
            // never reach for.
            'marketplace_orders',
        ] as $forbiddenTable) {
            $this->assertNoStatementTouches($statements, $forbiddenTable);
        }

        Http::assertNothingSent();
    }

    /**
     * The distinct tables written to, sorted. Fails loudly on a write whose
     * target cannot be identified rather than skipping it — an unparsed
     * statement must never be mistaken for a clean run.
     *
     * @param  list<string>  $statements
     * @return list<string>
     */
    private function writtenTables(array $statements): array
    {
        $tables = [];

        foreach ($statements as $sql) {
            if (preg_match('/^\s*(?:insert|update|delete|truncate)\b/i', $sql) !== 1) {
                continue;
            }

            if (preg_match('/^\s*(?:insert\s+into|update|delete\s+from|truncate)\s+"?([A-Za-z0-9_.]+)"?/i', $sql, $matches) !== 1) {
                $this->fail("Could not identify the target table of write statement: {$sql}");
            }

            if (! in_array($matches[1], $tables, true)) {
                $tables[] = $matches[1];
            }
        }

        sort($tables);

        return $tables;
    }

    /**
     * Asserts no captured statement — read or write — names `$table`. The
     * identifier boundaries matter: `orders` must not match inside
     * `marketplace_orders`.
     *
     * @param  list<string>  $statements
     */
    private function assertNoStatementTouches(array $statements, string $table): void
    {
        foreach ($statements as $sql) {
            $this->assertDoesNotMatchRegularExpression(
                '/(?<![A-Za-z0-9_])'.preg_quote($table, '/').'(?![A-Za-z0-9_])/i',
                $sql,
                "A statement referenced the forbidden table [{$table}]: {$sql}"
            );
        }
    }
}

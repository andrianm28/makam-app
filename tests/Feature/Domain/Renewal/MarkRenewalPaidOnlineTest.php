<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Renewal;

use App\Domain\Renewal\Actions\MarkRenewalPaidOnline;
use App\Domain\Renewal\Exceptions\RenewalAlreadySettledException;
use App\Domain\Renewal\Exceptions\RenewalPaymentAmountMismatchException;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalQuote;
use App\Domain\Renewal\RenewalAuditActions;
use App\Domain\Renewal\RenewalStatus;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Outbox\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `MarkRenewalPaidOnline` — Task 2 of
 * `docs/superpowers/plans/2026-08-25-renewal-online-payment.md`. Unit-level
 * coverage of the Action itself (called directly, not through a real
 * webhook); `tests/Feature/Payment/RenewalWebhookSettlementTest.php` covers
 * the real end-to-end webhook-driven path through
 * `ApplyPaymentSettlement::settleRenewal()`.
 */
final class MarkRenewalPaidOnlineTest extends TestCase
{
    use RefreshDatabase;

    private const int AMOUNT_MINOR = 150_000_00;

    private function makeRenewal(string $status = RenewalStatus::MENUNGGU_PEMBAYARAN): Renewal
    {
        $renewal = Renewal::factory()->create(['status' => $status]);

        RenewalQuote::factory()->accepted()->create([
            'renewal_id' => $renewal->id,
            'amount_minor' => self::AMOUNT_MINOR,
        ]);

        return $renewal;
    }

    public function test_a_menunggu_pembayaran_renewal_transitions_to_dibayar_with_settled_at_set(): void
    {
        $renewal = $this->makeRenewal();

        $result = app(MarkRenewalPaidOnline::class)(
            $renewal,
            self::AMOUNT_MINOR,
            'pay_online_1',
            'provider_event:test-1',
        );

        $this->assertSame(RenewalStatus::DIBAYAR, $result->status);
        $this->assertNotNull($result->settled_at);

        $fresh = $renewal->fresh();
        $this->assertSame(RenewalStatus::DIBAYAR, $fresh->status);
        $this->assertNotNull($fresh->settled_at);
        $this->assertTrue($fresh->isSettled());
    }

    /**
     * The idempotency guard — mirrors `MarkRenewalPaidExternally`'s own
     * existing test (`AdminRenewalActionsTest::test_mark_paid_refuses_settled_renewal`):
     * a second settlement attempt against an already-`DIBAYAR` renewal
     * refuses via `RenewalAlreadySettledException`, writes no second audit
     * row and no second outbox row.
     */
    public function test_a_second_invocation_against_an_already_paid_renewal_refuses(): void
    {
        $renewal = $this->makeRenewal();

        app(MarkRenewalPaidOnline::class)($renewal, self::AMOUNT_MINOR, 'pay_online_1', 'provider_event:test-1');

        $this->expectException(RenewalAlreadySettledException::class);

        try {
            app(MarkRenewalPaidOnline::class)(
                $renewal->fresh(),
                self::AMOUNT_MINOR,
                'pay_online_2',
                'provider_event:test-2',
            );
        } finally {
            $this->assertSame(RenewalStatus::DIBAYAR, $renewal->fresh()->status);
            $this->assertSame(1, AuditEvent::query()
                ->where('action', RenewalAuditActions::RENEWAL_PAID_ONLINE)
                ->where('subject_id', (string) $renewal->getKey())
                ->count());
            $this->assertSame(1, OutboxEvent::query()
                ->where('event_name', 'renewal.paid_online.v1')
                ->where('aggregate_id', (string) $renewal->getKey())
                ->count());
        }
    }

    public function test_the_outbox_event_is_recorded_with_the_correct_subject_reference(): void
    {
        $renewal = $this->makeRenewal();

        app(MarkRenewalPaidOnline::class)($renewal, self::AMOUNT_MINOR, 'pay_online_1', 'provider_event:test-1');

        $event = OutboxEvent::query()->where('event_name', 'renewal.paid_online.v1')->sole();

        $this->assertSame('renewal', $event->aggregate_type);
        $this->assertSame((string) $renewal->getKey(), $event->aggregate_id);
        $this->assertSame((string) $renewal->getKey(), $event->payload['renewal_id']);
        $this->assertSame((string) $renewal->grave_record_id, (string) $event->payload['grave_record_id']);

        // `paid_source_ref` matches `MarkCyclePaid`'s own
        // `care.cycle_created.v1` payload convention exactly — permitted in
        // an outbox payload (not on `PayloadClassification::DENYLISTED_KEYS`),
        // unlike an audit row where AC14 keeps it out (see the audit-row
        // test below).
        $this->assertSame('pay_online_1', $event->payload['paid_source_ref']);

        // References only — AC7: no amount in the outbox payload.
        $this->assertArrayNotHasKey('amount_minor', $event->payload);
    }

    public function test_the_audit_row_uses_the_established_webhook_triggered_shape(): void
    {
        $renewal = $this->makeRenewal();

        app(MarkRenewalPaidOnline::class)($renewal, self::AMOUNT_MINOR, 'pay_online_1', 'provider_event:test-1');

        $event = AuditEvent::query()->where('action', RenewalAuditActions::RENEWAL_PAID_ONLINE)->sole();

        $this->assertSame('renewal', $event->subject_type);
        $this->assertSame((string) $renewal->getKey(), $event->subject_id);
        $this->assertSame('allowed', $event->outcome);
        $this->assertSame('provider', $event->actor_role);
        $this->assertSame('provider_event:test-1', (string) $event->actor_ref);
        $this->assertSame('api', $event->source);

        // AC14: no provider payload value may reach an audit row.
        $this->assertStringNotContainsString('pay_online_1', (string) json_encode($event->toArray()));
    }

    /**
     * The amount-match precondition — mirrors `MarkCyclePaid`'s/
     * `MarkMarketplaceOrderPaid`'s own assert-before-any-write shape. A
     * settlement whose amount does not equal the renewal's latest quote must
     * never mark it `DIBAYAR`.
     */
    public function test_a_mismatched_amount_refuses_and_writes_nothing(): void
    {
        $renewal = $this->makeRenewal();

        try {
            app(MarkRenewalPaidOnline::class)(
                $renewal,
                self::AMOUNT_MINOR + 1,
                'pay_online_1',
                'provider_event:test-1',
            );
            $this->fail('Expected RenewalPaymentAmountMismatchException to be thrown.');
        } catch (RenewalPaymentAmountMismatchException) {
            // expected
        }

        $this->assertSame(RenewalStatus::MENUNGGU_PEMBAYARAN, $renewal->fresh()->status);
        $this->assertNull($renewal->fresh()->settled_at);
        $this->assertSame(0, OutboxEvent::query()->where('event_name', 'renewal.paid_online.v1')->count());
        $this->assertSame(0, AuditEvent::query()->where('action', RenewalAuditActions::RENEWAL_PAID_ONLINE)->count());
    }

    /**
     * The amount assert runs even against an already-settled renewal — the
     * same ordering `CyclePaymentAmountMismatchException`'s doc block
     * documents: a mismatched replay is refused loudly, never swallowed by
     * the idempotency guard.
     */
    public function test_a_mismatched_amount_against_an_already_paid_renewal_still_refuses_on_amount(): void
    {
        $renewal = $this->makeRenewal(RenewalStatus::DIBAYAR);

        $this->expectException(RenewalPaymentAmountMismatchException::class);

        app(MarkRenewalPaidOnline::class)(
            $renewal,
            self::AMOUNT_MINOR + 1,
            'pay_online_1',
            'provider_event:test-1',
        );
    }
}

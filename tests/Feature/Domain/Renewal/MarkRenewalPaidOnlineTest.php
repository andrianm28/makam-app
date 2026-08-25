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
     * The idempotency guard — corrected per task-2 review: a second
     * settlement attempt against an already-`DIBAYAR` renewal, for the SAME
     * amount, is a genuinely reachable duplicate-arrival race (see the
     * class's own "Idempotency" doc-block section — `GuardRenewalPaymentOpening`
     * has no check against two payment sessions being opened for the same
     * still-unpaid renewal). It must be SWALLOWED — the same shape
     * `MarkCyclePaid`/`MarkMarketplaceOrderPaid`/`ApplyPaidEffects` use for
     * their own duplicate-arrival cases — not thrown: the second call returns
     * the SAME renewal unchanged, with no second `RENEWAL_PAID_ONLINE` audit
     * row and no second outbox row.
     *
     * Whole-branch review fix wave (25 Aug 2026): the swallow is no longer
     * silent. It now writes exactly ONE `RENEWAL_PAID_ONLINE_DUPLICATE_ARRIVAL`
     * row naming the SECOND provider event's own actor — the durable trace an
     * operator needs to see a real second collection happened, even though
     * neither the renewal row nor the outbox gained a second write.
     */
    public function test_a_second_invocation_with_a_matching_amount_against_an_already_paid_renewal_is_swallowed(): void
    {
        $renewal = $this->makeRenewal();

        $first = app(MarkRenewalPaidOnline::class)($renewal, self::AMOUNT_MINOR, 'pay_online_1', 'provider_event:test-1');
        $settledAtFirst = $first->settled_at;

        $second = app(MarkRenewalPaidOnline::class)(
            $renewal->fresh(),
            self::AMOUNT_MINOR,
            'pay_online_2',
            'provider_event:test-2',
        );

        $this->assertSame(RenewalStatus::DIBAYAR, $second->status);
        $this->assertSame($settledAtFirst?->toIso8601String(), $second->settled_at?->toIso8601String());

        $this->assertSame(1, AuditEvent::query()
            ->where('action', RenewalAuditActions::RENEWAL_PAID_ONLINE)
            ->where('subject_id', (string) $renewal->getKey())
            ->count());
        $this->assertSame(1, OutboxEvent::query()
            ->where('event_name', 'renewal.paid_online.v1')
            ->where('aggregate_id', (string) $renewal->getKey())
            ->count());

        // The first settlement's audit row still names the FIRST provider
        // event actor — the swallowed second call never overwrites it.
        $audit = AuditEvent::query()->where('action', RenewalAuditActions::RENEWAL_PAID_ONLINE)->sole();
        $this->assertSame('provider_event:test-1', (string) $audit->actor_ref);

        // The swallow itself now leaves exactly one trace of its own,
        // naming the SECOND (genuinely different) provider event.
        $duplicateAudit = AuditEvent::query()
            ->where('action', RenewalAuditActions::RENEWAL_PAID_ONLINE_DUPLICATE_ARRIVAL)
            ->where('subject_id', (string) $renewal->getKey())
            ->sole();

        $this->assertSame('denied', $duplicateAudit->outcome);
        $this->assertSame('renewal', $duplicateAudit->subject_type);
        $this->assertSame('provider', $duplicateAudit->actor_role);
        $this->assertSame('provider_event:test-2', (string) $duplicateAudit->actor_ref);
        $this->assertSame('duplicate settlement arrival, no state change', $duplicateAudit->metadata['note'] ?? null);
    }

    /**
     * The no-op above is scoped to exactly the state this call itself
     * produces (`DIBAYAR`) — any OTHER non-open status is still a genuine
     * anomaly and still throws `RenewalAlreadySettledException`, the same
     * scoping `MarkCyclePaid`/`MarkMarketplaceOrderPaid` use. `KEDALUWARSA`
     * is not hypothetical here — `Actions\ExpireRenewal` is a real, live
     * producer, wired to a real Filament admin action.
     *
     * Whole-branch review fix wave (25 Aug 2026): this anomaly used to leave
     * NO audit trace at all (the mutation's own transaction rolled back and
     * took any row written inside it along with it). It must now leave
     * exactly one `RENEWAL_PAID_ONLINE_REFUSED` row — proving that row
     * survives the rollback rather than being written inside the same
     * transaction that throws.
     */
    public function test_a_settlement_attempt_against_a_kedaluwarsa_renewal_still_refuses(): void
    {
        $renewal = $this->makeRenewal(RenewalStatus::KEDALUWARSA);

        try {
            app(MarkRenewalPaidOnline::class)($renewal, self::AMOUNT_MINOR, 'pay_online_1', 'provider_event:test-1');
            $this->fail('Expected RenewalAlreadySettledException to be thrown.');
        } catch (RenewalAlreadySettledException) {
            // expected
        }

        $this->assertSame(RenewalStatus::KEDALUWARSA, $renewal->fresh()->status);
        $this->assertSame(0, OutboxEvent::query()->where('event_name', 'renewal.paid_online.v1')->count());

        $refusedAudit = AuditEvent::query()
            ->where('action', RenewalAuditActions::RENEWAL_PAID_ONLINE_REFUSED)
            ->where('subject_id', (string) $renewal->getKey())
            ->sole();

        $this->assertSame('denied', $refusedAudit->outcome);
        $this->assertSame('renewal', $refusedAudit->subject_type);
        $this->assertSame('provider', $refusedAudit->actor_role);
        $this->assertSame('provider_event:test-1', (string) $refusedAudit->actor_ref);
        $this->assertSame(
            'settlement arrived for a renewal that is neither open nor already paid',
            $refusedAudit->metadata['note'] ?? null,
        );
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

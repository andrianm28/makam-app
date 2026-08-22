<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Payment\Jobs\ProcessProviderEventJob;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentProviders;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Genuine cross-session proof for AC7's idempotency guard
 * (`ReceiveWebhook::resolveDuplicate()`), matching the `pgsql_race`
 * two-connection convention `ReservePlotTwoConnectionTest.php` establishes
 * — see that file's own doc block for the pattern this one replicates.
 *
 * `tests/Feature/Payment/WebhookReceiverTest.php` (800 lines) already
 * proves duplicate-delivery handling exhaustively, but every case there
 * runs sequentially inside ONE PHPUnit-managed connection/transaction —
 * never a genuinely separate database session. This test sends the
 * IDENTICAL signed webhook body through TWO real, separate connections:
 * the first commits a real `provider_events` row; the second's insert then
 * collides on the real unique constraint and is routed through
 * `ReceiveWebhook::resolveDuplicate()`'s `lockForUpdate()` re-read — the
 * exact code path AC7 exists to prove, now exercised across a genuine
 * connection boundary rather than within one process's single connection.
 *
 * `RefreshDatabase` cannot be used here for the same reason
 * `ReservePlotTwoConnectionTest.php` cannot: the fixture (and the first
 * delivery's committed row) must be visible to the second connection's own
 * session, which an outer, never-committed test transaction would hide.
 * The trailing `Artisan::call('migrate:fresh')` is therefore load-bearing,
 * not a nicety — see that file's doc block for the verified in-suite
 * failure this prevents.
 */
final class WebhookReplayTwoConnectionTest extends TestCase
{
    private const string MERCHANT = 'makam-sandbox';

    private const string SECRET = 'whsec_YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFh';

    private const string ENDPOINT = '/api/payments/webhook/'.self::MERCHANT;

    public function test_a_second_connections_identical_delivery_is_recognized_as_a_duplicate_not_a_second_effect(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Cross-connection duplicate resolution is only meaningful on PostgreSQL.');
        }

        config([
            'payment.default' => PaymentProviders::SUMOPOD_SANDBOX,
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.webhook_signing_secrets' => [self::SECRET],
            'payment.providers.'.PaymentProviders::SUMOPOD_SANDBOX.'.webhook_tokens' => [],
            'payment.webhook.allow_shared_token' => false,
            'payment.webhook.merchants' => [self::MERCHANT],
            'payment.webhook.replay_window_seconds' => 300,
        ]);

        Http::preventStrayRequests();
        Http::fake();
        Queue::fake();

        $id = 'msg_race_01';
        $timestamp = (string) CarbonImmutable::now()->getTimestamp();
        $body = json_encode([
            'event_type' => 'payment.completed',
            'data' => [
                'payment_id' => 'pay_race_test',
                'order_id' => 'INV-2026-RACE-01',
                'amount' => 1_500_000,
                'fee' => 10_800,
                'net_amount' => 1_489_200,
                'status' => 'completed',
                'payment_method' => 'QRIS',
                'completed_at' => '2026-08-09T09:59:00+00:00',
            ],
        ], JSON_THROW_ON_ERROR);

        $key = (string) base64_decode(substr(self::SECRET, strlen('whsec_')), true);
        $signature = 'v1,'.base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$body}", $key, true));

        $headers = [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_svix-id' => $id,
            'HTTP_svix-timestamp' => $timestamp,
            'HTTP_svix-signature' => $signature,
        ];

        config(['database.connections.pgsql_race' => config('database.connections.pgsql')]);
        $originalDefault = config('database.default');
        $statuses = [];

        try {
            foreach (['pgsql', 'pgsql_race'] as $connectionName) {
                DB::setDefaultConnection($connectionName);

                $response = $this->call('POST', self::ENDPOINT, [], [], [], $headers, $body);
                $response->assertOk();
                $statuses[] = json_decode((string) $response->getContent(), true, 512, JSON_THROW_ON_ERROR);
            }
        } finally {
            DB::setDefaultConnection($originalDefault);
            DB::purge('pgsql_race');
        }

        // Exactly one row for this provider_event_id, regardless of which
        // of the two connections' deliveries it was — the DB unique
        // constraint on (provider, provider_event_id) is the guarantee,
        // and this asserts its real, structural effect rather than a
        // status string.
        $this->assertSame(
            1,
            ProviderEvent::query()
                ->where('provider', PaymentProviders::SUMOPOD_SANDBOX)
                ->where('provider_event_id', $id)
                ->count(),
        );

        // The weaker row-count assertion above also passes if the second
        // connection's request were wrongly REJECTED (a digest mismatch or
        // header-validation failure) rather than correctly recognized as a
        // duplicate — these assertions prove the real RESOLUTION path
        // instead, matching WebhookReceiverTest's single-connection
        // duplicate test.
        $this->assertSame($statuses[0]['reference'], $statuses[1]['reference']);

        $this->assertSame(
            1,
            AuditEvent::query()->where('action', PaymentAuditActions::WEBHOOK_DUPLICATE)->count(),
        );

        // No `payment_sessions` row exists for this synthetic payload — the
        // same deny-only-guard constraint `WebhookReceiverTest::
        // test_a_correctly_signed_delivery_with_no_payment_session_is_rejected_and_recorded`
        // documents — so the FIRST delivery itself is REJECTED (verified
        // directly: `provider_events.status` = `REJECTED_SESSION`), never
        // reaching `ReceiveWebhook::finishValidation()`'s dispatch call.
        // `ProcessProviderEventJob` is therefore never pushed by either
        // connection, and this still pins a real invariant: a duplicate
        // delivery must never cause a SECOND (or first) spurious dispatch
        // via the exception-handling path. If this test is later extended
        // with a real `PaymentSession` fixture (`WebhookPaidEffectsTest`'s
        // pattern) so the delivery actually validates, this assertion
        // should become `Queue::assertPushed(ProcessProviderEventJob::class, 1)`.
        Queue::assertNotPushed(ProcessProviderEventJob::class);

        // Wipe the committed rows so later RefreshDatabase test classes in
        // this same process start from an empty, migrated schema — see
        // this file's own doc block.
        Artisan::call('migrate:fresh');
    }
}

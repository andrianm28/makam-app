<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\Models\ProviderEvent;
use App\Platform\Payment\PaymentProviders;
use App\Platform\Payment\ProviderEventStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * AC4's negative criterion, and `AGENTS.md` §Domain and financial invariants:
 * "Never mark paid from browser return URL."
 *
 * ADR-0033 lets a hosted checkout be created with `success_return_url` and
 * `cancel_return_url`. Those are the two routes a CUSTOMER'S BROWSER is sent
 * back to — an entirely untrusted channel: the customer controls the URL, the
 * query string, and whether they visit it at all, and an attacker who never
 * paid can visit either one with any parameters they like. The only admissible
 * evidence of payment is a signed provider webhook (`ReceiveWebhook`) or an
 * approved manual verification.
 *
 * These routes therefore render a view and nothing else. The tests below prove
 * that as a DENIAL rather than as an intention: that hitting either route with
 * hostile "I have paid" parameters changes no row in any table this lane owns,
 * and that neither controller so much as references a write.
 *
 * Wave 1b ruling 1b-L3-04 names this criterion "fully testable today", and it
 * is: proving that nothing happens needs no `payment_sessions` row, and none is
 * created here by any mechanism.
 */
final class PaymentReturnRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These are real HTTP requests through the full public layout, which
        // calls `@vite(...)`; CI's `php` job has no frontend build. Same
        // requirement as every other public route test in this repository.
        $this->withoutVite();
    }

    /**
     * The query string a hostile visitor (or a well-meaning provider redirect)
     * would present. Every value is a lie until a webhook says otherwise.
     */
    private const array HOSTILE_QUERY = [
        'status' => 'paid',
        'payment_status' => 'PAID',
        'order_id' => 'order_forged',
        'payment_id' => 'pay_forged',
        'amount' => '150000000',
        'state' => 'DIBAYAR',
    ];

    public function test_the_success_return_route_renders_a_page(): void
    {
        $this->get(route('payments.return', self::HOSTILE_QUERY))
            ->assertOk()
            ->assertSee('Pembayaran', false);
    }

    public function test_the_cancel_return_route_renders_a_page(): void
    {
        $this->get(route('payments.cancel', self::HOSTILE_QUERY))
            ->assertOk()
            ->assertSee('Pembayaran', false);
    }

    public function test_the_success_return_route_reaches_no_state_transition(): void
    {
        $event = $this->validatedProviderEvent();

        $this->get(route('payments.return', self::HOSTILE_QUERY))->assertOk();

        $this->assertNothingChanged($event);
    }

    public function test_the_cancel_return_route_reaches_no_state_transition(): void
    {
        $event = $this->validatedProviderEvent();

        $this->get(route('payments.cancel', self::HOSTILE_QUERY))->assertOk();

        $this->assertNothingChanged($event);
    }

    public function test_neither_return_page_claims_the_payment_succeeded(): void
    {
        foreach (['payments.return', 'payments.cancel'] as $route) {
            $body = $this->get(route($route, self::HOSTILE_QUERY))->assertOk()->getContent();

            $this->assertIsString($body);

            // A page that says "your payment succeeded" on the strength of a
            // browser redirect IS marking paid from a return URL, whatever the
            // database does. The copy must stay conditional.
            foreach (['Pembayaran berhasil', 'Lunas', 'DIBAYAR', 'Terbayar'] as $claim) {
                $this->assertStringNotContainsString(
                    $claim,
                    $body,
                    "[{$route}] asserts payment success from a browser return"
                );
            }
        }
    }

    public function test_neither_return_page_claims_the_payment_succeeded_or_failed(): void
    {
        foreach (['payments.return', 'payments.cancel'] as $route) {
            $body = (string) $this->get(route($route, self::HOSTILE_QUERY))->assertOk()->getContent();

            foreach ([
                'Pembayaran berhasil',
                'Lunas',
                'DIBAYAR',
                'Terbayar',
                'Pembayaran Tidak Diselesaikan',
                'tanpa menyelesaikan transaksi',
                'pembayaran gagal',
                'dibatalkan',
            ] as $claim) {
                $this->assertStringNotContainsString(
                    $claim,
                    $body,
                    "[{$route}] asserts a payment outcome from a browser return"
                );
            }
        }
    }

    public function test_neither_return_page_echoes_the_query_string_back_to_the_visitor(): void
    {
        // Reflecting an attacker-supplied value would both invite an injection
        // and dress a forged parameter up as something the system confirmed.
        foreach (['payments.return', 'payments.cancel'] as $route) {
            $body = (string) $this->get(route($route, self::HOSTILE_QUERY))->assertOk()->getContent();

            foreach (['order_forged', 'pay_forged', '150000000'] as $value) {
                $this->assertStringNotContainsString($value, $body, "[{$route}] reflected [{$value}]");
            }
        }
    }

    public function test_the_return_routes_answer_get_only(): void
    {
        $this->post(route('payments.return'))->assertMethodNotAllowed();
        $this->post(route('payments.cancel'))->assertMethodNotAllowed();
    }

    public function test_the_return_controllers_contain_no_write_or_transition(): void
    {
        // Route-controller separation, asserted structurally as the ruling asks:
        // the browser return path must not even be able to reach a mutation.
        foreach ([
            'app/Platform/Payment/Http/Controllers/PaymentReturnController.php',
            'app/Platform/Payment/Http/Controllers/PaymentCancelController.php',
        ] as $relative) {
            $path = base_path($relative);

            $this->assertFileExists($path);

            $source = (string) file_get_contents($path);

            foreach ([
                'markStatus',
                'SessionState',
                'PaymentSession',
                'ProviderEvent',
                'ReceiveWebhook',
                'ProcessWebhookEvent',
                'Journal',
                'Outbox',
                'DB::',
                '->save(',
                '->update(',
                '::create(',
                'Audit::',
            ] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $this->withoutComments($source),
                    "{$relative} references [{$forbidden}]"
                );
            }
        }
    }

    private function assertNothingChanged(ProviderEvent $event): void
    {
        $this->assertSame(
            ProviderEventStatus::Validated->value,
            $event->fresh()->status,
            'a browser return moved a provider_events row'
        );

        $this->assertSame(0, PaymentSession::query()->count());
        $this->assertSame(0, PaymentIntent::query()->count());
        $this->assertSame(0, AuditEvent::query()->count());
        $this->assertSame(0, DB::table('outbox_events')->count());
        $this->assertSame(1, ProviderEvent::query()->count());
    }

    private function validatedProviderEvent(): ProviderEvent
    {
        $body = '{"event_type":"payment.completed"}';

        $event = ProviderEvent::create([
            'provider' => PaymentProviders::SUMOPOD_SANDBOX,
            'provider_event_id' => 'msg_'.bin2hex(random_bytes(8)),
            'event_id_source' => 'svix-id',
            'provider_transaction_id' => 'pay_forged',
            'invoice_reference' => 'order_forged',
            'event_type' => 'payment.completed',
            'merchant_ref' => 'makam-sandbox',
            'amount_minor' => 1_500_000_00,
            'declared_currency' => 'IDR',
            'raw_payload' => $body,
            'payload_digest' => hash('sha256', $body),
            'status' => ProviderEventStatus::Received->value,
            'received_at' => CarbonImmutable::now(),
        ]);

        $event->markStatus(ProviderEventStatus::Validated);

        return $event;
    }

    /**
     * The forbidden-token scan reads code, not prose — the controllers' doc
     * blocks legitimately NAME the things they must never do.
     */
    private function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}

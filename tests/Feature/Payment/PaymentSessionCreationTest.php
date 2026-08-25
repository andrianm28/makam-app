<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\Payment\Exceptions\PaymentSessionCreationUnavailableException;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\PaymentIntentDecision;
use App\Platform\Payment\SessionState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `PaymentSession`'s `creating` hook is now gate-conditional: creation is
 * refused while `G-PAY-01` is closed (the Wave 1b ruling 1b-L3-01 posture,
 * asserted here through the real container binding — seeded registry, real
 * `ModeResolver`), and allowed when the gate is open (dev). These tests
 * open the gate exactly the way the feature-gate suite does — a direct
 * `feature_gates` row update — and let the model hook resolve the mode from
 * the container, mirroring `FeatureGateResolverTest`'s fresh-instance
 * convention.
 */
final class PaymentSessionCreationTest extends TestCase
{
    use RefreshDatabase;

    private function intent(): PaymentIntent
    {
        return PaymentIntent::query()->create([
            'requested_amount_minor' => 1_500_000_00,
            'currency' => 'IDR',
            'payment_mode' => 'online',
            'decision' => PaymentIntentDecision::Allowed->value,
            'actor_role' => 'customer',
            'evaluated_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function minimalPayload(PaymentIntent $intent): array
    {
        return [
            'payment_intent_id' => $intent->id,
            'provider' => 'sumopod_sandbox',
            'provider_payment_id' => 'pay_test_1',
            'payment_link_url' => 'https://checkout.sumopod.com/x',
            'amount_minor' => 1_500_000_00,
            'currency' => 'IDR',
            'merchant_ref' => 'MK-ORD-1',
            'badan_usaha_ref' => 'BU-JKT-01',
            'state' => SessionState::AwaitingPayment->value,
        ];
    }

    public function test_creating_a_session_throws_when_the_gate_is_closed(): void
    {
        // Default: G-PAY-01 closed (seeded by the registry migration).
        $intent = $this->intent();

        $this->expectException(PaymentSessionCreationUnavailableException::class);

        PaymentSession::query()->create($this->minimalPayload($intent));
    }

    public function test_creating_a_session_succeeds_when_the_gate_is_open(): void
    {
        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'open']);
        $intent = $this->intent();

        $session = PaymentSession::query()->create($this->minimalPayload($intent));

        $this->assertSame(SessionState::AwaitingPayment->value, $session->state);
        $this->assertSame(1_500_000_00, $session->amount_minor);
        $this->assertSame('IDR', $session->currency);
        $this->assertSame('MK-ORD-1', $session->merchant_ref);
        $this->assertSame('BU-JKT-01', $session->badan_usaha_ref);
        $this->assertDatabaseHas('payment_sessions', [
            'id' => $session->id,
            'state' => SessionState::AwaitingPayment->value,
            'amount_minor' => 1_500_000_00,
            'merchant_ref' => 'MK-ORD-1',
        ]);
    }
}

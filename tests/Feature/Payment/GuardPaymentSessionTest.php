<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\PaymentMode;
use App\Platform\FinancialLedger\Money;
use App\Platform\Payment\ConditionDenial;
use App\Platform\Payment\GuardCondition;
use App\Platform\Payment\GuardDenialReason;
use App\Platform\Payment\GuardPaymentSession;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\PaymentAuditActions;
use App\Platform\Payment\PaymentIntentDecision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * Wave 1b ruling 1b-L3-01 — the deny-only six-condition guard.
 *
 * Condition 1 is REAL (`ModeResolver::paymentMode()`, backed by `G-PAY-01`).
 * Conditions 2-6 have no authoritative upstream record in this repository,
 * so each returns an explicit `UnavailableUpstream` DENIAL naming the
 * missing upstream — never a bypass, never a stub that passes.
 */
final class GuardPaymentSessionTest extends TestCase
{
    use RefreshDatabase;

    private function guardWithPaymentGate(bool $open): GuardPaymentSession
    {
        $source = new class($open) implements GateRegistrySource
        {
            public function __construct(private readonly bool $open) {}

            public function load(): GateRegistrySnapshot
            {
                return new GateRegistrySnapshot([
                    'G-PAY-01' => GateState::fromRecord('G-PAY-01', open: $this->open),
                ]);
            }
        };

        $this->app->instance(ModeResolver::class, new ModeResolver(new FeatureGateResolver($source)));

        return $this->app->make(GuardPaymentSession::class);
    }

    private function amount(): Money
    {
        return new Money(1_500_000_00);
    }

    /**
     * @return array<string, ConditionDenial>
     */
    private function denialsByCondition(bool $gateOpen): array
    {
        $result = ($this->guardWithPaymentGate($gateOpen))($this->amount());

        $byCondition = [];

        foreach ($result->denials() as $denial) {
            $byCondition[$denial->condition->value] = $denial;
        }

        return $byCondition;
    }

    public function test_a_closed_payment_gate_denies_at_condition_one_as_a_genuine_domain_denial(): void
    {
        $result = ($this->guardWithPaymentGate(open: false))($this->amount());

        $this->assertFalse($result->isAllowed());
        $this->assertSame(GuardCondition::ProductGateOpen, $result->condition());
        $this->assertSame(GuardDenialReason::DomainDenied, $result->reason());
        $this->assertFalse(
            $result->isUnavailableUpstream(),
            'A closed G-PAY-01 is a real, evaluable domain denial — it must never be reported as a missing upstream.'
        );
        $this->assertNull($result->missingUpstream());
    }

    public function test_an_open_payment_gate_passes_condition_one_and_still_denies_at_condition_two(): void
    {
        $result = ($this->guardWithPaymentGate(open: true))($this->amount());

        $this->assertFalse($result->isAllowed());
        $this->assertSame(GuardCondition::ConfirmationOrReservation, $result->condition());
        $this->assertSame(GuardDenialReason::UnavailableUpstream, $result->reason());
        $this->assertSame('Confirmation|PlotReservation', $result->missingUpstream());

        $this->assertArrayNotHasKey(
            GuardCondition::ProductGateOpen->value,
            $this->denialsByCondition(gateOpen: true),
            'Condition 1 is genuinely evaluable — an open gate must not be reported as denied.'
        );
    }

    public function test_all_six_conditions_are_evaluated_in_the_fixed_documented_order(): void
    {
        $result = ($this->guardWithPaymentGate(open: false))($this->amount());

        $this->assertSame(
            GuardCondition::ORDER,
            array_map(
                static fn (ConditionDenial $denial): string => $denial->condition->value,
                $result->denials(),
            ),
            'With every condition failing, the denial list is exactly the six conditions in plan order.'
        );
    }

    /**
     * @return list<array{0: GuardCondition, 1: string}>
     */
    public static function unavailableUpstreamConditions(): array
    {
        return [
            'condition 2 — confirmation or reservation' => [
                GuardCondition::ConfirmationOrReservation, 'Confirmation|PlotReservation',
            ],
            'condition 3 — accepted unexpired quote' => [
                GuardCondition::QuoteAcceptedAndUnexpired, 'Quote',
            ],
            'condition 4 — authorized opening' => [
                GuardCondition::AuthorizedOpening, 'AuthorizePaymentOpening',
            ],
            'condition 5 — amount matches quote total' => [
                GuardCondition::AmountMatchesQuoteTotal, 'Quote',
            ],
            'condition 6 — merchant and badan usaha bound' => [
                GuardCondition::MerchantAndBadanUsahaBound, 'Merchant|BadanUsaha',
            ],
        ];
    }

    #[DataProvider('unavailableUpstreamConditions')]
    public function test_each_absent_upstream_condition_denies_with_unavailable_upstream(
        GuardCondition $condition,
        string $missingUpstream,
    ): void {
        $denials = $this->denialsByCondition(gateOpen: true);

        $this->assertArrayHasKey($condition->value, $denials);

        $denial = $denials[$condition->value];

        $this->assertSame(GuardDenialReason::UnavailableUpstream, $denial->reason);
        $this->assertSame($missingUpstream, $denial->missingUpstream);
        $this->assertNotSame('', trim($denial->publicMessage));
    }

    public function test_every_guard_evaluation_writes_exactly_one_payment_intent_decision_record(): void
    {
        ($this->guardWithPaymentGate(open: false))($this->amount());

        $this->assertSame(1, PaymentIntent::query()->count());

        $intent = PaymentIntent::query()->sole();

        $this->assertSame(PaymentIntentDecision::Denied->value, $intent->decision);
        $this->assertSame(GuardCondition::ProductGateOpen->value, $intent->denied_condition);
        $this->assertSame(GuardDenialReason::DomainDenied->value, $intent->denial_reason);
        $this->assertSame(1_500_000_00, $intent->requested_amount_minor);
        $this->assertSame('IDR', $intent->currency);
        $this->assertSame(PaymentMode::ManualCoordination->value, $intent->payment_mode);
        $this->assertSame(GuardCondition::ORDER, $intent->denied_conditions);
    }

    public function test_the_intent_snapshots_the_server_resolved_mode_at_evaluation_time(): void
    {
        ($this->guardWithPaymentGate(open: true))($this->amount());

        $this->assertSame(PaymentMode::Online->value, PaymentIntent::query()->sole()->payment_mode);
    }

    public function test_a_denial_writes_a_payment_guard_denied_audit_event_against_the_intent(): void
    {
        ($this->guardWithPaymentGate(open: false))($this->amount());

        $intent = PaymentIntent::query()->sole();

        $event = AuditEvent::query()
            ->where('action', PaymentAuditActions::GUARD_DENIED)
            ->sole();

        $this->assertSame('PAYMENT_GUARD_DENIED', $event->action);
        $this->assertSame(AuditOutcome::Denied->value, $event->outcome);
        $this->assertSame('payment_intent', $event->subject_type);
        $this->assertSame((string) $intent->id, $event->subject_id);
        $this->assertSame('guest', $event->actor_role);

        $this->assertSame(['note'], array_keys($event->metadata));
        $this->assertStringContainsString(GuardCondition::ProductGateOpen->value, $event->metadata['note']);
        $this->assertStringContainsString(GuardDenialReason::DomainDenied->value, $event->metadata['note']);
    }

    public function test_the_intent_and_its_audit_event_commit_together(): void
    {
        ($this->guardWithPaymentGate(open: true))($this->amount());

        $this->assertSame(1, PaymentIntent::query()->count());
        $this->assertSame(
            1,
            AuditEvent::query()->where('action', PaymentAuditActions::GUARD_DENIED)->count(),
        );
    }

    public function test_the_payment_mode_can_never_be_supplied_by_a_caller(): void
    {
        foreach ([new ReflectionMethod(GuardPaymentSession::class, '__construct'),
            new ReflectionMethod(GuardPaymentSession::class, '__invoke')] as $method) {
            foreach ($method->getParameters() as $parameter) {
                $type = $parameter->getType();

                $this->assertFalse(
                    $type instanceof ReflectionNamedType && $type->getName() === PaymentMode::class,
                    "GuardPaymentSession::{$method->getName()}() must not accept a PaymentMode — "
                    .'AC1 requires the mode to be server-resolved at evaluation time.'
                );

                $this->assertNotSame('mode', $parameter->getName());
                $this->assertNotSame('paymentMode', $parameter->getName());
            }
        }
    }

    public function test_a_guard_evaluation_never_creates_a_payment_session_row(): void
    {
        ($this->guardWithPaymentGate(open: false))($this->amount());
        ($this->guardWithPaymentGate(open: true))($this->amount());

        $this->assertSame(0, PaymentSession::query()->count());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Models\User;
use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FinancialLedger\Money;
use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\Payment\Exceptions\PaymentSessionCreationUnavailableException;
use App\Platform\Payment\GuardPaymentSession;
use App\Platform\Payment\GuardResult;
use App\Platform\Payment\Models\PaymentIntent;
use App\Platform\Payment\Models\PaymentSession;
use App\Platform\Payment\SessionState;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * The fail-closed invariant of Wave 1b ruling 1b-L3-01, asserted directly,
 * as it stands after Task 4 of the online-payment gateway plan:
 *
 *   "There must be **no reachable PASS outcome**, and therefore no
 *    `payment_sessions` row creatable by any caller."
 *
 * The gateway made condition 6 real via config, so the reachable-pass
 * prohibition is now scoped exactly where the approved design put it: a pass
 * requires ALL six conditions to hold — gate open AND merchant binding
 * configured AND a confirmed order AND an accepted unexpired quote AND an
 * authorized opener AND a matching amount. With the gate closed, the binding
 * unconfigured, or the order unconfirmed, no input combination reaches a
 * pass, and this suite's 16-combination sweep is precisely those shapes.
 * The one all-six-hold combination is exercised deliberately, in
 * `GuardPaymentSessionUpstreamTest` and `OpenPaymentSessionTest`, not here.
 *
 * Three independent layers are pinned here, because any one of them alone
 * could be weakened by a later edit without the others noticing:
 *
 *   1. Behaviour — no combination of inputs with the gate closed, the
 *      binding unconfigured, or the order unconfirmed makes the guard return
 *      an allowed result.
 *   2. Structure — nothing but `Actions\OpenPaymentSession` writes a
 *      `payment_sessions` row: `CreatePaymentSession` and the
 *      `PaymentProvider` contract are deliberately NOT built by this task.
 *   3. Defence in depth — the `PaymentSession` model itself refuses to
 *      insert while the gate is closed. See that model's doc block for the
 *      paths this does NOT cover (raw SQL / query builder), stated honestly
 *      rather than assumed shut.
 */
final class PaymentGuardFailClosedTest extends TestCase
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

    public function test_no_input_combination_reaches_a_pass_outcome(): void
    {
        $amounts = [new Money(0), new Money(1), new Money(1_500_000_00), new Money(-1)];
        $evaluations = 0;

        foreach ([false, true] as $authenticated) {
            if ($authenticated) {
                $this->actingAs(User::factory()->create());
            } else {
                Auth::logout();
            }

            // `ActorContextResolver` is a `scoped()` binding that caches the
            // resolved actor for the lifetime of one request/job; a test
            // switching actors mid-method has to discard it, exactly as that
            // class's `forget()` doc block describes.
            $this->app->forgetInstance(ActorContextResolver::class);

            foreach ([false, true] as $gateOpen) {
                foreach ($amounts as $amount) {
                    // A fresh `MASUK` order denies conditions 2-5 by its real
                    // evaluations; condition 6 denies because the merchant
                    // binding is unconfigured here (its config default is
                    // empty — fail closed), and condition 1 denies whenever
                    // the gate is closed. So no input combination here —
                    // order, amount, actor, gate, binding — can reach PASS.
                    $order = Order::query()->create([
                        'reference' => 'MK-FAILCLOSED-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 8)),
                        'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
                        'status' => OrderStatus::MASUK->value,
                    ]);

                    $result = ($this->guardWithPaymentGate($gateOpen))($order, $amount);
                    $evaluations++;

                    $this->assertFalse(
                        $result->isAllowed(),
                        'The guard produced a PASS — ruling 1b-L3-01 requires no reachable pass outcome.'
                    );
                    $this->assertTrue($result->isDenied());
                }
            }
        }

        $this->assertSame(16, $evaluations);
        $this->assertSame($evaluations, PaymentIntent::query()->count());
        $this->assertSame(
            0,
            PaymentSession::query()->count(),
            'A deny-only guard cannot create a payment session under any input.'
        );
    }

    public function test_the_guard_always_returns_a_non_nullable_guard_result(): void
    {
        $returnType = (new ReflectionMethod(GuardPaymentSession::class, '__invoke'))->getReturnType();

        $this->assertInstanceOf(ReflectionNamedType::class, $returnType);
        $this->assertSame(GuardResult::class, $returnType->getName());
        $this->assertFalse($returnType->allowsNull());
    }

    public function test_the_session_creation_and_provider_seams_are_not_built_by_this_task(): void
    {
        foreach ([
            'App\Platform\Payment\CreatePaymentSession',
            'App\Platform\Payment\Contracts\PaymentProvider',
            'App\Platform\Payment\Providers\SumoPodSandboxProvider',
        ] as $outOfScope) {
            $this->assertFalse(
                class_exists($outOfScope) || interface_exists($outOfScope),
                "{$outOfScope} is out of scope for Task 2 (ruling 1b-L3-01) — a deny-only guard "
                .'has nothing downstream to hand to.'
            );
        }
    }

    public function test_the_payment_session_model_refuses_to_insert_a_row(): void
    {
        $this->expectException(PaymentSessionCreationUnavailableException::class);

        PaymentSession::query()->create([
            'provider' => 'fake',
            'provider_payment_id' => 'pay_test',
            'payment_link_url' => 'https://example.test/pay',
            'amount_minor' => 1_500_000_00,
            'currency' => 'IDR',
            'merchant_ref' => 'merchant-test',
            'badan_usaha_ref' => 'badan-usaha-test',
            'state' => SessionState::AwaitingPayment->value,
        ]);
    }

    public function test_the_payment_session_model_refuses_a_direct_save_too(): void
    {
        $session = new PaymentSession;
        $session->provider = 'fake';
        $session->state = SessionState::Created->value;

        $this->expectException(PaymentSessionCreationUnavailableException::class);

        $session->save();
    }

    public function test_the_payment_sessions_table_exists_even_though_nothing_may_write_to_it(): void
    {
        $this->assertTrue(
            Schema::hasTable('payment_sessions'),
            'The migration ships in this lane (plan §File Structure); only the write path is withheld.'
        );

        $this->assertSame(0, PaymentSession::query()->count());
    }
}

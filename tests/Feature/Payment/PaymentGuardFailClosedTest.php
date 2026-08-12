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
 * The fail-closed invariant of Wave 1b ruling 1b-L3-01, asserted directly:
 *
 *   "There must be **no reachable PASS outcome**, and therefore no
 *    `payment_sessions` row creatable by any caller."
 *
 * Three independent layers are pinned here, because any one of them alone
 * could be weakened by a later edit without the others noticing:
 *
 *   1. Behaviour — no combination of inputs makes the guard return an
 *      allowed result.
 *   2. Structure — nothing in the application writes a `payment_sessions`
 *      row: `CreatePaymentSession` and the `PaymentProvider` contract are
 *      deliberately NOT built by this task.
 *   3. Defence in depth — the `PaymentSession` model itself refuses to
 *      insert. See that model's doc block for the paths this does NOT cover
 *      (raw SQL / query builder), stated honestly rather than assumed shut.
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
                    // evaluations and 6 by `UnavailableUpstream`; condition 6
                    // is unconditional, so no input combination here — order,
                    // amount, actor, gate — can reach PASS.
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

    public function test_the_guard_returns_a_result_type_that_has_no_allowed_variant(): void
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

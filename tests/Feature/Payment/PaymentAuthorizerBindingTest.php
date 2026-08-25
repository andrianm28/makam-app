<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Platform\Payment\Contracts\PaymentActionAuthorizer;
use App\Platform\Payment\FinanceOrRestrictedAdminPaymentAuthorizer;
use App\Platform\Payment\Providers\PaymentServiceProvider;
use Tests\TestCase;

/**
 * Locks in `PaymentServiceProvider`'s authorization binding, in the style of
 * `Tests\Feature\FinancialLedger\FinancialLedgerBindingsTest`.
 *
 * The provider's registration in `bootstrap/providers.php` is as load-bearing
 * as the binding itself: `FeatureGateServiceProvider`'s own class comment
 * records a case in this codebase where the bindings existed, the registration
 * line did not, and the gap only surfaced as a live 500 on the first real HTTP
 * request. For an authorization policy the failure mode would be worse than a
 * 500 — an unbound interface is how a controller stops checking anything.
 */
final class PaymentAuthorizerBindingTest extends TestCase
{
    public function test_the_provider_is_registered_in_the_application(): void
    {
        $this->assertTrue(
            $this->app->providerIsLoaded(PaymentServiceProvider::class),
            'PaymentServiceProvider must stay listed in bootstrap/providers.php.',
        );
    }

    public function test_the_payment_action_authorizer_resolves_to_the_finance_policy(): void
    {
        $this->assertInstanceOf(
            FinanceOrRestrictedAdminPaymentAuthorizer::class,
            $this->app->make(PaymentActionAuthorizer::class),
        );
    }

    /**
     * Transient `bind()`, not `singleton()` — see the provider's own comment.
     * A shared authorization-policy instance is the shape that invites a
     * cached decision to outlive the actor it was made for.
     */
    public function test_the_authorizer_is_transient_not_shared(): void
    {
        $this->assertNotSame(
            $this->app->make(PaymentActionAuthorizer::class),
            $this->app->make(PaymentActionAuthorizer::class),
        );
    }
}

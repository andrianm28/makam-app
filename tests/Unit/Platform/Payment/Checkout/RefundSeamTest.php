<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Payment\Checkout;

use App\Platform\Payment\Checkout\Contracts\PaymentCheckoutClient;
use App\Platform\Payment\Checkout\Exceptions\PaymentRefundNotSupportedException;
use App\Platform\Payment\Checkout\RefundPaymentRequest;
use App\Platform\Payment\Checkout\SumoPodPaymentClient;
use Illuminate\Support\Facades\Http;
use ReflectionClass;
use Tests\TestCase;

/**
 * The refund seam exists, and nothing behind it pretends to work.
 *
 * ---------------------------------------------------------------------------
 * What these tests are actually defending
 * ---------------------------------------------------------------------------
 * The refund plan's rule for this stage is that an interface with no
 * implementation "must not be read as an existing refund capability". The
 * danger is not that `refund()` fails — it is that some future
 * implementation fails QUIETLY: returns a null, swallows the call, or answers
 * `true` from `supportsRefund()` while its `refund()` throws. On this path
 * the consequence of a silent failure is a family told their money was
 * returned when it was not.
 *
 * So the last test below does not check SumoPod. It checks EVERY class in
 * the codebase that implements the contract, so a second provider added
 * later cannot claim the capability without also being able to serve it.
 * That is the assertion that keeps working after the person who wrote it
 * has gone.
 */
final class RefundSeamTest extends TestCase
{
    private function request(): RefundPaymentRequest
    {
        return new RefundPaymentRequest(
            providerPaymentId: 'pay_0001',
            amountMinor: 165_000_00,
            reason: 'Pesanan terbayar ditolak; kewajiban refund dibuka.',
        );
    }

    public function test_sumopod_declares_that_it_cannot_refund(): void
    {
        $this->assertFalse(SumoPodPaymentClient::fromConfig()->supportsRefund());
    }

    /**
     * The capability answer must not depend on whether this environment has
     * a credential. An unprovisioned environment and a fully-provisioned one
     * are equally unable to refund; making it config-dependent would suggest
     * the capability appears once the key is set.
     */
    public function test_the_refusal_does_not_depend_on_configuration(): void
    {
        config(['payment.providers.sumopod-sandbox.api_key' => 'a-real-looking-key']);
        config(['payment.providers.sumopod-sandbox.base_url' => 'https://api-pay-sandbox.sumopod.com']);

        $this->assertFalse(SumoPodPaymentClient::fromConfig()->supportsRefund());
    }

    public function test_calling_refund_on_sumopod_throws_rather_than_failing_quietly(): void
    {
        $this->expectException(PaymentRefundNotSupportedException::class);

        SumoPodPaymentClient::fromConfig()->refund($this->request());
    }

    /**
     * No HTTP request is attempted. `Http::fake()` with NO stubs registered
     * means any outgoing call would be recorded; asserting nothing was sent
     * proves the refusal comes from the provider's known capabilities and not
     * from a response.
     *
     * This is the specific mistake this module has already made once: a
     * reconciliation feature built against a GUESSED SumoPod endpoint path
     * shipped, returned real 404s in production, and was reverted. A refund
     * endpoint invented the same way would fail on a path where the wrong
     * answer is a family told their money came back.
     */
    public function test_refund_makes_no_provider_request_at_all(): void
    {
        Http::fake();

        try {
            SumoPodPaymentClient::fromConfig()->refund($this->request());
            $this->fail('Expected PaymentRefundNotSupportedException.');
        } catch (PaymentRefundNotSupportedException) {
            // expected
        }

        Http::assertNothingSent();
    }

    /**
     * The message has to be actionable by whoever reads it in a log at 2am,
     * so it states that this is permanent and names the path that does work.
     */
    public function test_the_refusal_says_it_is_permanent_and_names_the_way_forward(): void
    {
        try {
            SumoPodPaymentClient::fromConfig()->refund($this->request());
            $this->fail('Expected PaymentRefundNotSupportedException.');
        } catch (PaymentRefundNotSupportedException $e) {
            $this->assertStringContainsString('permanent property of the provider', $e->getMessage());
            $this->assertStringContainsString('retrying will not change it', $e->getMessage());
            $this->assertStringContainsString('manual', $e->getMessage());
        }
    }

    /**
     * Every implementation of the contract, not just today's one.
     *
     * A provider that answers `supportsRefund() === true` is claiming a
     * capability, and the claim has to survive being taken up: calling
     * `refund()` on it must not throw `PaymentRefundNotSupportedException`.
     * Conversely a provider that answers `false` must throw rather than
     * return something a caller would treat as success.
     *
     * Declared classes are enumerated rather than hardcoded so this keeps
     * biting when a second provider lands.
     */
    public function test_no_implementation_claims_a_capability_it_cannot_serve(): void
    {
        $implementations = array_values(array_filter(
            get_declared_classes(),
            static fn (string $class): bool => is_subclass_of($class, PaymentCheckoutClient::class)
                && ! (new ReflectionClass($class))->isAbstract(),
        ));

        $this->assertNotEmpty(
            $implementations,
            'No PaymentCheckoutClient implementation was loaded; this test would pass vacuously.',
        );

        foreach ($implementations as $class) {
            /** @var PaymentCheckoutClient $client */
            $client = $class === SumoPodPaymentClient::class
                ? SumoPodPaymentClient::fromConfig()
                : app($class);

            if ($client->supportsRefund()) {
                // Claiming the capability: taking it up must not be refused
                // as unsupported. Any OTHER failure (network, credentials) is
                // this test's business to ignore.
                try {
                    $client->refund($this->request());
                } catch (PaymentRefundNotSupportedException $e) {
                    $this->fail($class.' answers supportsRefund() true but refuses refund() as unsupported: '.$e->getMessage());
                } catch (\Throwable) {
                    // Unrelated failure — not what this test measures.
                }

                continue;
            }

            // Not claiming it: the refusal must be loud.
            try {
                $client->refund($this->request());
                $this->fail($class.' answers supportsRefund() false but refund() returned instead of throwing.');
            } catch (PaymentRefundNotSupportedException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}

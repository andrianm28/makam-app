<?php

declare(strict_types=1);

namespace Tests\Feature\Correlation;

use App\Platform\Correlation\CorrelationContext;
use App\Platform\Correlation\CorrelationId;
use Tests\TestCase;

/**
 * Locks in the `scoped()` container binding itself (`CorrelationServiceProvider`),
 * independent of any HTTP request — same style as
 * `IdentityAccessServiceProviderBindingTest`.
 */
final class CorrelationServiceProviderBindingTest extends TestCase
{
    public function test_correlation_context_is_resolvable_from_the_container(): void
    {
        $this->assertInstanceOf(
            CorrelationContext::class,
            $this->app->make(CorrelationContext::class)
        );
    }

    public function test_correlation_context_binding_returns_the_same_instance_within_one_scope(): void
    {
        $first = $this->app->make(CorrelationContext::class);
        $second = $this->app->make(CorrelationContext::class);

        $this->assertSame($first, $second);
    }

    public function test_forgetting_scoped_instances_resets_correlation_context_to_a_fresh_unset_instance(): void
    {
        // This is the exact mechanism this repo relies on to prevent a
        // correlation id leaking from one Horizon job into the next —
        // Laravel's QueueServiceProvider calls
        // $app->forgetScopedInstances() between jobs in a worker process.
        // Proving it here (rather than only asserting "compiles/binds")
        // is what the batch brief means by "proves the scoped() binding
        // actually resets."
        $first = $this->app->make(CorrelationContext::class);
        $first->set(CorrelationId::generate());

        $this->assertNotNull($first->current());

        $this->app->forgetScopedInstances();

        $second = $this->app->make(CorrelationContext::class);

        $this->assertNotSame($first, $second);
        $this->assertNull($second->current());
    }
}

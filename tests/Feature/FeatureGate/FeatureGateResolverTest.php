<?php

declare(strict_types=1);

namespace Tests\Feature\FeatureGate;

use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FeatureGate\Providers\FeatureGateServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `FeatureGateResolver` end-to-end through the real container binding —
 * mirrors `ActorContextResolverTest`'s convention.
 *
 * `FeatureGateServiceProvider` is registered manually in each test
 * (`$this->app->register(...)`) rather than relying on
 * `bootstrap/providers.php`, because that file is NOT yet updated for this
 * batch — see `FeatureGateServiceProvider`'s own doc block and this
 * batch's report for exactly why (this worktree does not contain a safely-
 * editable copy of that shared file). This keeps these tests meaningful
 * and self-contained regardless of when that one-line registration lands;
 * once it does, this manual `register()` call becomes a harmless no-op
 * (Laravel providers are safe to register more than once).
 */
final class FeatureGateResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(FeatureGateServiceProvider::class);
    }

    public function test_an_unknown_gate_id_resolves_closed_through_the_real_container_binding(): void
    {
        $resolver = $this->app->make(FeatureGateResolver::class);

        $this->assertFalse($resolver->isOpen('G-THIS-GATE-ID-DOES-NOT-EXIST'));
    }

    public function test_a_seeded_but_never_activated_gate_resolves_closed(): void
    {
        $resolver = $this->app->make(FeatureGateResolver::class);

        $this->assertFalse($resolver->isOpen('G-PAY-01'));
    }

    public function test_snapshot_is_resolved_only_once_per_resolver_instance(): void
    {
        $resolver = $this->app->make(FeatureGateResolver::class);

        $first = $resolver->resolve();
        $second = $resolver->resolve();

        $this->assertSame($first, $second);
    }

    public function test_a_state_change_made_after_the_snapshot_was_cached_is_not_observed_by_the_same_resolver_instance(): void
    {
        // design.md: "resolve gate set (cached per request)". A change
        // made mid-"request" by something else (here: a direct row update,
        // standing in for a concurrent activation) must not leak into an
        // already-resolved snapshot — the NEXT request/resolver instance
        // is what is expected to observe it (see the test below).
        $resolver = $this->app->make(FeatureGateResolver::class);

        $this->assertFalse($resolver->isOpen('G-PAY-01'));

        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'open']);

        $this->assertFalse($resolver->isOpen('G-PAY-01'), 'A cached snapshot must not observe a state change made after it was resolved.');
    }

    public function test_a_fresh_resolver_instance_observes_a_state_change_made_by_a_previous_one(): void
    {
        $first = $this->app->make(FeatureGateResolver::class);
        $first->isOpen('G-PAY-01');

        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'open']);

        // A brand new instance (standing in for the next request) — not
        // resolved from the container again, since the scoped() binding
        // would hand back the same cached instance within one test's
        // container lifetime.
        $second = new FeatureGateResolver($this->app->make(GateRegistrySource::class));

        $this->assertTrue($second->isOpen('G-PAY-01'));
    }

    public function test_forget_forces_re_resolution_on_the_next_call(): void
    {
        $resolver = $this->app->make(FeatureGateResolver::class);

        $this->assertFalse($resolver->isOpen('G-PAY-01'));

        FeatureGate::query()->where('gate_id', 'G-PAY-01')->update(['state' => 'open']);
        $resolver->forget();

        $this->assertTrue($resolver->isOpen('G-PAY-01'));
    }

    public function test_resolver_binding_returns_the_same_instance_within_one_container_lifetime(): void
    {
        $first = $this->app->make(FeatureGateResolver::class);
        $second = $this->app->make(FeatureGateResolver::class);

        $this->assertSame($first, $second);
    }
}

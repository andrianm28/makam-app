<?php

declare(strict_types=1);

namespace Tests\Feature\FeatureGate;

use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\EloquentGateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateActivationRecorder;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Providers\FeatureGateServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in `FeatureGateServiceProvider`'s bindings — mirrors
 * `IdentityAccessServiceProviderBindingTest`'s convention. Registered
 * manually (`$this->app->register(...)`) because `bootstrap/providers.php`
 * does not yet carry this provider — see `FeatureGateServiceProvider`'s
 * doc block and this batch's report.
 */
final class FeatureGateServiceProviderBindingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->register(FeatureGateServiceProvider::class);
    }

    public function test_gate_registry_source_interface_resolves_to_the_eloquent_implementation(): void
    {
        $this->assertInstanceOf(EloquentGateRegistrySource::class, $this->app->make(GateRegistrySource::class));
    }

    public function test_feature_gate_resolver_is_resolvable_from_the_container(): void
    {
        $this->assertInstanceOf(FeatureGateResolver::class, $this->app->make(FeatureGateResolver::class));
    }

    public function test_mode_resolver_is_resolvable_from_the_container(): void
    {
        $this->assertInstanceOf(ModeResolver::class, $this->app->make(ModeResolver::class));
    }

    public function test_gate_activation_recorder_is_resolvable_from_the_container(): void
    {
        $this->assertInstanceOf(GateActivationRecorder::class, $this->app->make(GateActivationRecorder::class));
    }

    public function test_feature_gate_resolver_binding_returns_the_same_instance_within_one_container_lifetime(): void
    {
        $first = $this->app->make(FeatureGateResolver::class);
        $second = $this->app->make(FeatureGateResolver::class);

        $this->assertSame($first, $second);
    }
}

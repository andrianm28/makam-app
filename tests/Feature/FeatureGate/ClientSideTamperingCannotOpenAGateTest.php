<?php

declare(strict_types=1);

namespace Tests\Feature\FeatureGate;

use App\Platform\FeatureGate\EloquentGateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\Providers\FeatureGateServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

/**
 * requirements.md AC2: "THE SYSTEM SHALL NOT treat a front-end flag as
 * authoritative." Negative criteria: "No client-side gate as the
 * enforcement point." tasks.md: "Add tests: client-side tampering cannot
 * open a gate."
 *
 * Two complementary proofs:
 *  1. Structural — neither `FeatureGateResolver` nor
 *     `EloquentGateRegistrySource` accepts an `Illuminate\Http\Request` (or
 *     anything request-shaped) as a constructor dependency at all, so there
 *     is no code path by which either class COULD read request input.
 *  2. Behavioural — even with a maximally hostile bound `request` instance
 *     in the container (query string, header, AND cookie all claiming the
 *     gate is open), resolving through the real container binding still
 *     returns the seeded, closed database state.
 */
final class ClientSideTamperingCannotOpenAGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_feature_gate_resolver_has_no_request_shaped_constructor_dependency(): void
    {
        $parameters = (new ReflectionClass(FeatureGateResolver::class))
            ->getConstructor()
            ?->getParameters() ?? [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;

            $this->assertStringNotContainsStringIgnoringCase(
                'Request',
                $typeName,
                "FeatureGateResolver must not depend on anything request-shaped (found: {$typeName})."
            );
        }
    }

    public function test_eloquent_gate_registry_source_has_no_request_shaped_constructor_dependency(): void
    {
        $parameters = (new ReflectionClass(EloquentGateRegistrySource::class))
            ->getConstructor()
            ?->getParameters() ?? [];

        foreach ($parameters as $parameter) {
            $type = $parameter->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : (string) $type;

            $this->assertStringNotContainsStringIgnoringCase(
                'Request',
                $typeName,
                "EloquentGateRegistrySource must not depend on anything request-shaped (found: {$typeName})."
            );
        }
    }

    public function test_a_hostile_request_claiming_a_gate_is_open_has_no_effect_on_resolution(): void
    {
        $this->app->register(FeatureGateServiceProvider::class);

        // G-PAY-01 seeds closed. Simulate every plausible client-side
        // channel an attacker could use to try to claim it is open.
        $request = Request::create('/', 'GET', [
            'G-PAY-01' => 'open',
            'gate' => ['G-PAY-01' => true],
            'feature_gate_override' => 'G-PAY-01:open',
        ]);
        $request->headers->set('X-Feature-Gate-G-PAY-01', 'open');
        $request->cookies->set('feature_gate_G-PAY-01', 'open');

        $this->app->instance('request', $request);

        $resolver = $this->app->make(FeatureGateResolver::class);

        $this->assertFalse($resolver->isOpen('G-PAY-01'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\FeatureGate;

use App\Platform\FeatureGate\EloquentGateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FeatureGate\Providers\FeatureGateServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * requirements.md AC2: "THE SYSTEM SHALL NOT treat a front-end flag as
 * authoritative." Negative criteria: "No client-side gate as the
 * enforcement point." tasks.md: "Add tests: client-side tampering cannot
 * open a gate."
 *
 * Every test here makes the attack and checks the answer, rather than
 * inspecting either class's constructor for a request-shaped dependency.
 * A signature check proves only that today's wiring has no request in it;
 * it says nothing about a facade call, a container lookup, or a global
 * helper reaching the request from inside a method body — all of which are
 * live ways to reintroduce exactly this bug while keeping the constructor
 * clean. Sending the hostile input and reading the resolved state catches
 * all of them.
 *
 * Each test pairs the attack with a control that flips the DATABASE row and
 * re-reads. Without it, a gate subsystem that had broken closed — always
 * answering `false` for every gate — would satisfy the attack assertion and
 * leave this file permanently, silently green.
 */
final class ClientSideTamperingCannotOpenAGateTest extends TestCase
{
    use RefreshDatabase;

    /**
     * G-PAY-01 is seeded closed. Every plausible client-side channel an
     * attacker could use to claim otherwise, bound as the live request.
     */
    private function bindHostileRequestClaiming(string $gateId): void
    {
        $request = Request::create('/', 'GET', [
            $gateId => 'open',
            'gate' => [$gateId => true],
            'feature_gate_override' => "{$gateId}:open",
        ]);
        $request->headers->set("X-Feature-Gate-{$gateId}", 'open');
        $request->cookies->set("feature_gate_{$gateId}", 'open');

        $this->app->instance('request', $request);
    }

    private function openInTheDatabase(string $gateId): void
    {
        FeatureGate::query()->where('gate_id', $gateId)->update(['state' => 'open']);
    }

    public function test_a_hostile_request_claiming_a_gate_is_open_has_no_effect_on_resolution(): void
    {
        $this->app->register(FeatureGateServiceProvider::class);

        $this->bindHostileRequestClaiming('G-PAY-01');

        $this->assertFalse($this->app->make(FeatureGateResolver::class)->isOpen('G-PAY-01'));
    }

    /**
     * The control for the test above: the same hostile request is still
     * bound, and the resolver now answers `true` — because the DATABASE row
     * changed, which is the only input it honours. This is what makes the
     * `assertFalse` above evidence of enforcement rather than evidence of a
     * subsystem stuck on "closed".
     */
    public function test_the_resolver_follows_the_database_row_and_only_the_database_row(): void
    {
        $this->app->register(FeatureGateServiceProvider::class);

        $this->bindHostileRequestClaiming('G-PAY-01');
        $this->openInTheDatabase('G-PAY-01');

        $this->assertTrue($this->app->make(FeatureGateResolver::class)->isOpen('G-PAY-01'));
    }

    /**
     * The resolver caches per request, so the test above could in principle
     * pass through a stale snapshot rather than a genuine re-read. Going at
     * the registry source directly removes that layer: this is the class
     * that actually touches the database, asked twice with the hostile
     * request bound throughout.
     */
    public function test_the_registry_source_reads_database_state_and_ignores_the_request_entirely(): void
    {
        $this->bindHostileRequestClaiming('G-PAY-01');

        $environment = (string) config('app.env');

        $this->assertFalse(
            (new EloquentGateRegistrySource($environment))->load()->isOpen('G-PAY-01'),
            'A hostile request opened a gate at the registry source.'
        );

        $this->openInTheDatabase('G-PAY-01');

        $this->assertTrue(
            (new EloquentGateRegistrySource($environment))->load()->isOpen('G-PAY-01'),
            'The registry source did not follow the database row — the assertion above proves nothing.'
        );
    }

    /**
     * The hostile input is aimed at a gate id that does not exist at all.
     * Deny-by-default (AC10) must swallow it: a client must not be able to
     * conjure an open gate by naming one, which is the failure mode a
     * request-reading implementation would most likely have.
     */
    public function test_an_invented_gate_id_supplied_by_the_client_resolves_closed(): void
    {
        $this->app->register(FeatureGateServiceProvider::class);

        $this->bindHostileRequestClaiming('G-NOT-A-REAL-GATE');

        $this->assertFalse($this->app->make(FeatureGateResolver::class)->isOpen('G-NOT-A-REAL-GATE'));
    }
}

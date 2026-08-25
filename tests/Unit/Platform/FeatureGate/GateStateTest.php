<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\FeatureGate;

use App\Platform\FeatureGate\GateState;
use Tests\TestCase;

/**
 * Pure unit coverage for `GateState`'s invariant: `unknown()` and
 * `misconfigured()` can NEVER produce an open state. requirements.md AC10:
 * "an unknown gate id, or a gate row that fails to load/parse, MUST resolve
 * closed" — this is that guarantee enforced at the narrowest possible
 * scope, one level below `FeatureGateResolver`/`GateRegistrySnapshot`.
 */
final class GateStateTest extends TestCase
{
    public function test_unknown_gate_state_is_always_closed(): void
    {
        $state = GateState::unknown('G-DOES-NOT-EXIST');

        $this->assertFalse($state->open);
        $this->assertTrue($state->unknown);
        $this->assertFalse($state->misconfigured);
    }

    public function test_misconfigured_gate_state_is_always_closed(): void
    {
        $state = GateState::misconfigured('G-PAY-01');

        $this->assertFalse($state->open);
        $this->assertFalse($state->unknown);
        $this->assertTrue($state->misconfigured);
    }

    public function test_from_record_open_is_open_and_neither_unknown_nor_misconfigured(): void
    {
        $state = GateState::fromRecord('G-PAY-01', open: true);

        $this->assertTrue($state->open);
        $this->assertFalse($state->unknown);
        $this->assertFalse($state->misconfigured);
    }

    public function test_from_record_closed_is_closed_and_neither_unknown_nor_misconfigured(): void
    {
        $state = GateState::fromRecord('G-PAY-01', open: false);

        $this->assertFalse($state->open);
        $this->assertFalse($state->unknown);
        $this->assertFalse($state->misconfigured);
    }

    public function test_gate_id_is_carried_through(): void
    {
        $this->assertSame('G-WA-01', GateState::unknown('G-WA-01')->gateId);
        $this->assertSame('G-WA-01', GateState::misconfigured('G-WA-01')->gateId);
        $this->assertSame('G-WA-01', GateState::fromRecord('G-WA-01', open: true)->gateId);
    }
}

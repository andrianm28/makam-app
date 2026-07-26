<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\FeatureGate;

use App\Platform\FeatureGate\Contracts\GateRegistrySource;
use App\Platform\FeatureGate\FeatureGateResolver;
use App\Platform\FeatureGate\GateRegistrySnapshot;
use App\Platform\FeatureGate\GateState;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\GraveSearchMode;
use App\Platform\FeatureGate\Modes\PaymentMode;
use App\Platform\FeatureGate\Modes\PreNeedMode;
use App\Platform\FeatureGate\Modes\UrgentMode;
use App\Platform\FeatureGate\Modes\WhatsAppMode;
use Tests\TestCase;

/**
 * Locks in the ONE place `ModeResolver` pairs each named mode
 * (requirements.md AC7) with its backing gate id — `G-PAY-01`, `G-WA-01`,
 * `G-LEGAL-01`, `G-DATA-01`, and (added for `public-home-and-navigation`
 * S4-T3) `G-OPS-01` -> `UrgentMode`. Uses an in-memory `GateRegistrySource`
 * stub instead of the database, so this stays a pure unit test: what is
 * being proven is the gate-id-to-mode WIRING, not the database read path
 * (that is `EloquentGateRegistrySourceTest`'s job).
 */
final class ModeResolverTest extends TestCase
{
    private function resolverWithOpenGates(string ...$openGateIds): ModeResolver
    {
        $states = [];
        foreach (['G-PAY-01', 'G-WA-01', 'G-LEGAL-01', 'G-DATA-01', 'G-OPS-01'] as $gateId) {
            $states[$gateId] = GateState::fromRecord($gateId, open: in_array($gateId, $openGateIds, true));
        }

        $source = new class($states) implements GateRegistrySource
        {
            public function __construct(private readonly array $states) {}

            public function load(): GateRegistrySnapshot
            {
                return new GateRegistrySnapshot($this->states);
            }
        };

        return new ModeResolver(new FeatureGateResolver($source));
    }

    public function test_all_gates_closed_resolves_every_mode_to_its_closed_case(): void
    {
        $modes = $this->resolverWithOpenGates();

        $this->assertSame(PaymentMode::ManualCoordination, $modes->paymentMode());
        $this->assertSame(WhatsAppMode::EmailInAppFallback, $modes->whatsAppMode());
        $this->assertSame(PreNeedMode::InterestOnly, $modes->preNeedMode());
        $this->assertSame(GraveSearchMode::ManualAssistance, $modes->graveSearchMode());
        $this->assertSame(UrgentMode::CapacityUnknown, $modes->urgentMode());
    }

    public function test_urgent_mode_reads_g_ops_01_only(): void
    {
        $modes = $this->resolverWithOpenGates('G-OPS-01');

        $this->assertSame(UrgentMode::AcceptingRequests, $modes->urgentMode());
        $this->assertSame(PaymentMode::ManualCoordination, $modes->paymentMode());
    }

    public function test_payment_mode_reads_g_pay_01_only(): void
    {
        $modes = $this->resolverWithOpenGates('G-PAY-01');

        $this->assertSame(PaymentMode::Online, $modes->paymentMode());
        $this->assertSame(WhatsAppMode::EmailInAppFallback, $modes->whatsAppMode());
        $this->assertSame(PreNeedMode::InterestOnly, $modes->preNeedMode());
        $this->assertSame(GraveSearchMode::ManualAssistance, $modes->graveSearchMode());
    }

    public function test_whatsapp_mode_reads_g_wa_01_only(): void
    {
        $modes = $this->resolverWithOpenGates('G-WA-01');

        $this->assertSame(WhatsAppMode::WhatsApp, $modes->whatsAppMode());
        $this->assertSame(PaymentMode::ManualCoordination, $modes->paymentMode());
    }

    public function test_preneed_mode_reads_g_legal_01_only(): void
    {
        $modes = $this->resolverWithOpenGates('G-LEGAL-01');

        $this->assertSame(PreNeedMode::PaymentEnabled, $modes->preNeedMode());
        $this->assertSame(PaymentMode::ManualCoordination, $modes->paymentMode());
    }

    public function test_grave_search_mode_reads_g_data_01_only(): void
    {
        $modes = $this->resolverWithOpenGates('G-DATA-01');

        $this->assertSame(GraveSearchMode::SearchEnabled, $modes->graveSearchMode());
        $this->assertSame(PaymentMode::ManualCoordination, $modes->paymentMode());
    }

    public function test_all_gates_open_resolves_every_mode_to_its_open_case(): void
    {
        $modes = $this->resolverWithOpenGates('G-PAY-01', 'G-WA-01', 'G-LEGAL-01', 'G-DATA-01', 'G-OPS-01');

        $this->assertSame(PaymentMode::Online, $modes->paymentMode());
        $this->assertSame(WhatsAppMode::WhatsApp, $modes->whatsAppMode());
        $this->assertSame(PreNeedMode::PaymentEnabled, $modes->preNeedMode());
        $this->assertSame(GraveSearchMode::SearchEnabled, $modes->graveSearchMode());
        $this->assertSame(UrgentMode::AcceptingRequests, $modes->urgentMode());
    }
}

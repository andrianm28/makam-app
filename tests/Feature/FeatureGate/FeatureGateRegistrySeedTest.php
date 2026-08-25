<?php

declare(strict_types=1);

namespace Tests\Feature\FeatureGate;

use App\Platform\FeatureGate\Models\FeatureFlag;
use App\Platform\FeatureGate\Models\FeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves the migration in `2026_07_26_120400_seed_feature_gate_registry.php`
 * actually seeds the 17 gates and 18 flags requirements.md AC1 names as
 * this module's source of truth (`docs/governance/assumptions-and-gates.md`
 * §2/§3, `docs/operations/feature-flag-registry.md`) — every id below is
 * copied from those two documents, not invented (this batch's brief's own
 * warning: "not invented gate IDs").
 */
final class FeatureGateRegistrySeedTest extends TestCase
{
    use RefreshDatabase;

    private const array EXPECTED_GATE_IDS = [
        'G-LEGAL-01', 'G-PROTECTION-01', 'G-LAND-01', 'G-OPS-01', 'G-CAP-01',
        'G-PLOT-01', 'G-DIRECT-01', 'G-PAY-01', 'G-PAYOUT-01', 'G-TOKEN-01',
        'G-WA-01', 'G-DATA-01', 'G-MEM-01', 'G-VISIT-01', 'G-CERT-01',
        'G-RATE-01', 'G-EXT-01',
    ];

    private const array EXPECTED_FLAG_KEYS = [
        'feature.urgent_booking', 'feature.preneed_interest', 'feature.preneed_payment',
        'feature.funeral_protection', 'feature.land_marketplace', 'feature.online_payment',
        'feature.plot_inventory', 'feature.plot_reservation', 'feature.direct_plot_purchase',
        'feature.platform_certificate', 'feature.visitation_booking', 'feature.memorial_public',
        'feature.memorial_qr', 'feature.vendor_auto_payout', 'feature.subscription_tokenization',
        'feature.whatsapp', 'feature.grave_search', 'feature.grave_reminders',
    ];

    public function test_exactly_seventeen_gates_are_seeded(): void
    {
        $this->assertSame(17, FeatureGate::query()->count());
    }

    public function test_exactly_eighteen_flags_are_seeded(): void
    {
        $this->assertSame(18, FeatureFlag::query()->count());
    }

    public function test_every_expected_gate_id_from_the_registry_is_present_and_no_others(): void
    {
        $seeded = FeatureGate::query()->pluck('gate_id')->sort()->values()->all();
        $expected = collect(self::EXPECTED_GATE_IDS)->sort()->values()->all();

        $this->assertSame($expected, $seeded);
    }

    public function test_every_expected_flag_key_from_the_registry_is_present_and_no_others(): void
    {
        $seeded = FeatureFlag::query()->pluck('flag_key')->sort()->values()->all();
        $expected = collect(self::EXPECTED_FLAG_KEYS)->sort()->values()->all();

        $this->assertSame($expected, $seeded);
    }

    public function test_every_gate_seeds_closed(): void
    {
        // tasks.md's own NOT TESTED note: no gate has a recorded current
        // value yet — none should seed as already active.
        $this->assertSame(0, FeatureGate::query()->where('state', '!=', 'closed')->count());
    }

    public function test_only_preneed_interest_flag_defaults_enabled(): void
    {
        // feature-flag-registry.md: every flag defaults Off except
        // `feature.preneed_interest` (On).
        $enabled = FeatureFlag::query()->where('default_enabled', true)->pluck('flag_key')->all();

        $this->assertSame(['feature.preneed_interest'], $enabled);
    }

    public function test_g_pay_01_gate_row_matches_the_registry(): void
    {
        $gate = FeatureGate::query()->findOrFail('G-PAY-01');

        $this->assertSame('Online payment', $gate->capability);
        $this->assertSame('Activation', $gate->type);
        $this->assertSame('Finance/Engineering', $gate->owner);
        $this->assertSame('closed', $gate->state);
    }

    public function test_flags_sharing_a_gate_both_reference_it(): void
    {
        // feature.grave_search and feature.grave_reminders both name
        // G-DATA-01 as their prerequisite in feature-flag-registry.md.
        $this->assertSame('G-DATA-01', FeatureFlag::query()->findOrFail('feature.grave_search')->prerequisite_gate_id);
        $this->assertSame('G-DATA-01', FeatureFlag::query()->findOrFail('feature.grave_reminders')->prerequisite_gate_id);
    }

    public function test_flags_with_a_non_single_gate_prerequisite_carry_a_note_instead_of_a_gate_id(): void
    {
        $preneedInterest = FeatureFlag::query()->findOrFail('feature.preneed_interest');
        $this->assertNull($preneedInterest->prerequisite_gate_id);
        $this->assertSame('Approved interest flow', $preneedInterest->prerequisite_note);

        $plotInventory = FeatureFlag::query()->findOrFail('feature.plot_inventory');
        $this->assertNull($plotInventory->prerequisite_gate_id);
        $this->assertSame('G-CAP-01/G-PLOT-01', $plotInventory->prerequisite_note);
    }

    public function test_gates_with_no_single_named_owner_in_the_registry_are_left_null_not_invented(): void
    {
        foreach (['G-CAP-01', 'G-PLOT-01', 'G-RATE-01', 'G-EXT-01'] as $gateId) {
            $this->assertNull(
                FeatureGate::query()->findOrFail($gateId)->owner,
                "Expected {$gateId}.owner to be null — see the seed migration's derivation notes."
            );
        }
    }
}

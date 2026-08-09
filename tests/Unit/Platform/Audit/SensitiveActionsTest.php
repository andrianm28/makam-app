<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Audit;

use App\Platform\Audit\SensitiveActions;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * AC3: the explicit, named sensitive-action list. Pure unit coverage —
 * no database.
 */
final class SensitiveActionsTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function sensitiveActionProvider(): array
    {
        return [
            'DITOLAK' => ['DITOLAK'],
            'plot override' => ['PLOT_OVERRIDE'],
            'tariff-source change' => ['TARIFF_SOURCE_CHANGE'],
            'gate change' => ['GATE_CHANGE'],
            'manual payment verification' => ['PAYMENT_MANUAL_VERIFICATION'],
            'certificate revoke' => ['CERTIFICATE_REVOKE'],
            'vendor payout' => ['VENDOR_PAYOUT'],
            'journal reversal' => ['JOURNAL_REVERSAL'],
            'price version recorded' => ['PRICE_VERSION_RECORDED'],
            'service definition price version recorded' => ['SERVICE_DEFINITION_PRICE_VERSION_RECORDED'],
        ];
    }

    #[DataProvider('sensitiveActionProvider')]
    public function test_each_requirements_named_sensitive_action_requires_a_reason(string $action): void
    {
        $this->assertTrue(SensitiveActions::requiresReason($action));
    }

    public function test_an_action_not_on_the_list_does_not_require_a_reason(): void
    {
        $this->assertFalse(SensitiveActions::requiresReason('booking.updated'));
    }

    public function test_the_check_is_case_sensitive_and_does_not_infer_sensitivity_from_the_action_name(): void
    {
        // Deliberately not "any action containing OVERRIDE" — a
        // lowercase or differently-cased variant of a real sensitive
        // action must not silently match, because that would defeat
        // the closed-list intent documented on the class itself.
        $this->assertFalse(SensitiveActions::requiresReason('plot_override'));
        $this->assertFalse(SensitiveActions::requiresReason('ditolak'));
    }

    /**
     * FIXED 26 Jul 2026 — first real CI run: this test hardcoded "exactly
     * the seven requirements.md-named actions," but Sprint 3 Batch 3.6
     * (S3-T2, the MFA subsystem) deliberately added an eighth,
     * `MFA_RESET` — a genuine, documented addition (see
     * `SensitiveActions`'s own updated class doc comment for why only
     * that one MFA action, not all four, requires a reason), not a
     * regression. Renamed and updated rather than left red. Wave 0c later
     * added `PRICE_VERSION_RECORDED` as the financial price-version action.
     * The ServiceCatalog action uses its domain-qualified emitted name too.
     */
    public function test_the_list_contains_the_requirements_named_actions_plus_mfa_reset_and_price_version_recorded(): void
    {
        $this->assertSame(
            [
                'DITOLAK',
                'PLOT_OVERRIDE',
                'TARIFF_SOURCE_CHANGE',
                'GATE_CHANGE',
                'PAYMENT_MANUAL_VERIFICATION',
                'CERTIFICATE_REVOKE',
                'VENDOR_PAYOUT',
                'JOURNAL_REVERSAL',
                'PRICE_VERSION_RECORDED',
                'SERVICE_DEFINITION_PRICE_VERSION_RECORDED',
                'MFA_RESET',
            ],
            SensitiveActions::ACTIONS
        );
    }
}

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
            'document delete' => ['DOCUMENT_DELETE'],
            'payment refund' => ['PAYMENT_REFUND'],
            'payment chargeback' => ['PAYMENT_CHARGEBACK'],
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
     * regression. Renamed and updated rather than left red.
     *
     * UPDATED 11 Aug 2026 — platform-payment-adapter Task 6 (Wave 1d
     * Append-Correction) added `PAYMENT_REFUND`/`PAYMENT_CHARGEBACK`: the
     * only writers of `payment_reversals` rows
     * (`App\Platform\Payment\Actions\RecordRefund`/`RecordChargeback`) are
     * explicit, human-initiated financial operations, the same risk
     * category as `VENDOR_PAYOUT` already on this list — another genuine,
     * documented addition, not a regression.
     */
    public function test_the_list_contains_the_requirements_named_actions_plus_mfa_reset_document_delete_and_payment_reversals(): void
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
                'MFA_RESET',
                'DOCUMENT_DELETE',
                'PAYMENT_REFUND',
                'PAYMENT_CHARGEBACK',
            ],
            SensitiveActions::ACTIONS
        );
    }
}

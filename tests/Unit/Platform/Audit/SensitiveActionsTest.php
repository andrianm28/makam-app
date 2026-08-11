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
            'journal reversal' => ['JOURNAL_REVERSAL'],
            'price version recorded' => ['PRICE_VERSION_RECORDED'],
            'service definition price version recorded' => ['SERVICE_DEFINITION_PRICE_VERSION_RECORDED'],
            'reconciliation exception resolved' => ['RECONCILIATION_EXCEPTION_RESOLVED'],
            'payment refund' => ['PAYMENT_REFUND'],
            'payment chargeback' => ['PAYMENT_CHARGEBACK'],
            'role grant' => ['ROLE_GRANT'],
            'role revoke' => ['ROLE_REVOKE'],
            'scope grant' => ['SCOPE_GRANT'],
            'scope revoke' => ['SCOPE_REVOKE'],
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
     *
     * UPDATED 10 Aug 2026 — Task 5 of the `platform-financial-ledger` lane
     * appended `RECONCILIATION_EXCEPTION_RESOLVED` under the user-approved
     * Wave 1c ruling in
     * `docs/superpowers/plans/2026-08-10-wave1b-financial-decisions.md`. This
     * assertion is an exact-list check on purpose, so every growth of that
     * array has to arrive here with a stated authority rather than slipping in.
     *
     * UPDATED 11 Aug 2026 — platform-payment-adapter Task 6 (Wave 1d
     * Append-Correction) added `PAYMENT_REFUND`/`PAYMENT_CHARGEBACK`: the
     * only writers of `payment_reversals` rows
     * (`App\Platform\Payment\Actions\RecordRefund`/`RecordChargeback`) are
     * explicit, human-initiated financial operations, the same risk
     * category as `VENDOR_PAYOUT` already on this list — another genuine,
     * documented addition, not a regression.
     *
     * UPDATED 12 Aug 2026 — Task 4 of the `platform-identity-seam` lane
     * (design doc decision 5) added `ROLE_GRANT`/`ROLE_REVOKE`/
     * `SCOPE_GRANT`/`SCOPE_REVOKE`: the only writers of
     * `actor_role_assignments`/`scope_assignments` rows
     * (`App\Platform\IdentityAccess\Roles\Actions\GrantActorRole`/
     * `RevokeActorRole`, `App\Platform\IdentityAccess\Scopes\Actions\
     * GrantScopeAssignment`/`RevokeScopeAssignment`) grant or withdraw a
     * role or record-visibility scope — the same privilege-escalation
     * category as `MFA_RESET`/`CERTIFICATE_REVOKE` already on this list —
     * another genuine, documented addition, not a regression.
     */
    public function test_the_list_contains_the_requirements_named_actions_plus_the_documented_additions(): void
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
                'DOCUMENT_DELETE',
                'RECONCILIATION_EXCEPTION_RESOLVED',
                'PAYMENT_REFUND',
                'PAYMENT_CHARGEBACK',
                'ROLE_GRANT',
                'ROLE_REVOKE',
                'SCOPE_GRANT',
                'SCOPE_REVOKE',
            ],
            SensitiveActions::ACTIONS
        );
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Reports;

use App\Livewire\Admin\Reports\OutgoingPaymentsReportPanel;
use App\Models\User;
use App\Platform\Audit\AuditSource;
use App\Platform\FinancialLedger\Actions\ManualPayout;
use App\Platform\FinancialLedger\Actions\VendorPayable;
use App\Platform\FinancialLedger\Contracts\PayoutProofVerifier;
use App\Platform\FinancialLedger\FinanceLedgerReadAuthorizer;
use App\Platform\FinancialLedger\Journal;
use App\Platform\FinancialLedger\Models\Payout;
use App\Platform\FinancialLedger\Money;
use App\Platform\FinancialLedger\PayoutProof;
use App\Platform\FinancialLedger\VendorPayableAssessmentTrigger;
use App\Platform\FinancialLedger\VendorPayableEligibility;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `OutgoingPaymentsReportPanel` — ADM-090/AC7's outgoing-payments-by-period
 * report. Payout fixtures go through the real `VendorPayable::assess()` +
 * `ManualPayout::pay()` workflow (`ManualPayoutTest`'s own fixture shape),
 * not a raw insert, so the `payouts.payable_id` foreign key and the
 * vendor-payable/payout consistency invariant stay real. Same
 * `LedgerReadAuthorizer` scoping fixture as `ReceiptsReportPageTest`.
 */
final class OutgoingPaymentsReportPanelTest extends TestCase
{
    use RefreshDatabase;

    private const string VENDOR = 'vendor-1';

    private const string ENTITY = 'badan-usaha-1';

    private const string OTHER_ENTITY = 'badan-usaha-2';

    private const string APPROVER = '77';

    private const string APPROVER_ROLE = FinanceLedgerReadAuthorizer::FINANCE_ROLE;

    private const int AMOUNT = 250_000;

    public function test_a_panel_user_without_finance_authority_cannot_access_the_page(): void
    {
        $user = User::factory()->create();
        $this->actAsActor($user, roles: []);

        $this->assertFalse(OutgoingPaymentsReportPanel::canAccess());

        Livewire::actingAs($user)->test(OutgoingPaymentsReportPanel::class)->assertForbidden();
    }

    public function test_an_empty_period_renders_the_required_empty_state(): void
    {
        $user = $this->authorisedFinanceUser();

        $component = Livewire::actingAs($user)->test(OutgoingPaymentsReportPanel::class);

        $this->assertSame(CarbonImmutable::now()->format('Y-m'), $component->get('period'));
        $component->assertSee('Belum ada pembayaran keluar pada periode ini')
            ->assertCount('reportRows', 0);
    }

    public function test_a_seeded_payout_renders_in_the_report(): void
    {
        $user = $this->authorisedFinanceUser();
        $this->payVendor();

        $component = Livewire::actingAs($user)->test(OutgoingPaymentsReportPanel::class);

        $component->assertCount('reportRows', 1)
            ->assertSet('totalMinor', self::AMOUNT);
    }

    public function test_the_report_covers_only_the_badan_usaha_the_actor_is_granted(): void
    {
        $user = $this->authorisedFinanceUser();
        $this->payVendor();
        $this->payVendor(entityRef: self::OTHER_ENTITY, sourceId: 'order-other');

        $component = Livewire::actingAs($user)->test(OutgoingPaymentsReportPanel::class);

        $component->assertCount('reportRows', 1)
            ->assertSet('totalMinor', self::AMOUNT);
    }

    public function test_a_payout_outside_the_period_is_excluded(): void
    {
        $user = $this->authorisedFinanceUser();
        $payout = $this->payVendor();

        Payout::query()->where('id', $payout->id)->update([
            'occurred_at' => CarbonImmutable::now()->subMonths(2),
        ]);

        $component = Livewire::actingAs($user)->test(OutgoingPaymentsReportPanel::class);

        $component->assertCount('reportRows', 0);
    }

    public function test_a_malformed_period_renders_the_inline_validation_error(): void
    {
        $user = $this->authorisedFinanceUser();

        $component = Livewire::actingAs($user)->test(OutgoingPaymentsReportPanel::class);

        $component->set('period', '2026-13')->call('loadReport');

        $component->assertSee('Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.')
            ->assertCount('reportRows', 0)
            ->assertHasErrors('period')
            ->assertSet('error', 'Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.');
    }

    private function authorisedFinanceUser(): User
    {
        $user = User::factory()->create();

        $this->actAsActor($user);
        $this->grant($user);

        return $user;
    }

    /**
     * @param  list<string>  $roles
     */
    private function actAsActor(User $user, array $roles = [FinanceLedgerReadAuthorizer::FINANCE_ROLE]): void
    {
        $this->app->instance(ActorContext::class, new ActorContext(
            identityReference: (string) $user->getAuthIdentifier(),
            roles: $roles,
            lastAuthenticatedAt: CarbonImmutable::now(),
        ));
    }

    private function grant(User $user, string $entityRef = self::ENTITY): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->getAuthIdentifier(),
            'entity_type' => ScopeEntityType::BUSINESS_ENTITY,
            'entity_id' => $entityRef,
            'grant_level' => ScopeGrantLevel::PRIVILEGED,
            'revoked_at' => null,
        ]);
    }

    /**
     * Runs the real payout workflow as its own, separately authorised
     * approver actor — the approver's own vendor/reauthentication grants are
     * unrelated to the report actor's business-entity grant under test.
     */
    private function payVendor(string $entityRef = self::ENTITY, string $sourceId = 'order-1'): Payout
    {
        $approverContext = new ActorContext(
            identityReference: self::APPROVER,
            roles: [self::APPROVER_ROLE],
            lastAuthenticatedAt: CarbonImmutable::now(),
        );

        $payable = (new VendorPayable(actorContext: ActorContext::guest()))->assess(
            vendorId: self::VENDOR,
            entityRef: $entityRef,
            sourceType: 'marketplace_order',
            sourceId: $sourceId,
            amount: new Money(self::AMOUNT),
            eligibility: new VendorPayableEligibility(
                orderPaid: true,
                fulfilmentEvidenceAccepted: true,
                disputeWindowEndsAt: CarbonImmutable::now()->subDay(),
            ),
            trigger: VendorPayableAssessmentTrigger::UnattendedAssessment,
        );

        ScopeAssignment::query()->create([
            'actor_identifier' => self::APPROVER,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => self::VENDOR,
            'grant_level' => ScopeGrantLevel::PRIVILEGED,
            'revoked_at' => null,
        ]);

        (new ReauthenticationService)->satisfy(
            actorRef: self::APPROVER,
            actorRole: self::APPROVER_ROLE,
            reason: ManualPayout::REAUTHENTICATION_REASON,
            source: AuditSource::Panel,
        );

        return (new ManualPayout(
            actorContext: $approverContext,
            proofVerifier: $this->acceptingProofVerifier(),
            journal: new Journal,
        ))->pay(
            payableId: (string) $payable->id,
            amount: new Money(self::AMOUNT),
            proof: new PayoutProof(
                documentKind: 'PAYMENT_PROOF',
                documentReference: 'document-vault-ref-1',
            ),
            approverRef: null,
            approverRole: null,
            reason: 'Approved manual bank transfer for completed vendor work.',
        );
    }

    private function acceptingProofVerifier(): PayoutProofVerifier
    {
        return new class implements PayoutProofVerifier
        {
            public function assertAcceptedPrivateRecordScoped(PayoutProof $proof, string $recordType, string $recordId): void
            {
                // Accepts unconditionally — this test never exercises proof
                // rejection, only the report's own read/scope behaviour.
            }
        };
    }
}

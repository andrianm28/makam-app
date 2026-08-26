<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Reports;

use App\Livewire\Admin\Reports\ReceiptsReportPanel;
use App\Models\User;
use App\Platform\FinancialLedger\FinanceLedgerReadAuthorizer;
use App\Platform\FinancialLedger\Journal;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `ReceiptsReportPanel` — ADM-090/AC7's receipts-by-period report. Same
 * authorization/scoping fixture shape as `FinanceReportPanelTest` (this page
 * shares the identical `LedgerReadAuthorizer` gate), because AC10's
 * business-entity half genuinely applies here — receipts are journal-derived
 * money. Required states (§6) covered: access denial, empty, success with a
 * receipt row, cross-entity exclusion, and the inline validation error.
 */
final class ReceiptsReportPanelTest extends TestCase
{
    use RefreshDatabase;

    private const string ENTITY = 'badan-usaha-1';

    private const string OTHER_ENTITY = 'badan-usaha-2';

    public function test_a_panel_user_without_finance_authority_cannot_access_the_page(): void
    {
        $user = User::factory()->create();
        $this->actAsActor($user, roles: []);

        $this->assertFalse(ReceiptsReportPanel::canAccess());

        Livewire::actingAs($user)->test(ReceiptsReportPanel::class)->assertForbidden();
    }

    public function test_a_finance_actor_without_a_business_entity_grant_cannot_access_the_page(): void
    {
        $user = User::factory()->create();
        $this->actAsActor($user);

        $this->assertFalse(ReceiptsReportPanel::canAccess());
    }

    public function test_an_empty_ledger_renders_the_required_empty_state(): void
    {
        $user = $this->authorisedFinanceUser();

        $component = Livewire::actingAs($user)->test(ReceiptsReportPanel::class);

        $this->assertSame(CarbonImmutable::now()->format('Y-m'), $component->get('period'));
        $component->assertSee('Belum ada penerimaan pada periode ini')
            ->assertCount('reportRows', 0);
    }

    public function test_a_seeded_receipt_renders_in_the_report(): void
    {
        $user = $this->authorisedFinanceUser();
        $this->seedReceipt();

        $component = Livewire::actingAs($user)->test(ReceiptsReportPanel::class);

        $component->assertCount('reportRows', 1)
            ->assertSet('totalMinor', 100_000);
    }

    public function test_the_report_covers_only_the_badan_usaha_the_actor_is_granted(): void
    {
        $user = $this->authorisedFinanceUser();
        $this->seedReceipt();
        $this->seedReceipt(entityRef: self::OTHER_ENTITY, suffix: '-other', amountMinor: 777_000);

        $component = Livewire::actingAs($user)->test(ReceiptsReportPanel::class);

        $component->assertCount('reportRows', 1)
            ->assertSet('totalMinor', 100_000);
    }

    public function test_a_malformed_period_renders_the_inline_validation_error(): void
    {
        $user = $this->authorisedFinanceUser();

        $component = Livewire::actingAs($user)->test(ReceiptsReportPanel::class);

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

    private function seedReceipt(
        string $entityRef = self::ENTITY,
        string $suffix = '',
        int $amountMinor = 100_000,
    ): void {
        $this->journal()->post(
            businessKey: 'payment:provider-event-receipts-report'.$suffix,
            entityRef: $entityRef,
            sourceType: 'payment',
            sourceId: 'provider-event-receipts-report'.$suffix,
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => $amountMinor],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => $amountMinor],
            ],
            correlationId: 'trace-receipts-report'.$suffix,
            occurredAt: CarbonImmutable::now()->toISOString(),
        );
    }

    private function journal(): Journal
    {
        return $this->app->make(Journal::class);
    }
}

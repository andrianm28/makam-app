<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Reports;

use App\Livewire\Admin\Reports\FinanceReportPanel;
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
 * `FinanceReportPanel` — the "Laporan Keuangan" tab of the consolidated
 * `App\Filament\Admin\Pages\Reports` admin page (formerly the standalone
 * `FinanceReports` Filament page; moved here verbatim by the six-report-page
 * consolidation) — the report's and the export's mount point.
 * `PasswordReauthenticationPageTest` is the sibling precedent for the
 * Filament page test shape this component's tests still follow, even though
 * it is now tested as a plain nested Livewire component rather than a
 * top-level Filament page. Required states (§6) covered here: empty (the
 * exact "Belum ada
 * transaksi pada periode ini" copy), success (rows + metadata), validation
 * error (inline, malformed period), and the export button's link to the gated
 * route.
 *
 * Every success case must bind a `finance` `ActorContext` and create a real
 * `scope_assignments` grant, because the component is gated by the same
 * policy the export is. Its own `canAccess()` re-check on `mount()`/
 * `hydrate()` — and `Reports`'s own `@livewire()` visibility guard — are what
 * keep another panel user from seeing every badan usaha's account totals; see
 * `ReportsPageTest` for the consolidated page's own cross-tab visibility
 * coverage of this exact boundary.
 */
final class FinanceReportPanelTest extends TestCase
{
    use RefreshDatabase;

    private const string ENTITY = 'badan-usaha-1';

    private const string OTHER_ENTITY = 'badan-usaha-2';

    public function test_a_panel_user_without_finance_authority_cannot_access_the_page(): void
    {
        $user = User::factory()->create();
        $this->actAsActor($user, roles: []);

        $this->assertFalse(FinanceReportPanel::canAccess());

        Livewire::actingAs($user)->test(FinanceReportPanel::class)->assertForbidden();
    }

    public function test_a_finance_actor_without_a_business_entity_grant_cannot_access_the_page(): void
    {
        $user = User::factory()->create();
        $this->actAsActor($user);

        $this->assertFalse(FinanceReportPanel::canAccess());
    }

    public function test_a_revoked_grant_does_not_grant_access(): void
    {
        $user = User::factory()->create();
        $this->actAsActor($user);
        $this->grant($user, revoked: true);

        $this->assertFalse(FinanceReportPanel::canAccess());
    }

    public function test_an_empty_ledger_renders_the_required_empty_state_with_the_current_period(): void
    {
        $user = $this->authorisedFinanceUser();

        $component = Livewire::actingAs($user)->test(FinanceReportPanel::class);

        $this->assertSame(CarbonImmutable::now()->format('Y-m'), $component->get('period'));
        $component->assertSee('Belum ada transaksi pada periode ini')
            ->assertSet('source', 'journal')
            ->assertCount('reportRows', 0);
    }

    public function test_a_seeded_period_renders_rows_metadata_and_totals(): void
    {
        $user = $this->authorisedFinanceUser();
        $this->seedCurrentMonthLedger();

        $component = Livewire::actingAs($user)->test(FinanceReportPanel::class);

        $component->assertSee('Kode akun')
            ->assertSee('TOTAL')
            ->assertSee('Sumber: journal')
            ->assertCount('reportRows', 2)
            ->assertSet('debitTotal', 100_000)
            ->assertSet('creditTotal', 100_000);
    }

    /**
     * The page shows rupiah, not sen. `amount_minor` is 100_000 sen = Rp
     * 1.000 — the table used to print `100.000` under a "Debit (IDR)" header,
     * one hundred times the real figure. (`Money::format()` omits the fraction
     * when it is zero, hence `Rp 1.000` rather than `Rp 1.000,00`.)
     */
    public function test_money_is_rendered_through_the_modules_own_presenter(): void
    {
        $user = $this->authorisedFinanceUser();
        $this->seedCurrentMonthLedger();

        Livewire::actingAs($user)
            ->test(FinanceReportPanel::class)
            ->assertSee('Rp 1.000')
            ->assertDontSee('Debit (IDR)');
    }

    /**
     * Query-level scope on the page, not only on the export: a batch for a
     * badan usaha this actor holds no grant for must not reach the table.
     */
    public function test_the_report_covers_only_the_badan_usaha_the_actor_is_granted(): void
    {
        $user = $this->authorisedFinanceUser();
        $this->seedCurrentMonthLedger();
        $this->seedCurrentMonthLedger(
            entityRef: self::OTHER_ENTITY,
            suffix: '-other',
            amountMinor: 777_000,
        );

        Livewire::actingAs($user)
            ->test(FinanceReportPanel::class)
            ->assertSet('debitTotal', 100_000)
            ->assertDontSee('Rp 7.770');
    }

    public function test_a_malformed_period_renders_the_inline_validation_error(): void
    {
        $user = $this->authorisedFinanceUser();

        $component = Livewire::actingAs($user)->test(FinanceReportPanel::class);

        $component->set('period', '2026-13')->call('loadReport');

        $component->assertSee('Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.')
            ->assertCount('reportRows', 0)
            ->assertHasErrors('period')
            ->assertSet('error', 'Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.');
    }

    public function test_reloading_after_fixing_a_bad_period_recovers_the_report(): void
    {
        $user = $this->authorisedFinanceUser();
        $this->seedCurrentMonthLedger();

        $component = Livewire::actingAs($user)->test(FinanceReportPanel::class);

        $component->set('period', '2026-13')->call('loadReport')->assertCount('reportRows', 0);

        $component->set('period', CarbonImmutable::now()->format('Y-m'))->call('loadReport');

        $component->assertSet('error', '')
            ->assertHasNoErrors('period')
            ->assertCount('reportRows', 2);
    }

    public function test_the_export_button_links_to_the_gated_exports_route(): void
    {
        $user = $this->authorisedFinanceUser();

        Livewire::actingAs($user)
            ->test(FinanceReportPanel::class)
            ->assertSeeHtml(route('admin.finance.exports', ['period' => CarbonImmutable::now()->format('Y-m')]));
    }

    /**
     * The button used to link with only `period`, so a page scoped to one
     * badan usaha handed the export a wider scope than the table above it.
     */
    public function test_the_export_button_carries_the_pages_own_entity_scope(): void
    {
        $user = $this->authorisedFinanceUser();

        Livewire::actingAs($user)
            ->test(FinanceReportPanel::class)
            ->set('entityRef', self::ENTITY)
            ->call('loadReport')
            ->assertSeeHtml(e(route('admin.finance.exports', [
                'period' => CarbonImmutable::now()->format('Y-m'),
                'entity' => self::ENTITY,
            ])));
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

    private function grant(User $user, string $entityRef = self::ENTITY, bool $revoked = false): void
    {
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->getAuthIdentifier(),
            'entity_type' => ScopeEntityType::BUSINESS_ENTITY,
            'entity_id' => $entityRef,
            'grant_level' => ScopeGrantLevel::PRIVILEGED,
            'revoked_at' => $revoked ? CarbonImmutable::now() : null,
        ]);
    }

    private function seedCurrentMonthLedger(
        string $entityRef = self::ENTITY,
        string $suffix = '',
        int $amountMinor = 100_000,
    ): void {
        $this->journal()->post(
            businessKey: 'payment:provider-event-report-page-1'.$suffix,
            entityRef: $entityRef,
            sourceType: 'payment',
            sourceId: 'provider-event-report-page-1'.$suffix,
            entries: [
                ['account' => '7000', 'direction' => 'DR', 'amountMinor' => $amountMinor],
                ['account' => '4000', 'direction' => 'CR', 'amountMinor' => $amountMinor],
            ],
            correlationId: 'trace-report-page-1'.$suffix,
            occurredAt: CarbonImmutable::now()->toISOString(),
        );
    }

    private function journal(): Journal
    {
        return $this->app->make(Journal::class);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Reports;

use App\Filament\Admin\Pages\Reports;
use App\Models\User;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * `App\Filament\Admin\Pages\Reports` — the consolidated "Laporan" admin
 * page that replaced the six former separate report pages (`FinanceReports`,
 * `OrdersReport`, `ReceiptsReport`, `OutgoingPaymentsReport`,
 * `VendorPerformanceReport`, `RenewalPeriodReport`).
 *
 * This is the file proving the correctness constraint the consolidation
 * exists to get right: the three `LedgerReadAuthorizer`-gated tabs
 * (Keuangan, Penerimaan, Pembayaran Keluar) must never appear — as a tab
 * button OR as rendered content — for an actor who can reach the page
 * (`Reports::canAccess()`'s `MasterDataAdminAuthorizerContract` floor) but
 * cannot pass the stricter ledger gate. Individual tabs' own report content
 * and CSV/export behaviour are covered by each panel's own
 * `*ReportPanelTest` — this file only covers cross-tab visibility, the
 * access floor, and the deep-link (`?tab=`) behaviour.
 */
final class ReportsPageTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private const string ENTITY = 'badan-usaha-1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_and_bare_users_are_denied(): void
    {
        $this->assertFalse(Reports::canAccess());

        $this->actingAs(User::factory()->create());
        $this->assertFalse(Reports::canAccess());

        Livewire::actingAs(User::factory()->create())->test(Reports::class)->assertForbidden();
    }

    public function test_vendor_role_cannot_access(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::VENDOR);
        $this->actingAs($user);

        $this->assertFalse(Reports::canAccess());
    }

    /**
     * `operator` passes the page's own floor (`MasterDataAdminAuthorizerContract`)
     * but holds neither the `finance` role nor any `BUSINESS_ENTITY` grant, so
     * none of the three `LedgerReadAuthorizer`-gated tabs may appear — as a
     * tab button, or as rendered content.
     */
    public function test_an_operator_sees_the_page_with_only_the_master_data_gated_tabs(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $this->assertTrue(Reports::canAccess());

        $component = Livewire::actingAs($user)->test(Reports::class);

        $component->assertOk()
            ->assertSee('Laporan Pesanan')
            ->assertSee('Laporan Kinerja Vendor')
            ->assertSee('Laporan Perpanjangan')
            ->assertDontSee('Laporan Keuangan')
            ->assertDontSee('Laporan Penerimaan')
            ->assertDontSee('Laporan Pembayaran Keluar')
            // Content markers unique to the ledger-gated tabs' own tables —
            // proving the CHILD components themselves were never mounted,
            // not merely that their tab buttons are missing.
            ->assertDontSee('Kode akun')
            ->assertDontSee('Referensi jurnal');

        // The default landing tab for an actor with no ledger access must be
        // one they can actually see, never a blank/forbidden default.
        $component->assertSet('activeTab', 'pesanan');
    }

    /**
     * The subtle case `AGENTS.md` flags this whole batch for: `finance` is
     * itself one of `MasterDataAdminAuthorizerContract`'s four allowed
     * roles, so a `finance` actor with NO `BUSINESS_ENTITY` grant still
     * reaches the page — but must still be refused the ledger tabs, because
     * `LedgerReadAuthorizer` requires the grant too, not just the role.
     */
    public function test_a_finance_actor_without_a_business_entity_grant_cannot_see_ledger_tabs(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);

        $this->assertTrue(Reports::canAccess());

        Livewire::actingAs($user)->test(Reports::class)
            ->assertOk()
            ->assertDontSee('Laporan Keuangan')
            ->assertDontSee('Kode akun');
    }

    /**
     * A `finance` actor WITH an active privileged `BUSINESS_ENTITY` grant
     * passes both gates and must see all six tabs, with real ledger content
     * rendering once switched to.
     */
    public function test_a_fully_privileged_finance_actor_sees_all_six_tabs(): void
    {
        $user = $this->authorisedFinanceUser();

        $component = Livewire::actingAs($user)->test(Reports::class);

        $component->assertOk()
            ->assertSee('Laporan Keuangan')
            ->assertSee('Laporan Pesanan')
            ->assertSee('Laporan Penerimaan')
            ->assertSee('Laporan Pembayaran Keluar')
            ->assertSee('Laporan Kinerja Vendor')
            ->assertSee('Laporan Perpanjangan');
    }

    /**
     * The default tab in this codebase's own historical nav order was
     * Keuangan first — confirm a fully privileged actor lands there and
     * sees the real (empty-state) ledger content, not just the tab label.
     */
    public function test_a_fully_privileged_finance_actor_lands_on_the_finance_tab_by_default_with_real_content(): void
    {
        $user = $this->authorisedFinanceUser();

        Livewire::actingAs($user)->test(Reports::class)
            ->assertSet('activeTab', 'keuangan')
            ->assertSee('Belum ada transaksi pada periode ini');
    }

    /**
     * Switching tabs server-side via the exact action the tab buttons call
     * (`setActiveTab`) must move between tabs for an authorized actor.
     */
    public function test_switching_tabs_renders_the_newly_selected_tabs_content(): void
    {
        $user = $this->authorisedFinanceUser();

        Livewire::actingAs($user)->test(Reports::class)
            ->call('setActiveTab', 'pesanan')
            ->assertSet('activeTab', 'pesanan')
            ->assertSee('Belum ada pesanan pada periode ini');
    }

    /**
     * The tamper case: an `operator` actor forces `setActiveTab('keuangan')`
     * — the same call the (absent, for them) finance tab button would have
     * made. The action must refuse to move onto a tab this actor cannot see,
     * never mount `FinanceReportPanel`, and never leak its content.
     */
    public function test_forcing_the_active_tab_to_a_hidden_tab_is_refused(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        Livewire::actingAs($user)->test(Reports::class)
            ->call('setActiveTab', 'keuangan')
            ->assertSet('activeTab', 'pesanan')
            ->assertDontSee('Kode akun');
    }

    /**
     * A stale/tampered `?tab=keuangan` deep link (e.g. a bookmarked
     * `FinancialOverviewWidget` stat-card URL from before a grant was
     * revoked) must land on a page the actor CAN see, never a 403 for the
     * whole page and never a leak of the tab they asked for.
     */
    public function test_a_deep_link_to_a_hidden_tab_falls_back_to_a_visible_tab(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $response = $this->get(Reports::getUrl(['tab' => 'keuangan']));

        $response->assertOk()
            ->assertDontSee('Kode akun', escape: false)
            ->assertSee('Belum ada pesanan pada periode ini', escape: false);
    }

    /**
     * The mirror of the above for a privileged actor: the same deep link
     * must land exactly on the requested, authorized tab.
     */
    public function test_a_deep_link_to_a_visible_tab_lands_on_it(): void
    {
        $user = $this->authorisedFinanceUser();

        $response = $this->get(Reports::getUrl(['tab' => 'keuangan']));

        $response->assertOk()->assertSee('Belum ada transaksi pada periode ini', escape: false);
    }

    private function authorisedFinanceUser(): User
    {
        $user = User::factory()->create();

        $this->grantRoleTo($user, ActorRole::FINANCE);
        $this->actingAs($user);

        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->getAuthIdentifier(),
            'entity_type' => ScopeEntityType::BUSINESS_ENTITY,
            'entity_id' => self::ENTITY,
            'grant_level' => ScopeGrantLevel::PRIVILEGED,
            'revoked_at' => null,
        ]);

        $this->app->forgetInstance(ActorContext::class);

        return $user;
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Reports;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalStatus;
use App\Livewire\Admin\Reports\RenewalPeriodReportPanel;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * `RenewalPeriodReportPanel` — ADM-090/AC7's renewal-by-period report. Filters by
 * `target_due_period`, not `created_at` — see `RenewalReport`'s doc block
 * for why. `canAccess()` is role-only, the same
 * `MasterDataAdminAuthorizerContract` gate `RenewalOrderResource` uses.
 */
final class RenewalPeriodReportPanelTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_guests_and_bare_users_are_denied(): void
    {
        $this->assertFalse(RenewalPeriodReportPanel::canAccess());
        $this->actingAs(User::factory()->create());
        $this->assertFalse(RenewalPeriodReportPanel::canAccess());
    }

    public function test_back_office_roles_can_access(): void
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);
            $this->assertTrue(RenewalPeriodReportPanel::canAccess(), "role {$role} should access");
        }
    }

    public function test_an_empty_period_renders_the_required_empty_state(): void
    {
        $user = $this->authorisedUser();

        $component = Livewire::actingAs($user)->test(RenewalPeriodReportPanel::class);

        $this->assertSame(CarbonImmutable::now()->format('Y-m'), $component->get('period'));
        $component->assertSee('Belum ada perpanjangan jatuh tempo pada periode ini')
            ->assertCount('reportRows', 0);
    }

    public function test_renewals_due_in_the_period_are_grouped_by_status(): void
    {
        $user = $this->authorisedUser();

        $dueThisMonth = CarbonImmutable::now()->startOfMonth()->addDays(3)->toDateString();

        $this->makeRenewal($dueThisMonth, RenewalStatus::MENUNGGU_PEMBAYARAN);
        $this->makeRenewal($dueThisMonth, RenewalStatus::MENUNGGU_PEMBAYARAN);
        $this->makeRenewal($dueThisMonth, RenewalStatus::DIBAYAR);

        $component = Livewire::actingAs($user)->test(RenewalPeriodReportPanel::class);

        $component->assertCount('reportRows', 2)
            ->assertSet('total', 3);
    }

    public function test_a_renewal_due_outside_the_period_is_excluded(): void
    {
        $user = $this->authorisedUser();

        $dueNextMonth = CarbonImmutable::now()->addMonths(2)->startOfMonth()->toDateString();
        $this->makeRenewal($dueNextMonth, RenewalStatus::MENUNGGU_PEMBAYARAN);

        $component = Livewire::actingAs($user)->test(RenewalPeriodReportPanel::class);

        $component->assertSet('total', 0);
    }

    public function test_a_malformed_period_renders_the_inline_validation_error(): void
    {
        $user = $this->authorisedUser();

        $component = Livewire::actingAs($user)->test(RenewalPeriodReportPanel::class);

        $component->set('period', '2026-13')->call('loadReport');

        $component->assertSee('Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.')
            ->assertCount('reportRows', 0)
            ->assertHasErrors('period')
            ->assertSet('error', 'Format periode tidak valid. Gunakan format YYYY-MM, contohnya 2026-08.');
    }

    private function authorisedUser(): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);

        return $user;
    }

    private function makeRenewal(string $targetDuePeriod, string $status): Renewal
    {
        $grave = GraveRecord::factory()->create();

        return Renewal::query()->create([
            'grave_record_id' => $grave->getKey(),
            'target_due_period' => $targetDuePeriod,
            'reference' => 'PPJ-'.uniqid(),
            'status' => $status,
            'source' => 'online',
        ]);
    }
}

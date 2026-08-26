<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Reports;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Livewire\Admin\Reports\OrdersReportPanel;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * `OrdersReportPanel` — ADM-090/AC7's orders-by-period report. Required states
 * (§6) covered here: access denial for a bare user, access for every
 * back-office role (AC10's role half — see `OrderPeriodReport`'s doc block
 * for why the business-entity half does not apply to this table), empty,
 * success with counts grouped by status, and the inline validation error for
 * a malformed period.
 */
final class OrdersReportPanelTest extends TestCase
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
        $this->assertFalse(OrdersReportPanel::canAccess());
        $this->actingAs(User::factory()->create());
        $this->assertFalse(OrdersReportPanel::canAccess());
    }

    public function test_back_office_roles_can_access(): void
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);
            $this->assertTrue(OrdersReportPanel::canAccess(), "role {$role} should access");
        }
    }

    public function test_vendor_role_cannot_access(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::VENDOR);
        $this->actingAs($user);
        $this->assertFalse(OrdersReportPanel::canAccess());
    }

    public function test_an_empty_period_renders_the_required_empty_state(): void
    {
        $user = $this->authorisedUser();

        $component = Livewire::actingAs($user)->test(OrdersReportPanel::class);

        $this->assertSame(CarbonImmutable::now()->format('Y-m'), $component->get('period'));
        $component->assertSee('Belum ada pesanan pada periode ini')
            ->assertCount('reportRows', 0);
    }

    public function test_orders_in_the_current_period_are_grouped_by_status(): void
    {
        $user = $this->authorisedUser();

        $this->makeOrder(OrderStatus::MASUK);
        $this->makeOrder(OrderStatus::MASUK);
        $this->makeOrder(OrderStatus::SELESAI);

        $component = Livewire::actingAs($user)->test(OrdersReportPanel::class);

        $component->assertCount('reportRows', 2)
            ->assertSet('total', 3);
    }

    public function test_an_order_outside_the_period_is_excluded(): void
    {
        $user = $this->authorisedUser();

        $inPeriod = $this->makeOrder(OrderStatus::MASUK);
        $outsidePeriod = $this->makeOrder(OrderStatus::MASUK);

        Order::query()->where('id', $outsidePeriod->getKey())->update([
            'created_at' => CarbonImmutable::now()->subMonths(2),
        ]);

        $component = Livewire::actingAs($user)->test(OrdersReportPanel::class);

        $component->assertSet('total', 1);
    }

    public function test_a_malformed_period_renders_the_inline_validation_error(): void
    {
        $user = $this->authorisedUser();

        $component = Livewire::actingAs($user)->test(OrdersReportPanel::class);

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

    private function makeOrder(OrderStatus $status): Order
    {
        return Order::query()->create([
            'reference' => 'MK-2026-TEST-'.uniqid(),
            'product_type' => 'AT_NEED_SERVICE_ORDER',
            'status' => $status->value,
        ]);
    }
}

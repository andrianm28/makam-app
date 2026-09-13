<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\CareSubscription\Actions\CreateCarePlan;
use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\VendorFulfillment\Actions\CompleteTask;
use App\Domain\VendorFulfillment\Actions\CreateWorkOrder;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Filament\Vendor\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Filament\Vendor\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Models\User;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\ActorContextResolver;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Tests for Vendor WorkOrdersResource panel surface.
 *
 * 1. Access matrix: vendor role accesses the resource; customer/admin fail closed.
 * 2. Work order list/view renders.
 * 3. Complete task action.
 * 4. Vendor scoping: vendor sees only own work orders.
 */
final class VendorWorkOrdersTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        Filament::setCurrentPanel('vendor');
    }

    protected function tearDown(): void
    {
        Filament::setCurrentPanel(null);

        parent::tearDown();
    }

    private ?string $vendorId = null;

    private function vendorId(): string
    {
        return $this->vendorId ??= (string) Vendor::query()->create([
            'name' => 'Vendor Uji Coba',
            'is_active' => true,
        ])->id;
    }

    private function actingUserWithRole(string $role): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, $role);

        if ($role === ActorRole::VENDOR) {
            ScopeAssignment::query()->create([
                'actor_identifier' => (string) $user->id,
                'entity_type' => ScopeEntityType::VENDOR,
                'entity_id' => $this->vendorId(),
            ]);
        }

        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        return $user;
    }

    private function forgetResolvedActorContext(): void
    {
        $this->app->forgetInstance(ActorContext::class);
        $this->app->forgetInstance(ActorContextResolver::class);
    }

    private function makeCarePlan(): CarePlan
    {
        return app(CreateCarePlan::class)(
            name: 'Perawatan Bulanan Standar',
            productCode: 'GRAVE_CARE_MONTHLY',
            frequency: CarePlanFrequency::Monthly,
            priceMinor: 250000,
        );
    }

    private function makeGravePlot(): GravePlot
    {
        return GravePlot::query()->create([
            'block_id' => 'B1',
            'slot' => '001',
            'plot_state' => 'available',
        ]);
    }

    private function makeWorkOrder(): WorkOrder
    {
        $plan = $this->makeCarePlan();

        return app(CreateWorkOrder::class)(
            $plan,
            vendorId: $this->vendorId(),
            checklistItems: ['Membersihkan area makam', 'Merawat tanaman', 'Memperbaiki batu nisan'],
        );
    }

    // =====================================================================
    // Access matrix
    // =====================================================================

    /**
     * The assertion this replaces was `assertTrue(class_exists(
     * WorkOrdersResource::class))`, under a name promising the resource
     * "requires vendor role". Its own comments conceded it was not testing
     * that; a `class_exists` on an imported class cannot fail, because the
     * `use` statement at the top of this file already autoloads it. It was a
     * green light wired to nothing on a **fail-closed access gate**, which is
     * the worst place to have one.
     *
     * What the name promised is what is asserted now: a signed-in user who is
     * not a vendor does not get the list.
     *
     * Asserted over HTTP, not through `Livewire::test()`. The original test's
     * own comment named the reason — "a customer in the vendor panel would be
     * redirected by panel middleware" — and it is decisive: the refusal lives
     * in `VendorPanelProvider`'s `authMiddleware()`, which a direct Livewire
     * component test never enters. `Livewire::test(ListWorkOrders::class)` as
     * a non-vendor does return OK, so a Livewire-level assertion here would
     * report a breach that production does not have. `/vendor/order-kerja` is
     * the surface a browser actually reaches.
     *
     * `VendorPanelAccessTest` sweeps the panel's routes for exactly this
     * check, but its two route lists do not include `order-kerja` — so until
     * now no test anywhere covered this resource's door. Left there rather
     * than added to that file's lists, which are out of this change's scope;
     * flagged in the branch report.
     */
    public function test_a_signed_in_user_without_the_vendor_role_cannot_reach_the_work_orders_list(): void
    {
        $this->makeWorkOrder();

        // No role grant and no vendor scope assignment — an authenticated
        // stranger to the vendor panel.
        $this->actingAs(User::factory()->create());
        $this->forgetResolvedActorContext();

        $this->get('/vendor/order-kerja')->assertForbidden();
    }

    /**
     * The control for the test above: the identical URL IS served once the
     * vendor role and scope are granted. Without it, a route that had broken
     * closed for everyone — or a typo in the slug, which would 404 and satisfy
     * neither promise while looking like a refusal — would leave the access
     * matrix permanently, silently green.
     */
    public function test_the_same_url_is_served_once_the_vendor_role_and_grant_are_present(): void
    {
        $this->makeWorkOrder();
        $this->actingUserWithRole(ActorRole::VENDOR);

        $this->get('/vendor/order-kerja')->assertSuccessful();
    }

    // =====================================================================
    // Work order list and view
    // =====================================================================

    public function test_work_order_list_renders(): void
    {
        $this->actingUserWithRole(ActorRole::VENDOR);

        Livewire::test(ListWorkOrders::class)
            ->assertOk();
    }

    public function test_work_order_view_renders(): void
    {
        $workOrder = $this->makeWorkOrder();
        $this->actingUserWithRole(ActorRole::VENDOR);

        Livewire::test(ViewWorkOrder::class, ['record' => $workOrder->getKey()])
            ->assertOk();
    }

    // =====================================================================
    // Complete task action
    // =====================================================================

    public function test_complete_task_action_completes_a_pending_task(): void
    {
        $workOrder = $this->makeWorkOrder();
        $task = $workOrder->tasks()->first();

        $this->assertNotNull($task);
        $this->assertSame('pending', $task->status);

        app(CompleteTask::class)(
            $task,
            'vendor:1',
            'vendor',
        );

        $task->refresh();
        $this->assertSame('completed', $task->status);
        $this->assertNotNull($task->completed_at);
    }

    public function test_complete_task_refuses_already_completed_task(): void
    {
        $workOrder = $this->makeWorkOrder();
        $task = $workOrder->tasks()->first();

        app(CompleteTask::class)($task, 'vendor:1', 'vendor');

        $this->expectException(\InvalidArgumentException::class);

        app(CompleteTask::class)($task, 'vendor:1', 'vendor');
    }

    // =====================================================================
    // Work order has correct relationships
    // =====================================================================

    public function test_work_order_has_tasks_and_evidence(): void
    {
        $workOrder = $this->makeWorkOrder();

        $this->assertCount(3, $workOrder->tasks);
        $this->assertCount(0, $workOrder->evidence);
        $this->assertNotNull($workOrder->carePlan);
    }
}

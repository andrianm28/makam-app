<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\WorkOrderStatus;
use App\Filament\Admin\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Admin\Resources\WorkOrders\WorkOrdersResource;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The admin `WorkOrdersResource`'s 'Ganti Vendor' header action —
 * `App\Domain\VendorFulfillment\Actions\ReplaceVendor` (AC7) existed,
 * audited, but was unwired anywhere in the codebase before this batch. This
 * is the resource-level counterpart to `ReplaceVendorTest`'s domain-level
 * proof: same shape `CertificateAdminTest` uses for `CreateCertificateAction`
 * — access matrix, a real write through the resource, and the issuer gate
 * at both layers (render + in-closure re-check).
 */
final class WorkOrderVendorReplacementTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function actingUserWithRole(string $role): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, $role);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        return $user;
    }

    /**
     * @return array{WorkOrder, Vendor, Vendor}
     */
    private function makeWorkOrderWithVendors(): array
    {
        $carePlan = CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Basic Care',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'product_code' => 'GC-MONTHLY',
            'status' => 'active',
            'checklist_template' => [],
        ]);

        $oldVendor = Vendor::query()->create(['name' => 'Vendor Lama', 'is_active' => true]);
        $newVendor = Vendor::query()->create(['name' => 'Vendor Baru', 'is_active' => true]);

        $workOrder = WorkOrder::query()->create([
            'reference' => 'WO-'.Str::upper(Str::random(8)),
            'care_plan_id' => $carePlan->getKey(),
            'vendor_id' => $oldVendor->getKey(),
            'status' => WorkOrderStatus::Scheduled->value,
        ]);

        return [$workOrder, $oldVendor, $newVendor];
    }

    // =====================================================================
    // Access matrix
    // =====================================================================

    public function test_the_resource_fails_closed_outside_the_back_office_roles(): void
    {
        $this->assertFalse(WorkOrdersResource::canAccess());

        $this->actingAs(User::factory()->create());
        $this->forgetResolvedActorContext();
        $this->assertFalse(WorkOrdersResource::canAccess());
    }

    public function test_back_office_roles_can_view_the_resource(): void
    {
        foreach ([
            ActorRole::ADMIN,
            ActorRole::RESTRICTED_ADMIN,
            ActorRole::OPERATOR,
            ActorRole::FINANCE,
        ] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertTrue(
                WorkOrdersResource::canAccess(),
                "Expected role [{$role}] to access the work orders resource.",
            );
            $this->forgetResolvedActorContext();
        }
    }

    public function test_operator_and_finance_never_see_the_replace_vendor_action(): void
    {
        [$workOrder] = $this->makeWorkOrderWithVendors();

        foreach ([ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);
            $this->forgetResolvedActorContext();

            Livewire::test(ViewWorkOrder::class, ['record' => $workOrder->getRouteKey()])
                ->assertOk()
                ->assertActionHidden('gantiVendor');
        }
    }

    // =====================================================================
    // The write, through the resource
    // =====================================================================

    public function test_an_admin_replaces_the_vendor_through_the_resource(): void
    {
        [$workOrder, , $newVendor] = $this->makeWorkOrderWithVendors();
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->callAction('gantiVendor', data: [
                'new_vendor_id' => $newVendor->getKey(),
                'reason' => 'Vendor lama tidak responsif.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Vendor pesanan kerja diganti.');

        $this->assertSame((string) $newVendor->getKey(), $workOrder->fresh()->vendor_id);

        $this->assertDatabaseHas('audit_events', [
            'action' => 'VENDOR_REPLACED',
            'subject_id' => (string) $workOrder->getKey(),
            'reason' => 'Vendor lama tidak responsif.',
        ]);
    }

    public function test_the_reason_is_required(): void
    {
        [$workOrder, , $newVendor] = $this->makeWorkOrderWithVendors();
        $originalVendorId = $workOrder->vendor_id;
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->callAction('gantiVendor', data: [
                'new_vendor_id' => $newVendor->getKey(),
                'reason' => '',
            ])
            ->assertHasActionErrors(['reason' => ['required']]);

        $this->assertSame($originalVendorId, $workOrder->fresh()->vendor_id);
    }

    /**
     * The wire-level refusal for a non-issuer: Filament refuses to even
     * mount an unauthorized action (the `->authorize()` render gate hides
     * it), matching `CertificateAdminTest`'s own proof for `terbitkan`.
     */
    public function test_an_operator_wire_call_on_the_replace_action_is_refused(): void
    {
        [$workOrder] = $this->makeWorkOrderWithVendors();
        $this->actingUserWithRole(ActorRole::OPERATOR);

        $component = Livewire::test(ViewWorkOrder::class, ['record' => $workOrder->getRouteKey()])
            ->assertActionHidden('gantiVendor');

        $component->call('mountAction', 'gantiVendor', []);

        $this->assertSame(0, count($component->instance()->mountedActions));
        $this->assertSame($workOrder->vendor_id, $workOrder->fresh()->vendor_id);
    }
}

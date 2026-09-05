<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin;

use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\VendorFulfillment\Actions\CreateWorkOrderFromCycle;
use App\Domain\VendorFulfillment\Actions\FileComplaint;
use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Domain\VendorFulfillment\Models\MakeGoodOrder;
use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Filament\Admin\Resources\ServiceComplaints\Pages\ViewServiceComplaint;
use App\Filament\Admin\Resources\ServiceComplaints\ServiceComplaintsResource;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

final class ServiceComplaintsResourceTest extends TestCase
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

    private function makeComplaint(): ServiceComplaint
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
        $subscription = Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => User::factory()->create()->id,
            'status' => 'active',
            'frequency' => 'monthly',
            'price_minor' => 150000,
            'currency' => 'IDR',
        ]);
        $cycle = SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth(),
            'cycle_end' => now(),
            'status' => 'PAID',
        ]);
        $workOrder = app(CreateWorkOrderFromCycle::class)($cycle, $carePlan);

        return app(FileComplaint::class)($workOrder, User::factory()->create()->id, 'Service was not performed properly.');
    }

    public function test_the_resource_fails_closed_outside_the_back_office_roles(): void
    {
        $this->assertFalse(ServiceComplaintsResource::canAccess());

        $this->actingAs(User::factory()->create());
        $this->forgetResolvedActorContext();
        $this->assertFalse(ServiceComplaintsResource::canAccess());
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
                ServiceComplaintsResource::canAccess(),
                "Expected role [{$role}] to access the service complaints resource.",
            );
            $this->forgetResolvedActorContext();
        }
    }

    public function test_vendor_and_cemetery_operator_and_customer_and_case_manager_are_denied(): void
    {
        foreach ([ActorRole::VENDOR, ActorRole::CEMETERY_OPERATOR, ActorRole::CUSTOMER, ActorRole::CASE_MANAGER] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);

            $this->assertFalse(
                ServiceComplaintsResource::canAccess(),
                "Expected role [{$role}] to be denied the service complaints resource.",
            );
            $this->forgetResolvedActorContext();
        }
    }

    public function test_an_admin_resolves_a_complaint_with_make_good_through_the_resource_and_attributes_the_real_actor(): void
    {
        $complaint = $this->makeComplaint();
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewServiceComplaint::class, ['record' => $complaint->getRouteKey()])
            ->callAction('selesaikan', data: [
                'resolution_notes' => 'Headstone was not cleaned properly, issuing a redo.',
                'create_make_good' => true,
                'make_good_notes' => 'Redo the cleaning pass.',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Keluhan diselesaikan.');

        $fresh = $complaint->fresh();
        $this->assertSame(ComplaintStatus::Resolved->value, $fresh->status);
        $this->assertNotNull($fresh->make_good_order_id);

        $makeGood = MakeGoodOrder::query()->findOrFail($fresh->make_good_order_id);
        $this->assertSame('Redo the cleaning pass.', $makeGood->notes);

        // Real actor attribution flows from the Filament action through
        // ResolveComplaint into CreateMakeGood's audit row — not 'system'.
        $this->assertDatabaseHas('audit_events', [
            'action' => 'MAKE_GOOD_CREATED',
            'actor_role' => 'admin',
        ]);
    }

    public function test_operator_and_finance_can_see_the_resolve_action(): void
    {
        $complaint = $this->makeComplaint();

        foreach ([ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);
            $this->forgetResolvedActorContext();

            // OPERATOR/FINANCE are allowed to VIEW the resource
            // (MasterDataAdminAuthorizerContract) but per this task's
            // brief the resolve/dismiss/investigate ACTIONS should carry
            // no narrower gate than the resource's own view gate — unlike
            // WorkOrdersResource's 'gantiVendor', ServiceComplaintsResource
            // does not scope these 3 actions to admin/restricted_admin
            // only. Confirm this deliberate choice: all 4 authorized
            // roles should see and be able to invoke all 3 actions. If
            // the real requirement turns out to need a narrower gate
            // (matching ReplaceVendorAction's admin/restricted_admin-only
            // pattern instead), adjust ResolveComplaintAction/
            // DismissComplaintAction/StartInvestigatingAction's
            // isAuthorized() methods accordingly and update this test to
            // match — this is a judgment call this plan makes explicitly
            // rather than leaving ambiguous: no requirement anywhere
            // named a stricter bar for complaint resolution than for
            // viewing complaints, unlike vendor replacement's own
            // documented stricter bar.
            Livewire::test(ViewServiceComplaint::class, ['record' => $complaint->getRouteKey()])
                ->assertActionVisible('selesaikan');
        }
    }

    public function test_the_resolution_notes_field_is_required(): void
    {
        $complaint = $this->makeComplaint();
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewServiceComplaint::class, ['record' => $complaint->getRouteKey()])
            ->callAction('selesaikan', data: [
                'resolution_notes' => '',
                'create_make_good' => false,
            ])
            ->assertHasActionErrors(['resolution_notes' => ['required']]);

        $this->assertSame(ComplaintStatus::Open->value, $complaint->fresh()->status);
    }

    public function test_an_admin_dismisses_a_complaint_through_the_resource(): void
    {
        $complaint = $this->makeComplaint();
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewServiceComplaint::class, ['record' => $complaint->getRouteKey()])
            ->callAction('tolak', data: ['reason' => 'Vendor evidence shows service was completed correctly.'])
            ->assertHasNoActionErrors()
            ->assertNotified('Keluhan ditolak.');

        $fresh = $complaint->fresh();
        $this->assertSame(ComplaintStatus::Dismissed->value, $fresh->status);
        $this->assertSame('Vendor evidence shows service was completed correctly.', $fresh->resolution_notes);
    }
}

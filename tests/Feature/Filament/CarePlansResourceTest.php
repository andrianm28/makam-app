<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\CareSubscription\Actions\CreateCarePlan;
use App\Domain\CareSubscription\Actions\CreateSubscription;
use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\PlotInventory\Models\GravePlot;
use App\Filament\Admin\Resources\CarePlans\CarePlansResource;
use App\Filament\Admin\Resources\CarePlans\Pages\CreateCarePlan as CreateCarePlanPage;
use App\Filament\Admin\Resources\CarePlans\Pages\ListCarePlans;
use App\Filament\Admin\Resources\CarePlans\Pages\ViewCarePlan;
use App\Filament\Admin\Resources\Subscriptions\SubscriptionsResource;
use App\Filament\Admin\Resources\Subscriptions\Pages\ListSubscriptions;
use App\Filament\Admin\Resources\Subscriptions\Pages\ViewSubscription;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * Tests for CarePlansResource and SubscriptionsResource admin surfaces.
 *
 * 1. Access matrix: four back-office roles access both resources; customer/vendor fail closed.
 * 2. Care plan CRUD through the resource.
 * 3. Subscription list/view + header actions (create, pause, cancel).
 * 4. AC7 gate: pause/cancel show honest 'Belum dapat diaktifkan' notification.
 */
final class CarePlansResourceTest extends TestCase
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

    private function forgetResolvedActorContext(): void
    {
        $this->app->forgetInstance(\App\Platform\IdentityAccess\ActorContext::class);
        $this->app->forgetInstance(\App\Platform\IdentityAccess\ActorContextResolver::class);
    }

    private function makeCarePlan(): CarePlan
    {
        return app(CreateCarePlan::class)(
            name: 'Perawatan Bulanan Standar',
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

    // =====================================================================
    // Access matrix — both resources
    // =====================================================================

    public function test_both_resources_fail_closed_outside_back_office_roles(): void
    {
        $this->assertFalse(CarePlansResource::canAccess());
        $this->assertFalse(SubscriptionsResource::canAccess());

        $this->actingAs(User::factory()->create());
        $this->forgetResolvedActorContext();
        $this->assertFalse(CarePlansResource::canAccess());
        $this->assertFalse(SubscriptionsResource::canAccess());

        $vendor = User::factory()->create();
        $this->grantRoleTo($vendor, ActorRole::VENDOR);
        $this->actingAs($vendor);

        $this->assertFalse(CarePlansResource::canAccess());
        $this->assertFalse(SubscriptionsResource::canAccess());
    }

    public function test_back_office_roles_access_both_resources(): void
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
            $this->forgetResolvedActorContext();

            $this->assertTrue(
                CarePlansResource::canAccess(),
                "Expected role [{$role}] to access the care plans resource.",
            );
            $this->assertTrue(
                SubscriptionsResource::canAccess(),
                "Expected role [{$role}] to access the subscriptions resource.",
            );
        }
    }

    // =====================================================================
    // Care plans — list, view, create
    // =====================================================================

    public function test_care_plan_list_renders(): void
    {
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ListCarePlans::class)
            ->assertOk();
    }

    public function test_care_plan_view_renders(): void
    {
        $plan = $this->makeCarePlan();
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewCarePlan::class, ['record' => $plan->getKey()])
            ->assertOk();
    }

    public function test_care_plan_create_page_renders(): void
    {
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(CreateCarePlanPage::class)
            ->assertOk();
    }

    // =====================================================================
    // Subscriptions — list, view
    // =====================================================================

    public function test_subscription_list_renders(): void
    {
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ListSubscriptions::class)
            ->assertOk();
    }

    public function test_subscription_view_renders(): void
    {
        $plan = $this->makeCarePlan();
        $grave = $this->makeGravePlot();
        $user = User::factory()->create();

        $subscription = app(CreateSubscription::class)(
            $plan,
            (string) $grave->getKey(),
            (string) $user->getKey(),
            CarePlanFrequency::Monthly,
        );

        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewSubscription::class, ['record' => $subscription->getKey()])
            ->assertOk();
    }

    // =====================================================================
    // AC7 gate — pause and cancel actions
    // =====================================================================

    public function test_pause_action_shows_ac7_gate_notification(): void
    {
        $plan = $this->makeCarePlan();
        $grave = $this->makeGravePlot();
        $user = User::factory()->create();

        $subscription = app(CreateSubscription::class)(
            $plan,
            (string) $grave->getKey(),
            (string) $user->getKey(),
            CarePlanFrequency::Monthly,
        );

        // Manually set to active so the action is visible
        $subscription->update(['status' => 'active']);

        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewSubscription::class, ['record' => $subscription->getKey()])
            ->callAction('jeda')
            ->assertNotified('Belum dapat diaktifkan');
    }

    public function test_cancel_action_shows_ac7_gate_notification(): void
    {
        $plan = $this->makeCarePlan();
        $grave = $this->makeGravePlot();
        $user = User::factory()->create();

        $subscription = app(CreateSubscription::class)(
            $plan,
            (string) $grave->getKey(),
            (string) $user->getKey(),
            CarePlanFrequency::Monthly,
        );

        // Manually set to active so the action is visible
        $subscription->update(['status' => 'active']);

        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewSubscription::class, ['record' => $subscription->getKey()])
            ->callAction('batalkan')
            ->assertNotified('Belum dapat diaktifkan');
    }

    public function test_pause_action_is_hidden_for_non_active_subscriptions(): void
    {
        $plan = $this->makeCarePlan();
        $grave = $this->makeGravePlot();
        $user = User::factory()->create();

        $subscription = app(CreateSubscription::class)(
            $plan,
            (string) $grave->getKey(),
            (string) $user->getKey(),
            CarePlanFrequency::Monthly,
        );

        // draft status — action should be hidden
        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewSubscription::class, ['record' => $subscription->getKey()])
            ->assertActionHidden('jeda');
    }

    public function test_cancel_action_is_hidden_for_ended_subscriptions(): void
    {
        $plan = $this->makeCarePlan();
        $grave = $this->makeGravePlot();
        $user = User::factory()->create();

        $subscription = app(CreateSubscription::class)(
            $plan,
            (string) $grave->getKey(),
            (string) $user->getKey(),
            CarePlanFrequency::Monthly,
        );

        $subscription->update(['status' => 'ended']);

        $this->actingUserWithRole(ActorRole::ADMIN);

        Livewire::test(ViewSubscription::class, ['record' => $subscription->getKey()])
            ->assertActionHidden('batalkan');
    }
}

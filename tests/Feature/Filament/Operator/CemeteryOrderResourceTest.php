<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Operator;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Filament\Operator\Resources\CemeteryOrders\CemeteryOrderResource;
use App\Filament\Operator\Resources\CemeteryOrders\Pages\ListCemeteryOrders;
use App\Filament\Operator\Resources\CemeteryOrders\Pages\ViewCemeteryOrder;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The cross-cemetery denial this resource exists to enforce, proven against
 * real Postgres rows: an operator granted cemetery A must not see, and must
 * not be able to reach by guessed record id, an order belonging to cemetery
 * B. Same rigor as Phase A Task 6's `OrderTransitionAuthorizerContract`
 * test — a "cannot see it in the list" assertion alone would not catch a
 * resource whose list is scoped but whose view page is not.
 */
final class CemeteryOrderResourceTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private Cemetery $cemeteryA;

    private Cemetery $cemeteryB;

    private Order $orderInA;

    private Order $orderInB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Filament::setCurrentPanel('operator');

        $this->cemeteryA = Cemetery::factory()->create(['name' => 'TPU Alpha']);
        $this->cemeteryB = Cemetery::factory()->create(['name' => 'TPU Beta']);
        $this->orderInA = $this->orderFor($this->cemeteryA, 'Budi Santoso');
        $this->orderInB = $this->orderFor($this->cemeteryB, 'Citra Dewi');
    }

    private function orderFor(Cemetery $cemetery, string $customer): Order
    {
        $draft = BookingDraft::query()->create([
            'cemetery_id' => $cemetery->id,
            'customer_full_name' => $customer,
        ]);

        return Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
            'booking_draft_id' => $draft->id,
        ]);
    }

    private function actingAsOperatorGrantedTo(Cemetery $cemetery): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $cemetery->id,
        ]);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        return $user;
    }

    public function test_the_list_shows_only_the_granted_cemeterys_orders(): void
    {
        $this->actingAsOperatorGrantedTo($this->cemeteryA);

        Livewire::test(ListCemeteryOrders::class)
            ->assertCanSeeTableRecords([$this->orderInA])
            ->assertCanNotSeeTableRecords([$this->orderInB]);
    }

    public function test_the_scoped_query_excludes_another_cemeterys_order_entirely(): void
    {
        $this->actingAsOperatorGrantedTo($this->cemeteryA);

        $visible = CemeteryOrderResource::getEloquentQuery()->pluck('id')->map(strval(...))->all();

        $this->assertContains((string) $this->orderInA->id, $visible);
        $this->assertNotContains((string) $this->orderInB->id, $visible);
    }

    public function test_an_order_with_no_booking_draft_is_invisible_to_every_operator(): void
    {
        $draftless = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
        ]);

        $this->actingAsOperatorGrantedTo($this->cemeteryA);

        $visible = CemeteryOrderResource::getEloquentQuery()->pluck('id')->map(strval(...))->all();

        $this->assertNotContains((string) $draftless->id, $visible);
    }

    public function test_a_guessed_record_id_for_another_cemetery_is_not_reachable(): void
    {
        $this->actingAsOperatorGrantedTo($this->cemeteryA);

        // The view page resolves its record from the scoped query, so an id
        // the actor has no grant for resolves nothing at all — mounting the
        // page throws, matching `ViewWorkOrder` (vendor)'s established
        // scoping-denial shape (`WorkOrderEvidenceUploadTest
        // ::test_a_vendor_cannot_upload_evidence_against_another_vendors_work_order`).
        // Not `->assertNotFound()`: Filament's `InteractsWithRecord
        // ::resolveRecord()` throws a raw `ModelNotFoundException`, and
        // Livewire's test harness (`RequestBroker
        // ::temporarilyDisableExceptionHandlingAndMiddleware()`) only
        // renders `HttpException`/`AuthorizationException` during a
        // component mount — every other exception, this one included,
        // propagates raw rather than becoming a captured 404 response. This
        // is the assertion that would catch a resource whose LIST is
        // scoped but whose VIEW page is reachable by direct URL.
        $this->expectException(ModelNotFoundException::class);

        Livewire::test(ViewCemeteryOrder::class, ['record' => (string) $this->orderInB->id]);
    }

    public function test_the_operators_own_order_is_reachable_by_id(): void
    {
        $this->actingAsOperatorGrantedTo($this->cemeteryA);

        Livewire::test(ViewCemeteryOrder::class, ['record' => (string) $this->orderInA->id])
            ->assertOk()
            ->assertSee('Budi Santoso');
    }

    public function test_the_resource_refuses_an_operator_holding_no_grant(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        $this->assertFalse(CemeteryOrderResource::canAccess());
    }

    public function test_the_resource_refuses_an_admin(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->app->forgetScopedInstances();

        $this->assertFalse(CemeteryOrderResource::canAccess());
        $this->assertTrue(CemeteryOrderResource::getAuthorizationResponse('view')->denied());
    }

    public function test_the_resource_admits_a_granted_operator(): void
    {
        $this->actingAsOperatorGrantedTo($this->cemeteryA);

        $this->assertTrue(CemeteryOrderResource::canAccess());
        $this->assertTrue(CemeteryOrderResource::getAuthorizationResponse('view')->allowed());
    }
}

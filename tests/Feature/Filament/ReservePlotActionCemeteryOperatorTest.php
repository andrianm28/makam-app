<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Filament\Admin\Resources\BookingOrders\Actions\ReservePlotAction;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * `ReservePlotAction::ALLOWED_ROLES` now lists `ActorRole::CEMETERY_OPERATOR`
 * (this plan's Task 5) — necessary groundwork for Phase C, where the
 * `/operator` panel's own order resource reuses this action.
 *
 * It is NOT sufficient today: `ReservePlotAction::roleAllowed()` composes
 * `BookingOrderResource::canAccess()` FIRST, which is gated by
 * `MasterDataAdminAuthorizerContract` — a closed list of admin /
 * restricted_admin / operator / finance that does not include
 * `cemetery_operator`. This test documents that honestly rather than
 * asserting a false enablement; full enablement is Phase C's job, once a
 * real `/operator` resource exists to compose against instead of
 * `BookingOrderResource`. See this plan's "Known, deliberate
 * incompleteness carried into Phase C" section.
 */
final class ReservePlotActionCemeteryOperatorTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_cemetery_operator_with_a_cemetery_grant_is_still_refused_today(): void
    {
        $cemetery = Cemetery::factory()->create();
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemetery->id]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
            'booking_draft_id' => $draft->id,
        ]);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::CEMETERY_OPERATOR);
        $this->actingAs($user);

        $action = ReservePlotAction::make($order);

        $this->assertFalse($action->isAuthorized());
    }

    public function test_existing_admitted_roles_are_unaffected_by_the_addition(): void
    {
        $cemetery = Cemetery::factory()->create();
        $draft = BookingDraft::query()->create(['cemetery_id' => $cemetery->id]);
        $order = Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::DIVERIFIKASI->value,
            'booking_draft_id' => $draft->id,
        ]);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        $action = ReservePlotAction::make($order);

        $this->assertTrue($action->isAuthorized());
    }
}

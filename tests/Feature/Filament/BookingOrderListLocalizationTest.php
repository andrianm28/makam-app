<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderProductTypeLabel;
use App\Filament\Admin\Resources\BookingOrders\Pages\ListBookingOrders;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * UI-audit fix (26 Aug 2026): `BookingOrdersTable`'s "Jenis Layanan" column
 * previously rendered the raw `product_type` enum value (e.g.
 * `AT_NEED_SERVICE_ORDER`) for every row instead of an Indonesian label.
 * Proves the list page now shows `BookingOrderProductTypeLabel::label()`'s
 * output and never the raw machine code.
 */
final class BookingOrderListLocalizationTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);
    }

    public function test_the_list_shows_a_localized_service_type_label_not_the_raw_enum(): void
    {
        $draft = BookingDraft::query()->create(['customer_full_name' => 'UAT Pemesan']);

        Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
            'booking_draft_id' => $draft->getKey(),
        ]);

        Livewire::test(ListBookingOrders::class)
            ->assertOk()
            ->assertSee(BookingOrderProductTypeLabel::label(ProductType::AT_NEED_SERVICE_ORDER))
            ->assertDontSee(ProductType::AT_NEED_SERVICE_ORDER->value);
    }
}

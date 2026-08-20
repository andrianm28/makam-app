<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Akun;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderParty;
use App\Domain\OrderWorkflow\OrderPartyRole;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/akun`'s own rendered content — Task 2 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-shell-and-drafts/task-2-brief.md`).
 * Companion to `AkunIndexRouteTest`, which covers the route's `auth`
 * middleware guard and intended-URL round-trip only; this file proves the
 * draft-tile copy `AkunIndex::render()` computes via
 * `BookingDraftQuery::openForUser(auth()->id())->count()` — both branches,
 * and that the count is scoped to the viewing user, not a global count.
 */
final class AkunIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_open_draft_count_is_scoped_to_the_viewing_user_only(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        BookingDraft::create(['user_id' => $user->id, 'city_code' => 'JAKARTA']);
        BookingDraft::create(['user_id' => $user->id, 'city_code' => 'BOGOR']);

        BookingDraft::create(['user_id' => $otherUser->id, 'city_code' => 'JAKARTA']);
        BookingDraft::create(['user_id' => $otherUser->id, 'city_code' => 'BOGOR']);
        BookingDraft::create(['user_id' => $otherUser->id, 'city_code' => 'JAKARTA']);

        $response = $this->actingAs($user)->get('/akun');

        $response->assertOk();
        $response->assertSee('2 draft belum selesai');
    }

    public function test_the_empty_state_copy_renders_when_the_user_has_no_open_drafts(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/akun');

        $response->assertOk();
        $response->assertSee('Belum ada draft pemesanan');
    }

    public function test_the_fourth_tile_links_to_the_order_list_route(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/akun');

        $response->assertOk();
        $response->assertSee('href="'.route('akun.pesanan').'"', false);
    }

    public function test_the_order_tile_count_is_scoped_to_the_viewing_user_only(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownOrder = Order::query()->create([
            'reference' => 'MK-2026-OWNTILEONE',
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
        ]);
        OrderParty::query()->create([
            'order_id' => $ownOrder->getKey(),
            'user_id' => $user->getKey(),
            'role' => OrderPartyRole::PEMESAN->value,
        ]);

        $otherOrder = Order::query()->create([
            'reference' => 'MK-2026-OTHERTILEONE',
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
        ]);
        OrderParty::query()->create([
            'order_id' => $otherOrder->getKey(),
            'user_id' => $otherUser->getKey(),
            'role' => OrderPartyRole::PEMESAN->value,
        ]);

        $response = $this->actingAs($user)->get('/akun');

        $response->assertOk();
        $response->assertSee('1 pesanan tercatat');
    }
}

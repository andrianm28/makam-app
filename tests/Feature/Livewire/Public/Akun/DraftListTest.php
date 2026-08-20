<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Akun;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `/akun/draft` — Task 2 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-shell-and-drafts/task-2-brief.md`).
 * `DraftList` is a thin `render()`-only wrapper over
 * `BookingDraftQuery::openForUser()`, already covered for its own logic
 * (own-user scoping, order exclusion, ordering) by
 * `BookingDraftQueryTest`. This file proves the SCREEN renders that same
 * data honestly — own drafts only, submitted drafts excluded, the exact
 * §6.2 empty-state copy, and update-order preserved end to end.
 */
final class DraftListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_exact_empty_state_copy_renders_when_the_user_has_no_open_drafts(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/akun/draft');

        $response->assertOk();
        $response->assertSee('Belum ada draft pemesanan.');
        $response->assertSee('Mulai pemesanan');
        $response->assertSee(route('pemesanan-makam.index'), false);
    }

    public function test_only_the_authenticated_users_own_open_draft_is_shown(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $ownDraft = BookingDraft::create(['user_id' => $user->id, 'city_code' => 'JAKARTA']);
        BookingDraft::create(['user_id' => $otherUser->id, 'city_code' => 'BOGOR']);

        $response = $this->actingAs($user)->get('/akun/draft');

        $response->assertOk();
        $response->assertSee(route('pemesanan-makam.draft', ['draftId' => $ownDraft->id]), false);
        $response->assertDontSee('Belum ada draft pemesanan.');
    }

    public function test_a_draft_that_already_has_an_order_is_excluded(): void
    {
        $user = User::factory()->create();

        $draftWithOrder = BookingDraft::create(['user_id' => $user->id, 'city_code' => 'JAKARTA']);
        $openDraft = BookingDraft::create(['user_id' => $user->id, 'city_code' => 'BOGOR']);

        Order::query()->create([
            'reference' => 'MK-2026-'.Str::upper(Str::random(8)),
            'product_type' => ProductType::AT_NEED_SERVICE_ORDER->value,
            'status' => OrderStatus::MASUK->value,
            'booking_draft_id' => $draftWithOrder->id,
        ]);

        $response = $this->actingAs($user)->get('/akun/draft');

        $response->assertOk();
        $response->assertSee(route('pemesanan-makam.draft', ['draftId' => $openDraft->id]), false);
        $response->assertDontSee(route('pemesanan-makam.draft', ['draftId' => $draftWithOrder->id]), false);
    }

    public function test_drafts_are_ordered_most_recently_updated_first(): void
    {
        $user = User::factory()->create();

        $older = BookingDraft::create(['user_id' => $user->id, 'city_code' => 'JAKARTA']);
        $newer = BookingDraft::create(['user_id' => $user->id, 'city_code' => 'BOGOR']);

        BookingDraft::query()->where('id', $older->id)->update(['updated_at' => now()->subDay()]);
        BookingDraft::query()->where('id', $newer->id)->update(['updated_at' => now()]);

        $html = $this->actingAs($user)->get('/akun/draft')->assertOk()->getContent();

        $newerPos = strpos($html, route('pemesanan-makam.draft', ['draftId' => $newer->id]));
        $olderPos = strpos($html, route('pemesanan-makam.draft', ['draftId' => $older->id]));

        $this->assertNotFalse($newerPos);
        $this->assertNotFalse($olderPos);
        $this->assertTrue($newerPos < $olderPos, 'Expected the more recently updated draft to render first.');
    }

    public function test_row_shows_progress_and_a_continue_link(): void
    {
        $user = User::factory()->create();

        $draft = BookingDraft::create([
            'user_id' => $user->id,
            'city_code' => 'JAKARTA',
            'current_step' => 4,
        ]);

        $response = $this->actingAs($user)->get('/akun/draft');

        $response->assertOk();
        $response->assertSee('Langkah 4 dari 9');
        $response->assertSee('Lanjutkan');
        $response->assertSee('href="'.route('pemesanan-makam.draft', ['draftId' => $draft->id]).'"', false);
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get('/akun/draft')->assertRedirect(route('login'));
    }
}

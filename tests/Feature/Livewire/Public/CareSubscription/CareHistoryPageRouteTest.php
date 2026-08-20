<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\CareSubscription;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/riwayat-perawatan/{customerId}` — route-level behaviour only. This route
 * carried no `auth` middleware until the accept/complaint write surface
 * landed (`CareHistoryPage`'s own doc block: "a route middleware gap this
 * component cannot close on its own"); the middleware was added alongside
 * that write surface in the same batch. `CareHistoryPageTest`/
 * `CareHistoryPageActionsTest` exercise the component's own content and
 * write actions via `Livewire::test()`, which bypasses route middleware
 * entirely — this file is what actually proves the route itself is gated.
 */
final class CareHistoryPageRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $response = $this->get('/riwayat-perawatan/some-customer-id');

        $response->assertRedirect(route('login'));
    }

    public function test_an_authenticated_user_can_reach_the_route(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/riwayat-perawatan/'.$user->getAuthIdentifier());

        $response->assertOk();
    }
}

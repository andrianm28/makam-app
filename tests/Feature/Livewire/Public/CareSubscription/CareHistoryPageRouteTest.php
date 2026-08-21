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

    /**
     * `customer_id` is a real bigint column (fixed 22 Aug 2026,
     * `2026_08_22_100000_fix_customer_and_uploader_identity_columns`).
     * `CareHistoryPage::isNumericCustomerId()`'s `ctype_digit()` check alone
     * accepts a digit string of any length, and a value that overflows
     * Postgres's bigint range would otherwise reach the database and throw
     * `SQLSTATE[22003]: Numeric value out of range` (confirmed live during
     * review) -- the same failure class ($customerId reaching a typed
     * Postgres column unvalidated) this route's own auth-guard history
     * already names. The honest "no history" empty state, not a 500.
     */
    public function test_an_authenticated_user_visiting_a_bigint_overflowing_id_gets_the_honest_empty_state_not_a_500(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/riwayat-perawatan/99999999999999999999');

        $response->assertOk();
        $response->assertSee('Belum ada riwayat perawatan');
    }
}

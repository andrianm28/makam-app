<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\CareSubscription;

use App\Domain\CareSubscription\CarePlanFrequency;
use App\Domain\CareSubscription\Models\CarePlan;
use App\Domain\CareSubscription\Models\Subscription;
use App\Domain\CareSubscription\Models\SubscriptionCycle;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * `/riwayat-perawatan/{customerId}` — route-level behaviour only.
 * `CareHistoryPageTest`/`CareHistoryPageActionsTest` exercise the
 * component's own content and write actions via `Livewire::test()`, which
 * bypasses route middleware entirely — this file is what actually proves
 * the route itself is gated, and (since the 5 Sep 2026 IDOR fix) that the
 * `auth` middleware being satisfied is not by itself enough: the
 * authenticated actor must also BE the customer the URL names.
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

    /**
     * The real, live cross-customer IDOR this fix closes, proven at the
     * actual HTTP/route level (not just via `Livewire::test()`, which
     * bypasses middleware entirely) — see
     * `CareHistoryPageTest::test_an_authenticated_customer_cannot_see_
     * another_customers_care_history()` for the component-level version of
     * this same proof. Before 5 Sep 2026, satisfying `auth` middleware was
     * enough: any logged-in customer visiting another customer's real
     * `/riwayat-perawatan/{customerId}` URL saw that customer's real
     * work-order history. Now the wrong viewer gets the exact same honest
     * "no history" state a genuinely history-less customer sees — never a
     * distinguishing 403/404 (see `CareHistoryPage`'s own class doc block
     * for why a 403 here would itself be an existence-leak).
     */
    public function test_an_authenticated_customer_visiting_another_customers_url_gets_the_honest_empty_state_not_their_real_history(): void
    {
        $victim = User::factory()->create();

        $carePlan = CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Perawatan Bulanan Standar',
            'product_code' => 'GRAVE_CARE_MONTHLY',
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'checklist_template' => ['membersihkan makam'],
            'status' => 'active',
        ]);

        $subscription = Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => $victim->id,
            'status' => 'active',
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'current_cycle_number' => 2,
            'started_at' => now()->subMonths(2),
        ]);

        $cycle = SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'cycle_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'status' => 'COMPLETED',
        ]);

        $victimsWorkOrder = WorkOrder::query()->create([
            'reference' => 'WO-'.Str::upper(Str::random(8)),
            'care_plan_id' => $subscription->getKey(),
            'subscription_cycle_id' => $cycle->getKey(),
            'status' => 'completed',
        ]);

        $attacker = User::factory()->create();

        $response = $this->actingAs($attacker)->get('/riwayat-perawatan/'.$victim->getAuthIdentifier());

        $response->assertOk();
        $response->assertSee('Belum ada riwayat perawatan');
        $response->assertDontSee($victimsWorkOrder->reference);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\RateLimiting;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `public-guest` rate limiter — `AppServiceProvider::boot()`'s own doc
 * block explains why it is attached to the whole `web` middleware group
 * (`bootstrap/app.php`) rather than to individual routes: every public
 * journey is a Livewire component, and Livewire's write actions all funnel
 * through one shared endpoint no per-route `throttle:` middleware could
 * reach.
 *
 * Exercises the homepage (`/`) as the target, deliberately NOT the
 * framework's own `/up` health route: `ApplicationBuilder`'s `health:`
 * option registers that route as a bare `Route::get()` outside any
 * `Route::middleware('web')->group(...)` wrapper, so it never carries the
 * `web` group at all and this throttle could never reach it. `/` is inside
 * `routes/web.php`, which IS wrapped in the `web` group, and is the
 * cheapest real route that genuinely proves the wiring.
 */
final class PublicGuestThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // CI's PHP job never builds frontend assets (that's the separate
        // `frontend` job) — `/`'s real view carries `@vite(...)`, which
        // would otherwise throw ViteManifestNotFoundException before this
        // throttle is ever reached. Same convention `EditSiteSettingsSmokeTest`
        // already establishes for the same reason.
        $this->withoutVite();
    }

    public function test_a_guest_is_throttled_after_60_requests_in_a_minute(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->get('/')->assertOk();
        }

        $this->get('/')->assertStatus(429);
    }

    public function test_an_authenticated_request_gets_a_generous_but_finite_limit(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        for ($i = 0; $i < 120; $i++) {
            $this->get('/')->assertOk();
        }

        $this->get('/')->assertStatus(429);
    }

    public function test_two_different_guest_ips_are_throttled_independently(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->get('/')->assertOk();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.1'])->get('/')->assertStatus(429);

        // A different IP has its own, untouched budget.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->get('/')->assertOk();
    }

    /**
     * The IP floor added to the authenticated arm of `public-guest`
     * (`AppServiceProvider::boot()`'s own doc block): without it, one IP
     * could register many accounts (registration is 3/min/IP) and each
     * fresh account would get its own untouched 120/min budget, making the
     * per-user limit alone a bypass narrowed, not closed. This proves the
     * IP-keyed limit is shared across DIFFERENT authenticated users from
     * the SAME IP, not just tracked per user: two users each make 50
     * requests (well under their own individual 120/min budget) from the
     * same test-client IP, then a third user's requests trip the shared
     * IP-keyed limit well before that third user's OWN per-user counter
     * gets anywhere near 120 — proving the block came from the IP floor,
     * not that user's own budget.
     */
    public function test_many_authenticated_users_from_the_same_ip_eventually_trip_the_shared_ip_floor(): void
    {
        $userOne = User::factory()->create();
        $this->actingAs($userOne);
        for ($i = 0; $i < 50; $i++) {
            $this->get('/')->assertOk();
        }

        $userTwo = User::factory()->create();
        $this->actingAs($userTwo);
        for ($i = 0; $i < 50; $i++) {
            $this->get('/')->assertOk();
        }

        // The shared IP-keyed bucket is now at 100/120. A third, distinct
        // user — with its OWN per-user counter starting fresh at 0 — trips
        // the IP floor after only ~20 more requests, long before that
        // user's own 120/min budget would ever be a factor.
        $userThree = User::factory()->create();
        $this->actingAs($userThree);

        $blockedAt = null;
        for ($i = 0; $i < 50; $i++) {
            $response = $this->get('/');

            if ($response->getStatusCode() === 429) {
                $blockedAt = $i;

                break;
            }

            $response->assertOk();
        }

        $this->assertNotNull($blockedAt, 'Expected the shared IP-keyed limit to eventually block a request.');
        $this->assertLessThan(
            120,
            $blockedAt,
            "The third user's own per-user budget is 120/min; a block this early can only be the shared IP floor.",
        );
    }
}

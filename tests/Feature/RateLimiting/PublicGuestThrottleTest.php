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

    public function test_an_authenticated_request_is_never_throttled(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        for ($i = 0; $i < 65; $i++) {
            $this->get('/')->assertOk();
        }
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
}

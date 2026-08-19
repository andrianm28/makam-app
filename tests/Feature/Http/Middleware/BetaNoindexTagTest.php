<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `BetaNoindexTag` — see its own doc block for why this is global,
 * app-level middleware rather than only an nginx vhost header, and why it
 * defaults to a no-op.
 */
final class BetaNoindexTagTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_header_is_absent_by_default(): void
    {
        config(['app.beta_noindex' => false]);

        $response = $this->get('/');

        $response->assertHeaderMissing('X-Robots-Tag');
    }

    public function test_the_header_is_present_when_enabled(): void
    {
        config(['app.beta_noindex' => true]);

        $response = $this->get('/');

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    }

    /**
     * `ReportContentSecurityPolicy`'s own load-bearing case: proves this is
     * genuinely global middleware, not a `web`-group append that would
     * silently miss the Filament panel — see `bootstrap/app.php`'s comment
     * on `AssignCorrelationId` for why `/admin` never goes through the
     * `web` group at all.
     */
    public function test_the_header_reaches_the_admin_panel_login_page_when_enabled(): void
    {
        config(['app.beta_noindex' => true]);

        $response = $this->get('/admin/login');

        $response->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
    }
}

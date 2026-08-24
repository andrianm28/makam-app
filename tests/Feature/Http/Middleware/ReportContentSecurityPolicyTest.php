<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `ReportContentSecurityPolicy` — see its own doc block for why this is
 * global middleware (must reach the Filament `/admin`/`/vendor` panels,
 * which do not go through the `web` group at all) and why it ships
 * report-only.
 *
 * `RefreshDatabase`: every test here hits the real homepage, whose
 * `mount()` records a real `MenuInteractionEvent` row per primary menu
 * (`App\Livewire\Public\HomePage::mount()`) with no dedup. Without a
 * transaction to roll back, six real `$this->get('/')` calls across this
 * class's methods committed 24 permanent rows straight into the shared
 * test database, which `Tests\Feature\Livewire\Public\HomePageRouteTest`'s
 * OWN precondition (`assertSame(0, MenuInteractionEvent::query()->count())`)
 * then failed against — this class's writes outliving its own tests
 * entirely, in whichever unrelated later test happened to check that count.
 */
final class ReportContentSecurityPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_header_is_present_on_a_public_response(): void
    {
        $response = $this->get('/');

        $response->assertHeader('Content-Security-Policy-Report-Only');
    }

    /**
     * The load-bearing case: proves this is genuinely global middleware,
     * not a `web`-group append that would silently miss the panel — see
     * `bootstrap/app.php`'s own comment on why `throttle:public-guest`
     * (the OTHER lane's `web`-group append) could not have covered this.
     */
    public function test_the_header_is_present_on_the_admin_panel_login_page(): void
    {
        $response = $this->get('/admin/login');

        $response->assertHeader('Content-Security-Policy-Report-Only');
    }

    public function test_it_never_sets_the_enforcing_header(): void
    {
        $response = $this->get('/');

        $response->assertHeaderMissing('Content-Security-Policy');
    }

    public function test_the_policy_carries_a_nonce_shared_between_script_src_and_style_src(): void
    {
        $response = $this->get('/');

        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertMatchesRegularExpression('/script-src [^;]*\'nonce-([A-Za-z0-9]{40})\'/', $policy);

        preg_match('/script-src [^;]*\'nonce-([A-Za-z0-9]{40})\'/', $policy, $scriptMatch);
        preg_match('/style-src [^;]*\'nonce-([A-Za-z0-9]{40})\'/', $policy, $styleMatch);

        $this->assertNotEmpty($scriptMatch);
        $this->assertNotEmpty($styleMatch);
        $this->assertSame($scriptMatch[1], $styleMatch[1], 'script-src and style-src must share the same nonce.');
    }

    public function test_two_separate_requests_get_two_different_nonces(): void
    {
        $first = $this->get('/');
        $second = $this->get('/');

        preg_match('/nonce-([A-Za-z0-9]{40})/', (string) $first->headers->get('Content-Security-Policy-Report-Only'), $a);
        preg_match('/nonce-([A-Za-z0-9]{40})/', (string) $second->headers->get('Content-Security-Policy-Report-Only'), $b);

        $this->assertNotSame($a[1] ?? null, $b[1] ?? null, 'A stable/predictable nonce defeats its own purpose.');
    }

    /**
     * RENAMED from `test_the_policy_declares_no_third_party_origin`.
     * `frame-src https://www.google.com` (added for the cemetery directory's
     * embedded map — see `ReportContentSecurityPolicy`'s own comment) is now
     * a deliberate, sole exception to the "no third party origin" rule this
     * test used to assert absolutely. This test still proves the rule holds
     * everywhere else — every OTHER directive must stay origin-free — while
     * pinning the one exception to exactly the origin it is meant to be, not
     * a broader wildcard that would silently permit more than intended.
     */
    public function test_the_policy_declares_no_third_party_origin_except_the_deliberate_maps_frame_src(): void
    {
        $response = $this->get('/');

        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');
        $directives = array_map('trim', explode(';', $policy));

        foreach ($directives as $directive) {
            if (str_starts_with($directive, 'frame-src')) {
                $this->assertSame('frame-src https://www.google.com', $directive);

                continue;
            }

            $this->assertStringNotContainsString('http://', $directive, "directive [{$directive}] must not carry a third-party origin");
            $this->assertStringNotContainsString('https://', $directive, "directive [{$directive}] must not carry a third-party origin");
        }
    }
}

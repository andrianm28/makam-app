<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use Tests\TestCase;

/**
 * `ReportContentSecurityPolicy` — see its own doc block for why this is
 * global middleware (must reach the Filament `/admin`/`/vendor` panels,
 * which do not go through the `web` group at all) and why it ships
 * report-only.
 */
final class ReportContentSecurityPolicyTest extends TestCase
{
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

    public function test_the_policy_declares_no_third_party_origin(): void
    {
        $response = $this->get('/');

        $policy = (string) $response->headers->get('Content-Security-Policy-Report-Only');

        $this->assertStringNotContainsString('http://', $policy);
        $this->assertStringNotContainsString('https://', $policy);
    }
}

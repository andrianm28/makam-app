<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `ReportContentSecurityPolicy` — see its own doc block for why this is
 * global middleware (must reach the Filament `/admin`/`/vendor` panels,
 * which do not go through the `web` group at all) and why it now ships
 * enforcing (flipped 25 Aug 2026, SEC-08 — see the middleware's own doc
 * block for the gating criterion this closed).
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

        $response->assertHeader('Content-Security-Policy');
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

        $response->assertHeader('Content-Security-Policy');
    }

    /**
     * RENAMED from `test_it_never_sets_the_enforcing_header` (SEC-08,
     * 25 Aug 2026): the middleware now sets the enforcing header, never the
     * report-only one — this pins the flip in both directions at once.
     */
    public function test_it_never_sets_the_report_only_header(): void
    {
        $response = $this->get('/');

        $response->assertHeaderMissing('Content-Security-Policy-Report-Only');
    }

    /**
     * `unsafe-eval` in `script-src`, deliberately — see the middleware's own
     * doc block for exactly why (Filament's bundled Alpine genuinely needs
     * it; Livewire's JS asset has no per-panel routing to avoid it
     * selectively). Pinned here so a future attempt to remove it doesn't
     * silently reintroduce the real regression this fixed: a live CI
     * Playwright run showed admin login, vendor login, and Filament
     * table-action clicks all failing once `unsafe-eval` was ever removed.
     */
    public function test_the_policy_allows_unsafe_eval_for_filaments_alpine_usage(): void
    {
        $response = $this->get('/');

        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/script-src [^;]*'unsafe-eval'/", $policy);
    }

    /**
     * `style-src` stays free of `unsafe-eval` — that gap is script-only
     * (Alpine/Livewire directive parsing), not a blanket relaxation of the
     * whole policy. `style-src` DOES now carry `'unsafe-inline'` — see
     * `test_the_policy_allows_unsafe_inline_style_for_filaments_attribute_level_style_mutation()`
     * below and the middleware's own doc block (SEC-08 CSP-enforcement
     * follow-up) for exactly why.
     *
     * RENAMED from `test_style_src_does_not_carry_unsafe_eval`, which also
     * asserted `style-src` had no `unsafe-inline` — that assertion is now
     * the opposite of correct and is pinned by the new test instead.
     */
    public function test_style_src_does_not_carry_unsafe_eval(): void
    {
        $response = $this->get('/');

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $directives = array_map('trim', explode(';', $policy));

        $styleSrc = collect($directives)->first(fn (string $d): bool => str_starts_with($d, 'style-src'));

        $this->assertNotNull($styleSrc);
        $this->assertStringNotContainsString('unsafe-eval', $styleSrc);
    }

    /**
     * `unsafe-inline` in `style-src`, deliberately — see the middleware's
     * own doc block for exactly why (Filament's bundled Alpine mutates the
     * `style` ATTRIBUTE at runtime via `element.style.*`, which a nonce
     * cannot cover — nonce-source has no attribute-level form in the CSP
     * spec). Pinned here, mirroring
     * `test_the_policy_allows_unsafe_eval_for_filaments_alpine_usage()`
     * above, so a future attempt to remove it doesn't silently reintroduce
     * the real regression this fixed: a live CI Playwright run
     * (32871721430) showed the admin sidebar rendering as a broken
     * full-viewport overlay once this was missing under enforcement.
     */
    public function test_the_policy_allows_unsafe_inline_style_for_filaments_attribute_level_style_mutation(): void
    {
        $response = $this->get('/');

        $policy = (string) $response->headers->get('Content-Security-Policy');

        $this->assertMatchesRegularExpression("/style-src [^;]*'unsafe-inline'/", $policy);
    }

    public function test_the_policy_carries_a_nonce_shared_between_script_src_and_style_src(): void
    {
        $response = $this->get('/');

        $policy = (string) $response->headers->get('Content-Security-Policy');

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

        preg_match('/nonce-([A-Za-z0-9]{40})/', (string) $first->headers->get('Content-Security-Policy'), $a);
        preg_match('/nonce-([A-Za-z0-9]{40})/', (string) $second->headers->get('Content-Security-Policy'), $b);

        $this->assertNotSame($a[1] ?? null, $b[1] ?? null, 'A stable/predictable nonce defeats its own purpose.');
    }

    /**
     * RENAMED from `test_the_policy_declares_no_third_party_origin`, then
     * again from
     * `test_the_policy_declares_no_third_party_origin_except_the_deliberate_maps_frame_src`
     * (SEC-08 CSP-enforcement follow-up, 25 Aug 2026): `img-src` now carries
     * a SECOND deliberate exception, `https://ui-avatars.com` — Filament's
     * default `UiAvatarsProvider` (see `AdminPanelProvider`/
     * `VendorPanelProvider`, neither configures a custom
     * `->defaultAvatarProvider()`) fetches the signed-in user's initials
     * avatar from this fixed origin on every admin/vendor page load; real
     * enforcement blocks it outright otherwise. This test still proves the
     * "no third party origin" rule holds everywhere else — every OTHER
     * directive must stay origin-free — while pinning BOTH exceptions to
     * exactly the origins they are meant to be, not a broader wildcard that
     * would silently permit more than intended.
     */
    public function test_the_policy_declares_no_third_party_origin_except_the_deliberate_maps_frame_src(): void
    {
        $response = $this->get('/');

        $policy = (string) $response->headers->get('Content-Security-Policy');
        $directives = array_map('trim', explode(';', $policy));

        foreach ($directives as $directive) {
            if (str_starts_with($directive, 'frame-src')) {
                $this->assertSame('frame-src https://www.google.com', $directive);

                continue;
            }

            if (str_starts_with($directive, 'img-src')) {
                $this->assertSame("img-src 'self' data: https://ui-avatars.com", $directive);

                continue;
            }

            $this->assertStringNotContainsString('http://', $directive, "directive [{$directive}] must not carry a third-party origin");
            $this->assertStringNotContainsString('https://', $directive, "directive [{$directive}] must not carry a third-party origin");
        }
    }

    /**
     * The Maps embed itself must actually render under the now-enforcing
     * policy, not merely have the right frame-src directive text — a real
     * request against a cemetery detail page with a real embeddable map
     * proves the exception is genuinely sufficient, not just correctly
     * worded. A factory-built cemetery with real coordinates, not a
     * dependency on whatever happens to be seeded, so this assertion is
     * deterministic.
     */
    public function test_a_cemetery_page_with_a_maps_embed_still_renders_under_enforcement(): void
    {
        $this->withoutVite();

        $cemetery = Cemetery::factory()->create([
            'publication_status' => CemeteryPublicationStatus::PUBLISHED,
            'latitude' => -6.2088,
            'longitude' => 106.8456,
        ]);

        $response = $this->get('/pemakaman/'.$cemetery->slug);

        $response->assertOk();
        $response->assertHeader('Content-Security-Policy');
        // `assertSee($value, false)` — the raw URL contains `&`, which the
        // view escapes to `&amp;`; matching a substring with escaping
        // disabled is this codebase's own established pattern for this
        // exact assertion (`CemeteryDetailRouteTest`).
        $response->assertSee('output=embed', false);
        $response->assertSee('-6.2088000,106.8456000', false);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use App\Domain\Marketplace\Models\Vendor;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Real, urgent production regression (26 Aug 2026): `App\Http\Middleware\
 * ReportContentSecurityPolicy`'s `style-src-elem`/`script-src` carry a nonce
 * with NO `'unsafe-inline'` fallback (a nonce present in a directive disables
 * that fallback per spec), so ANY inline `<style>`/`<script>` tag missing the
 * matching `nonce` attribute is blocked outright by the browser, with zero
 * fallback and (since no `report-uri` is configured — see the middleware's
 * own doc block) zero server-side signal that anything broke.
 *
 * `ReportContentSecurityPolicyTest` already proves the HEADER carries a
 * correct nonce. It never proved every TAG in the rendered body carries the
 * SAME nonce — and several of Filament v5's own bundled Blade views
 * (`vendor/filament/support/resources/views/assets.blade.php`,
 * `vendor/filament/filament/resources/views/components/layout/base.blade.php`,
 * `vendor/filament/filament/resources/views/livewire/sidebar.blade.php`) emit
 * literal inline `<style>`/`<script>` tags of their own with no nonce at
 * all — Filament has no first-class CSP nonce mechanism (confirmed: zero
 * `nonce` hits anywhere under `vendor/filament`; open, unresolved upstream
 * discussions filamentphp/filament#7032 and #8329 ask for exactly this).
 * `assets.blade.php`'s blocked `<style>` tag is the one that defines every
 * `--fi-color-primary-*`/`--danger-*`/etc. CSS custom property Filament's own
 * `bg-{color}`/`text-{color}` utilities read (`AssetManager::renderStyles()`,
 * emitted via `@filamentStyles` on every panel page) — with it blocked,
 * every primary-colored button (including the panel LOGIN submit button)
 * renders as `background-color: transparent; color: white`, i.e. fully
 * invisible, present in the DOM and clickable but with zero visual
 * affordance. Confirmed the real, non-hypothetical way: fetching a real
 * `/vendor/login` response and diffing the header's nonce against every
 * inline tag in that same response body by hand found four `<style>` tags
 * and at least three `<script>` tags with no `nonce` attribute at all.
 *
 * Fixed via `resources/views/vendor/filament/assets.blade.php`,
 * `resources/views/vendor/filament-panels/components/layout/base.blade.php`,
 * and `resources/views/vendor/filament-panels/livewire/sidebar.blade.php` —
 * Laravel's own supported view-override convention
 * (`Illuminate\Support\ServiceProvider::loadViewsFrom()`, which every
 * Filament sub-package's `Spatie\LaravelPackageTools` `hasViews()` call
 * already wires up: a file at `resources/views/vendor/{package}/...`
 * resolves BEFORE the package's own view of the same relative path), not a
 * `vendor/` patch — content otherwise byte-for-byte identical to the
 * installed `filament/filament` v5.7.3 copies, with `nonce="{{
 * \Illuminate\Support\Facades\Vite::cspNonce() }}"` added to each tag that
 * had none. That is the exact same mechanism Livewire's OWN already-working
 * `<style data-livewire-style>`/`<script>` tags use
 * (`vendor/livewire/livewire/src/Mechanisms/FrontendAssets/
 * FrontendAssets.php` calls the same `Vite::cspNonce()` facade, resolving
 * the same `Vite` singleton `ReportContentSecurityPolicy::handle()` seeds via
 * `app(Vite::class)->useCspNonce()` before `$next($request)` runs) — this fix
 * replicates a mechanism already proven to work here, it does not invent one.
 *
 * Deliberately NOT covered by the assertion below: Filament/Livewire's
 * `@script`/`@endscript`-wrapped blocks (`unsaved-action-changes-alert.blade.php`,
 * `page/index.blade.php`, both `notifications`-package views) — traced
 * through `Livewire\Features\SupportScriptsAndAssets\
 * SupportScriptsAndAssets` (the `@script` directive buffers the block into
 * a Livewire component "effect" via `ob_start()`/`Livewire\store(...)->push('scripts', ...)`,
 * never emitted as a literal `<script>` HTML element) and the built
 * `livewire.js` (`on2("effect", ...)` reads `effects.scripts` from the
 * component's JSON payload and runs each one through Alpine's
 * `evaluateExpression()`, i.e. via `Function`/`eval` — already covered by
 * `script-src`'s existing `'unsafe-eval'`, not by a tag-level nonce, since
 * the browser's HTML parser never sees these as `<script>` elements at all).
 * Genuinely out of scope for this bug, not an oversight.
 *
 * The general assertion below (every literal inline `<style>`/`<script
 * [no src]>` tag in the response body carries the header's own nonce, no
 * exceptions listed by name) is deliberate, not an accident of what happened
 * to be checked — the original bug was exactly this kind of tag-by-tag gap
 * that a narrower "does the ONE known tag have a nonce" assertion would have
 * kept missing the same way the pre-fix codebase's own repo-wide grep did
 * (that grep covered this app's OWN `resources/views/`, per
 * `ReportContentSecurityPolicy`'s own doc block, never `vendor/filament/**`).
 */
final class CspNonceCoversEveryInlineTagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // No `public/build/manifest.json` on this host/CI job (frontend
        // builds are a separate CI job with no shared artifact — see
        // `AdminPanelHttpAccessTest`'s own doc block for the full
        // precedent) — harmless for the pages that don't call `@vite()`,
        // required for the ones (every Filament panel page) that do.
        $this->withoutVite();
    }

    public function test_the_public_homepage_has_no_un_nonced_inline_tag(): void
    {
        $this->assertNoUnNoncedInlineTag($this->get('/'));
    }

    public function test_the_admin_login_page_has_no_un_nonced_inline_tag(): void
    {
        $this->assertNoUnNoncedInlineTag($this->get('/admin/login'));
    }

    public function test_the_vendor_login_page_has_no_un_nonced_inline_tag(): void
    {
        $this->assertNoUnNoncedInlineTag($this->get('/vendor/login'));
    }

    /**
     * The authenticated case matters specifically because
     * `livewire/sidebar.blade.php`'s own un-nonced `<script>` (fixed by this
     * change) only renders once a user is signed in — the sidebar isn't part
     * of the (unauthenticated) login page at all, so the two login-page
     * tests above cannot catch it.
     */
    public function test_the_authenticated_admin_dashboard_has_no_un_nonced_inline_tag(): void
    {
        $user = User::factory()->create();
        ActorRoleAssignment::create([
            'actor_identifier' => (string) $user->id,
            'role' => ActorRole::OPERATOR,
        ]);

        $response = $this->actingAs($user)->get('/admin');
        $response->assertOk();

        $this->assertNoUnNoncedInlineTag($response);
    }

    /**
     * Same reasoning as the admin dashboard case above, for the sibling
     * panel — `ReportContentSecurityPolicy`'s own doc block is explicit that
     * `/admin` and `/vendor` are wired independently (both declare their own
     * middleware arrays, neither goes through the `web` group), so proving
     * the fix on one panel's authenticated view does not prove it on the
     * other.
     */
    public function test_the_authenticated_vendor_dashboard_has_no_un_nonced_inline_tag(): void
    {
        $user = User::factory()->create();
        ActorRoleAssignment::create([
            'actor_identifier' => (string) $user->id,
            'role' => ActorRole::VENDOR,
        ]);
        $vendor = Vendor::query()->create(['name' => 'Vendor Uji CSP', 'is_active' => true]);
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->id,
            'entity_type' => ScopeEntityType::VENDOR,
            'entity_id' => (string) $vendor->id,
        ]);

        $response = $this->actingAs($user)->get('/vendor');
        $response->assertOk();

        $this->assertNoUnNoncedInlineTag($response);
    }

    private function assertNoUnNoncedInlineTag(TestResponse $response): void
    {
        $policy = (string) $response->headers->get('Content-Security-Policy');
        $this->assertNotSame('', $policy, 'No Content-Security-Policy header on this response — cannot cross-check tag nonces against it.');

        $this->assertMatchesRegularExpression(
            '/nonce-([A-Za-z0-9]{40})/',
            $policy,
            'The response header itself carries no nonce to cross-check tags against.'
        );

        preg_match('/nonce-([A-Za-z0-9]{40})/', $policy, $headerNonceMatch);
        $headerNonce = $headerNonceMatch[1];

        $html = (string) $response->getContent();
        $this->assertNotSame('', $html);

        $offenders = [];

        preg_match_all('/<(script|style)\b([^>]*)>/i', $html, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            [$fullTag, $tagName, $attributes] = $match;

            // An externally-sourced <script src="..."> is governed by
            // script-src's origin allowlist ('self'), not by the
            // nonce/'unsafe-inline' fallback rule — a nonce is only required
            // for genuinely inline script/style CONTENT. <style> has no
            // src-equivalent (external stylesheets are <link>, not <style>),
            // so every <style> tag is in scope.
            if (strtolower($tagName) === 'script' && preg_match('/\bsrc\s*=/i', $attributes) === 1) {
                continue;
            }

            if (preg_match('/\bnonce\s*=\s*["\']'.preg_quote($headerNonce, '/').'["\']/i', $attributes) === 1) {
                continue;
            }

            $offenders[] = trim($fullTag);
        }

        $this->assertSame(
            [],
            $offenders,
            "Found inline <script>/<style> tag(s) with no nonce matching the response's own CSP header nonce ({$headerNonce}) — each one is silently blocked by the browser under this app's nonce-scoped, no-'unsafe-inline'-fallback CSP, with no server-side signal (no report-uri configured)."
        );
    }
}

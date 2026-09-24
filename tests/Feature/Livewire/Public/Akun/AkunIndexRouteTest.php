<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Akun;

use App\Livewire\Public\Auth\LoginPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `/akun` — the account-area home shell, Task 2 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-shell-and-drafts/task-2-brief.md`).
 * Route-level behaviour only: the `auth` middleware guard and the
 * intended-URL round-trip through `LoginPage::login()`'s
 * `redirectIntended(route('akun.index'), ...)` fallback. `AkunIndex`'s own
 * rendered content — the draft-tile copy and its per-user scoping — is
 * exercised in `AkunIndexTest`.
 */
final class AkunIndexRouteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_guest_is_redirected_to_login_with_the_intended_url_preserved(): void
    {
        $response = $this->get('/akun');

        $response->assertRedirect(route('login'));
        $response->assertSessionHas('url.intended', url('/akun'));
    }

    /**
     * Proves the whole round-trip, not just that the session key was set:
     * hitting a guarded route as a guest, then completing login in the SAME
     * test session, must land back on that exact route —
     * `LoginPage::login()`'s `redirectIntended(route('akun.index'), ...)`
     * call consuming the very `url.intended` key `Authenticate` middleware
     * wrote above. Visits `/akun/draft`, not `/akun`, on purpose:
     * `redirectIntended()`'s OWN fallback (when no intended URL was ever set)
     * is also `route('akun.index')` (`/akun`), so asserting a redirect to
     * `/akun` would pass identically whether the `url.intended` mechanism
     * fired or never ran at all. `/akun/draft` differs from that fallback,
     * so the assertion actually discriminates a working round-trip from a
     * silently-skipped one.
     */
    public function test_logging_in_after_being_redirected_from_akun_returns_there(): void
    {
        $user = User::factory()->create(['password' => 'password']);

        $this->get('/akun/draft');

        Livewire::test(LoginPage::class)
            ->set('email', $user->email)
            ->set('password', 'password')
            ->call('login')
            ->assertRedirect(url('/akun/draft'));
    }

    public function test_an_authenticated_user_can_view_akun(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/akun')->assertOk();
    }

    public function test_bottom_nav_renders_with_akun_active(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/akun');

        $response->assertSee('aria-label="Navigasi utama"', false);

        $html = $response->getContent();
        $start = strpos($html, 'href="/akun"', strpos($html, 'Navigasi utama'));
        $this->assertNotFalse($start, 'Akun tab anchor not found in bottom nav');
        $end = strpos($html, '</a>', $start);
        $anchor = substr($html, $start, $end - $start);

        $this->assertStringContainsString('aria-current="page"', $anchor);
    }

    public function test_the_four_dashboard_tiles_use_the_journey_entrance_card_emphasis(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/akun');
        $response->assertOk();

        $html = $response->getContent();

        // border-primary-200 is a solid, page-wide discriminator: nothing
        // else on this page renders that pair. Kept unscoped.
        $response->assertSee('border-primary-200', false);

        // shadow-md and size-6 are NOT safe to assert page-wide: every
        // interactive <x-mk.card> already renders `hover:shadow-md`
        // regardless of emphasis (card.blade.php's $emphasisHoverShadow
        // maps both 'base' and 'strong' to it), and the shared header's
        // mobile hamburger icon always renders a literal `size-6` class
        // (hidden via CSS, not absent from the HTML) on every page that
        // uses layouts.app. Both substrings were already present before
        // this diff for reasons unrelated to the tile restyle, so a
        // page-wide assertSee can't tell a correct tile from a broken
        // one. Scope to one tile's own <a>...</a> markup instead, found
        // by its unique heading text.
        $headingPos = strpos($html, 'Draft Pemesanan');
        $this->assertNotFalse($headingPos, 'Draft Pemesanan tile heading not found.');

        $cardStart = strrpos(substr($html, 0, $headingPos), '<a ');
        $this->assertNotFalse($cardStart, 'Draft Pemesanan tile is not wrapped in an <a> element.');

        $cardEnd = strpos($html, '</a>', $headingPos);
        $this->assertNotFalse($cardEnd, 'Draft Pemesanan tile <a> element is unterminated.');

        $card = substr($html, $cardStart, $cardEnd - $cardStart);

        // The REST shadow (emphasis="strong" specifically, distinct from
        // the hover shadow every interactive card already carries) is
        // `shadow-md` with no `hover:` prefix immediately before it.
        $this->assertMatchesRegularExpression('/(?<!hover:)shadow-md/', $card);

        // size-13 tile / size-6 icon (icon-medallion.blade.php's
        // $sizes/$iconSizes map for size="lg") — both must appear inside
        // THIS card, proving the size bump landed on the medallion, not
        // just that the header's unrelated size-6 hamburger exists
        // somewhere on the page.
        $this->assertStringContainsString('size-13', $card);
        $this->assertStringContainsString('size-6', $card);
    }
}

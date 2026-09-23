<?php

declare(strict_types=1);

namespace Tests\Feature\View;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `<x-mk.header>`'s account-area link, wired live by this batch
 * (`.superpowers/sdd/2026-08-20-akun-shell-and-drafts/task-2-brief.md`) —
 * `layouts/app.blade.php` now always passes a real `akunHref`, so the
 * header's own "account area not built yet" disabled fallback
 * (`aria-disabled="true"`) must never render again, for a guest or an
 * authenticated visitor.
 */
final class AkunHeaderLinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_guest_sees_a_real_link_to_login(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('login').'"', $html);
        $this->assertStringContainsString('Masuk/Akun', $html);
        $this->assertStringNotContainsString('aria-disabled="true"', $this->headerRegion($html));
    }

    public function test_an_authenticated_user_sees_a_real_link_to_akun(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('akun.index').'"', $html);
        $this->assertStringNotContainsString('Masuk/Akun', $html);
        $this->assertStringContainsString('Akun', $html);
        $this->assertStringNotContainsString('aria-disabled="true"', $this->headerRegion($html));
    }

    /**
     * Scoped to `<header>...</header>` — the homepage legitimately carries
     * its own, unrelated `aria-disabled="true"` control since Stage 3
     * ticket 03 (a "Wakaf Tanah" secondary CTA with no real destination
     * route yet, rendered as an honest disabled control rather than a
     * fabricated URL). A whole-page assertion would false-fail on that
     * addition; this test's actual concern — this file's own doc block —
     * is the header's account-area link specifically.
     */
    private function headerRegion(string $html): string
    {
        $start = strpos($html, '<header');
        $this->assertNotFalse($start, 'Expected a <header> tag in the homepage response.');
        $end = strpos($html, '</header>', $start);
        $this->assertNotFalse($end);

        return substr($html, $start, $end - $start);
    }
}

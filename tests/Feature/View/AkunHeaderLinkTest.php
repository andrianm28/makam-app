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
        $this->assertStringNotContainsString('aria-disabled="true"', $html);
    }

    public function test_an_authenticated_user_sees_a_real_link_to_akun(): void
    {
        $user = User::factory()->create();

        $html = $this->actingAs($user)->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('href="'.route('akun.index').'"', $html);
        $this->assertStringNotContainsString('Masuk/Akun', $html);
        $this->assertStringContainsString('Akun', $html);
        $this->assertStringNotContainsString('aria-disabled="true"', $html);
    }
}

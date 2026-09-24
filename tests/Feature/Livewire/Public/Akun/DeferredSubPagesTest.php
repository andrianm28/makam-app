<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Akun;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `/akun/perpanjangan` and `/akun/dokumen` — Task 3 of the `/akun` account
 * area (`.superpowers/sdd/2026-08-20-akun-shell-and-drafts/task-3-brief.md`).
 * Both are honest "not yet available" pages over `<x-mk.gate-closed-page>`:
 * a real 200, never a raw 403/404, with a working fallback link, for an
 * authenticated user; a guest is redirected to login by the same `auth`
 * middleware as the rest of the `akun.*` group.
 */
final class DeferredSubPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_an_authenticated_user_can_view_the_renewal_not_yet_available_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/akun/perpanjangan');

        $response->assertOk();
        $response->assertSee('href="'.route('perpanjangan.index').'"', false);
    }

    public function test_an_authenticated_user_can_view_the_document_not_yet_available_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/akun/dokumen');

        $response->assertOk();
        $response->assertSee('href="'.route('bantuan.index').'"', false);
    }

    public function test_a_guest_is_redirected_to_login_from_the_renewal_page(): void
    {
        $this->get('/akun/perpanjangan')->assertRedirect(route('login'));
    }

    public function test_a_guest_is_redirected_to_login_from_the_document_page(): void
    {
        $this->get('/akun/dokumen')->assertRedirect(route('login'));
    }

    /**
     * Stage 3 ticket 01's fix round wired `bottomNavActive => 'akun'` into
     * this controller but, unlike its DraftList/OrderList siblings, never
     * got its own bottom-nav assertion — this file already covers the
     * route's other behaviour, so the missing assertion belongs here, not
     * in a new file. Same anchor-isolation pattern as
     * DraftListTest::test_bottom_nav_renders_with_akun_active.
     */
    public function test_bottom_nav_renders_with_akun_active_on_the_renewal_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/akun/perpanjangan');

        $response->assertSee('aria-label="Navigasi utama"', false);

        $html = $response->getContent();
        $start = strpos($html, 'href="/akun"', strpos($html, 'Navigasi utama'));
        $this->assertNotFalse($start, 'Akun tab anchor not found in bottom nav');
        $end = strpos($html, '</a>', $start);
        $anchor = substr($html, $start, $end - $start);

        $this->assertStringContainsString('aria-current="page"', $anchor);
    }

    public function test_bottom_nav_renders_with_akun_active_on_the_document_page(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/akun/dokumen');

        $response->assertSee('aria-label="Navigasi utama"', false);

        $html = $response->getContent();
        $start = strpos($html, 'href="/akun"', strpos($html, 'Navigasi utama'));
        $this->assertNotFalse($start, 'Akun tab anchor not found in bottom nav');
        $end = strpos($html, '</a>', $start);
        $anchor = substr($html, $start, $end - $start);

        $this->assertStringContainsString('aria-current="page"', $anchor);
    }
}

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
}

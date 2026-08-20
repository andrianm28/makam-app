<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Controllers\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `LogoutController` (`POST /keluar`) — Task 1 of the `/akun` account area.
 * See `.superpowers/sdd/2026-08-20-akun-auth-foundation/task-1-brief.md`.
 */
final class LogoutControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_authenticated_post_logs_out_and_redirects_to_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertFalse(auth()->check());
    }

    public function test_a_get_request_is_not_allowed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('logout'))
            ->assertStatus(405);
    }

    public function test_a_guest_post_is_redirected_to_login_by_the_auth_middleware_without_error(): void
    {
        $this->post(route('logout'))
            ->assertRedirect(route('login'));
    }
}

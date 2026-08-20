<?php

declare(strict_types=1);

namespace Tests\Feature\Livewire\Public\Auth;

use App\Livewire\Public\Auth\RegisterPage;
use App\Models\User;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use Filament\Panel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

/**
 * `RegisterPage` (`/daftar`) — Task 2 of the `/akun` account area
 * (`.superpowers/sdd/2026-08-20-akun-auth-foundation/task-2-brief.md`).
 *
 * Mirrors `LoginPageTest`'s structure — see that file first.
 */
final class RegisterPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_valid_registration_creates_a_user_and_authenticates_immediately(): void
    {
        Livewire::test(RegisterPage::class)
            ->set('name', 'Budi Santoso')
            ->set('email', 'budi@example.test')
            ->set('password', 'a-very-secure-password')
            ->set('password_confirmation', 'a-very-secure-password')
            ->call('register')
            ->assertRedirect(route('akun.index'));

        $user = User::query()->where('email', 'budi@example.test')->first();

        $this->assertNotNull($user);
        $this->assertNotSame('a-very-secure-password', $user->password);
        $this->assertTrue(Hash::check('a-very-secure-password', $user->password));

        $this->assertTrue(auth()->check());
        $this->assertSame($user->id, auth()->id());
    }

    public function test_duplicate_email_shows_a_validation_error_and_creates_no_user(): void
    {
        User::factory()->create(['email' => 'sudah@example.test']);

        Livewire::test(RegisterPage::class)
            ->set('name', 'Budi Santoso')
            ->set('email', 'sudah@example.test')
            ->set('password', 'a-very-secure-password')
            ->set('password_confirmation', 'a-very-secure-password')
            ->call('register')
            ->assertHasErrors(['email']);

        $this->assertSame(1, User::query()->where('email', 'sudah@example.test')->count());
        $this->assertFalse(auth()->check());
    }

    public function test_password_confirmation_mismatch_shows_a_validation_error_and_creates_no_user(): void
    {
        Livewire::test(RegisterPage::class)
            ->set('name', 'Budi Santoso')
            ->set('email', 'budi@example.test')
            ->set('password', 'a-very-secure-password')
            ->set('password_confirmation', 'a-different-password')
            ->call('register')
            ->assertHasErrors(['password']);

        $this->assertSame(0, User::query()->where('email', 'budi@example.test')->count());
        $this->assertFalse(auth()->check());
    }

    public function test_newly_registered_user_has_no_panel_access(): void
    {
        Livewire::test(RegisterPage::class)
            ->set('name', 'Budi Santoso')
            ->set('email', 'budi@example.test')
            ->set('password', 'a-very-secure-password')
            ->set('password_confirmation', 'a-very-secure-password')
            ->call('register');

        $user = User::query()->where('email', 'budi@example.test')->firstOrFail();

        $this->assertSame(
            0,
            ActorRoleAssignment::query()->where('actor_identifier', (string) $user->id)->count(),
        );

        $adminPanel = Mockery::mock(Panel::class);
        $adminPanel->shouldReceive('getId')->andReturn('admin');
        $this->assertFalse($user->canAccessPanel($adminPanel));

        $vendorPanel = Mockery::mock(Panel::class);
        $vendorPanel->shouldReceive('getId')->andReturn('vendor');
        $this->assertFalse($user->canAccessPanel($vendorPanel));
    }

    public function test_the_fourth_registration_attempt_within_sixty_seconds_from_the_same_ip_is_blocked(): void
    {
        for ($i = 0; $i < 3; $i++) {
            Livewire::test(RegisterPage::class)
                ->set('name', 'Budi Santoso')
                ->set('email', "budi{$i}@example.test")
                ->set('password', 'a-very-secure-password')
                ->set('password_confirmation', 'a-very-secure-password')
                ->call('register');
        }

        $this->assertSame(3, User::query()->count());

        Livewire::test(RegisterPage::class)
            ->set('name', 'Budi Santoso')
            ->set('email', 'budi-fourth@example.test')
            ->set('password', 'a-very-secure-password')
            ->set('password_confirmation', 'a-very-secure-password')
            ->call('register')
            ->assertHasErrors(['email']);

        $this->assertSame(3, User::query()->count());
        $this->assertSame(0, User::query()->where('email', 'budi-fourth@example.test')->count());
    }
}

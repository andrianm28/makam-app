<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Reauthentication;

use App\Filament\Admin\Pages\PasswordReauthentication;
use App\Models\User;
use App\Platform\IdentityAccess\Models\ActorSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class PasswordReauthenticationPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_correct_password_redirects_and_refreshes_the_actor_session(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);
        $this->actingAs($user);

        Livewire::test(PasswordReauthentication::class)
            ->set('password', 'correct-horse-battery-staple')
            ->call('submit')
            ->assertRedirect();

        $this->assertSame(
            1,
            ActorSession::query()->where('user_id', $user->id)->count(),
            'A successful password check must refresh the actor_sessions freshness row.',
        );
    }

    public function test_the_wrong_password_shows_an_error_and_writes_no_session_row(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);
        $this->actingAs($user);

        Livewire::test(PasswordReauthentication::class)
            ->set('password', 'wrong-password')
            ->call('submit')
            ->assertHasErrors(['password']);

        $this->assertSame(
            0,
            ActorSession::query()->where('user_id', $user->id)->count(),
            'A failed password check must never write an actor_sessions row.',
        );
    }
}

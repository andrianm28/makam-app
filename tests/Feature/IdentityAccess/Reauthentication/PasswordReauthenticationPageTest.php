<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Reauthentication;

use App\Filament\Admin\Pages\PasswordReauthentication;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationAuditActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class PasswordReauthenticationPageTest extends TestCase
{
    use RefreshDatabase;

    private const string WRONG_PASSWORD = 'wrong-password';

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
            ->set('password', self::WRONG_PASSWORD)
            ->call('submit')
            ->assertHasErrors(['password']);

        $this->assertSame(
            0,
            ActorSession::query()->where('user_id', $user->id)->count(),
            'A failed password check must never write an actor_sessions row.',
        );
    }

    public function test_a_wrong_password_writes_exactly_one_failed_audit_event_with_no_credential_in_metadata(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);
        $this->actingAs($user);

        Livewire::test(PasswordReauthentication::class)
            ->set('password', self::WRONG_PASSWORD)
            ->call('submit')
            ->assertHasErrors(['password']);

        $events = AuditEvent::query()
            ->where('action', ReauthenticationAuditActions::FAILED)
            ->get();

        $this->assertCount(1, $events, 'Exactly one FAILED audit event must be written for one wrong-password submission.');

        $metadataJson = json_encode($events->first()->metadata);

        $this->assertIsString($metadataJson);
        $this->assertStringNotContainsString(
            self::WRONG_PASSWORD,
            $metadataJson,
            'The submitted password must never appear in audit metadata.',
        );
    }

    public function test_repeated_wrong_passwords_are_rate_limited_and_stop_writing_audit_events(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);
        $this->actingAs($user);

        // ReauthenticationRateLimiter::MAX_ATTEMPTS is 5 (documented on that
        // class); it is private, so this test asserts against the documented
        // threshold rather than the constant itself.
        $maxAttempts = 5;

        for ($i = 0; $i < $maxAttempts; $i++) {
            Livewire::test(PasswordReauthentication::class)
                ->set('password', self::WRONG_PASSWORD)
                ->call('submit')
                ->assertHasErrors(['password']);
        }

        $this->assertSame(
            $maxAttempts,
            AuditEvent::query()->where('action', ReauthenticationAuditActions::FAILED)->count(),
            'Every attempt up to the threshold must still be audited.',
        );

        $component = Livewire::test(PasswordReauthentication::class)
            ->set('password', self::WRONG_PASSWORD)
            ->call('submit')
            ->assertHasErrors(['password']);

        $errors = $component->errors()->get('password');
        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('Terlalu banyak percobaan', $errors[0]);

        $this->assertSame(
            $maxAttempts,
            AuditEvent::query()->where('action', ReauthenticationAuditActions::FAILED)->count(),
            'A rate-limited attempt must not call Hash::check() again or write a further audit event.',
        );
    }
}

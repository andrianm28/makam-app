<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess;

use App\Livewire\Public\Auth\LoginPage;
use App\Models\User;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Listeners\RecordAuthAuditOnLockout;
use App\Platform\IdentityAccess\Listeners\RecordAuthAuditOnLogin;
use App\Platform\IdentityAccess\Listeners\RecordAuthAuditOnLoginFailed;
use App\Platform\IdentityAccess\Listeners\RecordAuthAuditOnLogout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * SEC-08: authentication events (successful login, failed login, lockout,
 * logout) previously wrote nothing to `audit_events` at all. These
 * listeners are the fix — this test exercises them through the real
 * `/masuk` login surface rather than constructing the Laravel auth events
 * by hand, so it also proves the listeners are actually wired in
 * `IdentityAccessServiceProvider`, not just individually correct.
 */
final class AuthEventAuditTest extends TestCase
{
    use RefreshDatabase;

    private const string PASSWORD = 'correct-horse-battery-staple';

    private const string WRONG_PASSWORD = 'definitely-the-wrong-password';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_successful_login_writes_exactly_one_allowed_auth_login_event(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        Livewire::test(LoginPage::class)
            ->set('email', $user->email)
            ->set('password', self::PASSWORD)
            ->call('login')
            ->assertRedirect(route('akun.index'));

        $events = AuditEvent::query()->where('action', RecordAuthAuditOnLogin::ACTION)->get();

        $this->assertCount(1, $events);
        $this->assertSame(AuditOutcome::Allowed->value, $events->first()->outcome);
        $this->assertSame((string) $user->getAuthIdentifier(), $events->first()->actor_ref);
    }

    public function test_a_failed_login_writes_a_failed_auth_login_failed_event_with_no_credential_anywhere_in_it(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        Livewire::test(LoginPage::class)
            ->set('email', $user->email)
            ->set('password', self::WRONG_PASSWORD)
            ->call('login')
            ->assertHasErrors(['email']);

        $events = AuditEvent::query()->where('action', RecordAuthAuditOnLoginFailed::ACTION)->get();

        $this->assertCount(1, $events);
        $this->assertSame(AuditOutcome::Failed->value, $events->first()->outcome);

        $row = json_encode($events->first()->toArray());
        $this->assertIsString($row);
        $this->assertStringNotContainsString(self::WRONG_PASSWORD, $row);
        $this->assertStringNotContainsString(self::PASSWORD, $row);
    }

    /**
     * An unknown email must not leak which emails exist through the audit
     * trail — the same no-enumeration posture `LoginPage` keeps in its
     * user-facing copy.
     */
    public function test_a_failed_login_for_an_unknown_email_records_no_actor_identity(): void
    {
        Livewire::test(LoginPage::class)
            ->set('email', 'no-such-account@example.test')
            ->set('password', self::WRONG_PASSWORD)
            ->call('login')
            ->assertHasErrors(['email']);

        $event = AuditEvent::query()->where('action', RecordAuthAuditOnLoginFailed::ACTION)->firstOrFail();

        $this->assertNull($event->actor_ref);
        $this->assertSame('guest', $event->actor_role);
    }

    public function test_repeated_failures_trigger_a_lockout_event_recorded_as_denied(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        for ($i = 0; $i < 5; $i++) {
            Livewire::test(LoginPage::class)
                ->set('email', $user->email)
                ->set('password', self::WRONG_PASSWORD)
                ->call('login');
        }

        Livewire::test(LoginPage::class)
            ->set('email', $user->email)
            ->set('password', self::WRONG_PASSWORD)
            ->call('login')
            ->assertHasErrors(['email']);

        $lockouts = AuditEvent::query()->where('action', RecordAuthAuditOnLockout::ACTION)->get();

        $this->assertCount(1, $lockouts);
        $this->assertSame(AuditOutcome::Denied->value, $lockouts->first()->outcome);
        $this->assertNull($lockouts->first()->actor_ref);
    }

    public function test_logging_out_writes_an_allowed_auth_logout_event(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);

        Livewire::test(LoginPage::class)
            ->set('email', $user->email)
            ->set('password', self::PASSWORD)
            ->call('login')
            ->assertRedirect(route('akun.index'));

        $this->post(route('logout'))->assertRedirect(route('login'));

        $events = AuditEvent::query()->where('action', RecordAuthAuditOnLogout::ACTION)->get();

        $this->assertCount(1, $events);
        $this->assertSame(AuditOutcome::Allowed->value, $events->first()->outcome);
        $this->assertSame((string) $user->getAuthIdentifier(), $events->first()->actor_ref);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Reauthentication;

use App\Filament\Admin\Pages\PasswordReauthentication;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\User;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

final class PasswordReauthenticationSatisfiesRecentAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const string SENSITIVE_REASON = 'bank_account_change';

    private const string PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')
            ->get('/__test/sensitive-action', function () {
                return response()->json(['ok' => true]);
            })
            ->middleware(RequireRecentAuthentication::class.':'.self::SENSITIVE_REASON.',test.reauth.challenge');

        Route::middleware('web')
            ->get('/__test/reauth-challenge', function () {
                return response('challenge-page', 200);
            })
            ->name('test.reauth.challenge');

        app('router')->getRoutes()->refreshNameLookups();
    }

    private function staleSessionFor(User $user): ActorSession
    {
        return ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'stale-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subHour(),
        ]);
    }

    private function crossRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
    }

    public function test_a_stale_actor_passes_the_same_gate_after_a_correct_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt(self::PASSWORD)]);
        $this->staleSessionFor($user);
        $this->actingAs($user);

        $this->get('/__test/sensitive-action')->assertRedirect(route('test.reauth.challenge'));

        $this->crossRequestBoundary();

        Livewire::actingAs($user)
            ->test(PasswordReauthentication::class)
            ->set('password', self::PASSWORD)
            ->call('submit')
            ->assertRedirect();

        $this->crossRequestBoundary();

        $this->get('/__test/sensitive-action')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_the_satisfied_event_carries_the_sensitive_action_that_raised_the_challenge(): void
    {
        $user = User::factory()->create(['password' => bcrypt(self::PASSWORD)]);
        $this->staleSessionFor($user);
        $this->actingAs($user);

        $this->get('/__test/sensitive-action')->assertRedirect(route('test.reauth.challenge'));

        $this->crossRequestBoundary();

        Livewire::actingAs($user)
            ->test(PasswordReauthentication::class)
            ->set('password', self::PASSWORD)
            ->call('submit')
            ->assertRedirect();

        $satisfied = ReauthenticationEvent::query()
            ->where('outcome', ReauthenticationOutcome::SATISFIED)
            ->latest('id')
            ->first();

        $this->assertNotNull($satisfied);
        $this->assertSame(self::SENSITIVE_REASON, $satisfied->reason);
    }

    public function test_a_wrong_password_writes_no_satisfied_event_and_leaves_the_actor_stale(): void
    {
        $user = User::factory()->create(['password' => bcrypt(self::PASSWORD)]);
        $staleSession = $this->staleSessionFor($user);
        $staleAt = $staleSession->last_authenticated_at;
        $this->actingAs($user);

        Livewire::actingAs($user)
            ->test(PasswordReauthentication::class)
            ->set('password', 'not-the-password')
            ->call('submit')
            ->assertHasErrors(['password']);

        $this->assertSame(
            0,
            ReauthenticationEvent::query()->where('outcome', ReauthenticationOutcome::SATISFIED)->count(),
        );
        $this->assertTrue(
            $staleSession->refresh()->last_authenticated_at->equalTo($staleAt),
            'A failed challenge must leave last_authenticated_at untouched.',
        );

        $this->crossRequestBoundary();

        $this->get('/__test/sensitive-action')->assertRedirect(route('test.reauth.challenge'));
    }
}

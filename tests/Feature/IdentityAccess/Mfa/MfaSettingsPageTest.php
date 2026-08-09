<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Mfa;

use App\Filament\Admin\Pages\MfaSettings;
use App\Models\User;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentService;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus;
use App\Platform\IdentityAccess\Mfa\Totp\Base32;
use App\Platform\IdentityAccess\Mfa\Totp\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `App\Filament\Admin\Pages\MfaSettings` — self-service enroll, status, and
 * recovery-code regeneration for the currently-authenticated `/admin`
 * user. `test_the_disable_button_links_to_the_gated_route_not_a_local_action`
 * asserts against `route('admin.mfa.disable')`, a route this repo does not
 * yet register (a later task in this same plan adds it) — the `route()`
 * helper call itself throws `RouteNotFoundException` until that lands, on
 * this branch, the same way `MfaSettings`'s own Blade view's `route()` call
 * does. Per the plan's task brief, real CI verification of this whole
 * branch happens only after every task (including that one) is committed
 * here, so this is a deliberate, sequenced dependency, not an oversight.
 */
final class MfaSettingsPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Same "one period ahead" reasoning is NOT needed here (unlike
     * `MfaChallengePageTest::currentCodeFor()`) — this computes a code for
     * a `PENDING` enrolment's FIRST confirmation, so there is no prior
     * `last_verified_counter` for the current time-step to collide with.
     */
    private function currentCodeFor(MfaEnrolment $enrolment): string
    {
        $totp = new Totp(t0: 0, period: $enrolment->period_seconds);

        return $totp->generate(Base32::decode($enrolment->secret), time(), $enrolment->digits);
    }

    /**
     * Starts and confirms an enrolment directly through the service (not
     * through the Livewire component) for tests that need an
     * already-`CONFIRMED` enrolment as their starting state.
     */
    private function confirmViaService(User $user): MfaEnrolment
    {
        $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
        $code = $this->currentCodeFor($enrolment);

        $confirmation = app(MfaEnrolmentService::class)->confirm(
            $enrolment,
            $code,
            actorRef: $user->id,
            actorRole: 'admin',
            source: AuditSource::Panel,
        );

        return $confirmation->enrolment;
    }

    public function test_a_non_enrolled_user_sees_the_enroll_flow(): void
    {
        $user = User::factory()->create();

        Livewire::actingAs($user)
            ->test(MfaSettings::class)
            ->assertSee('otpauth://')
            ->assertSet('enrolmentStatus', MfaEnrolmentStatus::PENDING);
    }

    public function test_confirming_the_first_code_shows_recovery_codes_once(): void
    {
        $user = User::factory()->create();

        $component = Livewire::actingAs($user)->test(MfaSettings::class);

        $enrolment = MfaEnrolment::query()
            ->where('user_id', $user->id)
            ->where('status', MfaEnrolmentStatus::PENDING)
            ->firstOrFail();

        $code = $this->currentCodeFor($enrolment);

        $component->set('confirmationCode', $code)
            ->call('confirmEnrolment')
            ->assertSet('enrolmentStatus', MfaEnrolmentStatus::CONFIRMED);

        $this->assertNotEmpty($component->get('displayedRecoveryCodes'));
    }

    public function test_an_enrolled_user_can_regenerate_recovery_codes_without_a_challenge(): void
    {
        $user = User::factory()->create();
        $this->confirmViaService($user);

        Livewire::actingAs($user)
            ->test(MfaSettings::class)
            ->call('regenerateRecoveryCodes')
            ->assertHasNoErrors();
    }

    public function test_the_disable_button_links_to_the_gated_route_not_a_local_action(): void
    {
        $user = User::factory()->create();
        $this->confirmViaService($user);

        Livewire::actingAs($user)
            ->test(MfaSettings::class)
            ->assertSeeHtml(route('admin.mfa.disable'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Filament\Admin\Pages\FeatureGateAdmin;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\FeatureGate\Models\GateActivation;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The `FeatureGateAdmin` admin page — P2 `admin-data-management` Task 2.
 *
 * Uses the real grant chain (`GrantsActorRoles` -> `actor_role_assignments`
 * -> `ActorRoleReader` -> `ActorContext`) and the real re-authentication
 * fixture (`actor_sessions` -> `LocalUsersTableIdentityAccessAdapter::
 * resolveLastAuthenticatedAt`), the same fixture
 * `RequireRecentAuthenticationMiddlewareTest` established, so the tests
 * prove the whole authorization + freshness chain rather than a fabricated
 * `ActorContext`.
 *
 * `feature_gates` is seeded by migration (`2026_07_26_120400_...`), so
 * every test sees the 17 canonical gates; `G-DATA-01` is the transition
 * target used throughout.
 */
final class FeatureGateAdminTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_back_office_roles_can_access(): void
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            $user = User::factory()->create();
            $this->grantRoleTo($user, $role);
            $this->actingAs($user);
            $this->assertTrue(FeatureGateAdmin::canAccess(), "role {$role}");
        }
    }

    public function test_vendor_role_cannot_access(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::VENDOR);
        $this->actingAs($user);
        $this->assertFalse(FeatureGateAdmin::canAccess());

        Livewire::actingAs($user)
            ->test(FeatureGateAdmin::class)
            ->assertForbidden();
    }

    public function test_admin_can_see_gates(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->assertTrue(FeatureGateAdmin::canAccess());

        // The 17 canonical gates come from the seed migration; add one
        // fixture gate (G-TEST-01 is not a registry id) so the created row
        // itself is visible, not just the seed.
        FeatureGate::query()->create([
            'gate_id' => 'G-TEST-01',
            'capability' => 'data',
            'type' => 'feature',
            'owner' => 'admin',
            'state' => 'closed',
        ]);

        $page = new FeatureGateAdmin;
        $gateIds = $page->gates()->pluck('gate_id');

        $this->assertSame(18, $page->gates()->count());
        $this->assertTrue($gateIds->contains('G-TEST-01'));
        $this->assertTrue($gateIds->contains('G-DATA-01'));
    }

    public function test_transition_reads_gate_evidence_and_reason_from_livewire_properties(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->seedActorSession($user, CarbonImmutable::now());

        FeatureGate::query()->create([
            'gate_id' => 'G-TEST-01',
            'capability' => 'data',
            'type' => 'feature',
            'owner' => 'admin',
            'state' => 'closed',
        ]);
        $this->assertSame('closed', FeatureGate::query()->findOrFail('G-TEST-01')->state);

        // Regression lock for the live dev bug: interpolating the modal
        // values into the wire:click arguments froze them at render time,
        // so the evidence typed after the render never reached the
        // recorder (blank evidence -> MissingActivationEvidenceException,
        // zero activations, gate stays closed). transitionGate() must read
        // the values off the Livewire properties on the confirm request.
        Livewire::actingAs($user)
            ->test(FeatureGateAdmin::class)
            ->set('activeGateId', 'G-TEST-01')
            ->set('activeToState', 'open')
            ->set('evidence', 'UAT evidence')
            ->set('reason', 'UAT reason')
            ->call('transitionGate')
            ->assertNotified('Gerbang diperbarui.')
            ->assertSet('activeGateId', null)
            ->assertSet('activeToState', 'open')
            ->assertSet('evidence', '')
            ->assertSet('reason', '');

        $activation = GateActivation::query()->where('gate_id', 'G-TEST-01')->latest('id')->first();
        $this->assertNotNull($activation);
        $this->assertSame('closed', $activation->from_state);
        $this->assertSame('open', $activation->to_state);
        $this->assertSame('UAT evidence', $activation->evidence_reference);
        $this->assertSame('UAT reason', $activation->reason);
        $this->assertSame((string) $user->id, $activation->actor_reference);

        $this->assertSame('open', FeatureGate::query()->findOrFail('G-TEST-01')->state);

        $audit = AuditEvent::query()
            ->where('action', 'GATE_CHANGE')
            ->where('subject_type', 'feature_gate')
            ->where('subject_id', 'G-TEST-01')
            ->latest('id')
            ->first();
        $this->assertNotNull($audit);
        $this->assertSame((string) $user->id, (string) $audit->actor_ref);
        $this->assertSame(ActorRole::ADMIN, $audit->actor_role);
        $this->assertSame('open', $audit->metadata['new_state']);
    }

    public function test_missing_evidence_surfaces_an_error_and_does_not_change_state(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->seedActorSession($user, CarbonImmutable::now());

        Livewire::actingAs($user)
            ->test(FeatureGateAdmin::class)
            ->set('activeGateId', 'G-DATA-01')
            ->set('activeToState', 'open')
            ->set('evidence', '')
            ->set('reason', 'Test fixture: blank evidence must be refused.')
            ->call('transitionGate')
            ->assertNotified('Gagal memperbarui gerbang');

        $this->assertSame('closed', FeatureGate::query()->findOrFail('G-DATA-01')->state);
        $this->assertSame(0, GateActivation::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'GATE_CHANGE')->count());
    }

    public function test_confirm_button_invokes_the_zero_argument_transition(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        $this->seedActorSession($user, CarbonImmutable::now());

        // Regression lock for two live dev bugs: (1) bare PHP variable
        // names in the wire:click attribute were never interpolated and
        // raised an Alpine ReferenceError; (2) interpolated values were
        // frozen at render time, so evidence typed into the wire:model
        // textareas after the render never reached the recorder. The
        // confirm button must therefore carry NO arguments at all — the
        // zero-arg transitionGate() reads the live Livewire properties
        // server-side on the confirm request.
        Livewire::actingAs($user)
            ->test(FeatureGateAdmin::class)
            ->call('beginTransition', 'G-DATA-01', 'open')
            ->set('evidence', 'ref/regression-evidence')
            ->set('reason', 'Regression fixture reason.')
            ->assertSeeHtml('wire:click="transitionGate()"')
            ->assertDontSeeHtml("transitionGate('G-DATA-01")
            ->assertDontSeeHtml('$activeGateId');
    }

    public function test_a_stale_actor_is_redirected_to_the_challenge_instead_of_transitioning(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);
        // 900 seconds is the documented default freshness window — an hour
        // ago is unambiguously stale under it.
        $this->seedActorSession($user, CarbonImmutable::now()->subHour());

        Livewire::actingAs($user)
            ->test(FeatureGateAdmin::class)
            ->set('activeGateId', 'G-DATA-01')
            ->set('activeToState', 'open')
            ->set('evidence', 'ref/p2-task-2-evidence')
            ->set('reason', 'Test fixture: stale actor must not reach the recorder.')
            ->call('transitionGate')
            ->assertNotified('Perlu verifikasi ulang')
            ->assertRedirect(route('filament.admin.pages.password-reauthentication'));

        $this->assertSame('closed', FeatureGate::query()->findOrFail('G-DATA-01')->state);
        $this->assertSame(0, GateActivation::query()->count());
        $this->assertSame('feature_gate', session()->get(RequireRecentAuthentication::REASON_SESSION_KEY));
        $this->assertSame(route('filament.admin.pages.feature-gates'), session()->get('url.intended'));
    }

    public function test_a_non_admin_back_office_role_cannot_transition_a_gate(): void
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);
        $this->seedActorSession($user, CarbonImmutable::now());

        Livewire::actingAs($user)
            ->test(FeatureGateAdmin::class)
            ->set('activeGateId', 'G-DATA-01')
            ->set('activeToState', 'open')
            ->set('evidence', 'ref/p2-task-2-evidence')
            ->set('reason', 'Test fixture: operator must not be able to change a gate.')
            ->call('transitionGate')
            ->assertNotified('Hanya admin yang dapat mengubah gerbang fitur.');

        $this->assertSame('closed', FeatureGate::query()->findOrFail('G-DATA-01')->state);
        $this->assertSame(0, GateActivation::query()->count());
        $this->assertSame(0, AuditEvent::query()->where('action', 'GATE_CHANGE')->count());
    }

    /**
     * The same fixture `RequireRecentAuthenticationMiddlewareTest` uses:
     * a non-revoked `actor_sessions` row with a fresh
     * `last_authenticated_at`, which is what
     * `LocalUsersTableIdentityAccessAdapter::resolveLastAuthenticatedAt()`
     * reads.
     */
    private function seedActorSession(User $user, CarbonImmutable $lastAuthenticatedAt): ActorSession
    {
        return ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => $lastAuthenticatedAt,
        ]);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\RenewalSource;
use App\Domain\Renewal\RenewalStatus;
use App\Filament\Admin\Resources\RenewalOrders\Actions\MarkExternalRenewalAction;
use App\Filament\Admin\Resources\RenewalOrders\Pages\ListRenewalOrders;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\User;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * The `ListRenewalOrders` header action that gives
 * `App\Domain\Renewal\Actions\MarkExternalRenewal` (§F-84) its missing
 * Filament UI entry point.
 *
 * Follows `FeatureGateAdminTest`'s real re-authentication fixture
 * (`actor_sessions.last_authenticated_at`, the same row
 * `RequireRecentAuthenticationMiddlewareTest` established) and
 * `ReconciliationAdminTest`'s `Livewire::test(ListPage::class)->callAction(...)`
 * convention for a header action mounted on a List page — this action has no
 * bound record at mount time, so it is exercised through the page rather
 * than through a per-row `Action::make($record)` call.
 */
final class MarkExternalRenewalActionTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function grantCemeteryScope(
        User $user,
        string $cemeteryId,
        string $level = ScopeGrantLevel::PRIVILEGED,
    ): void {
        ScopeAssignment::query()->create([
            'actor_identifier' => (string) $user->getAuthIdentifier(),
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => $cemeteryId,
            'grant_level' => $level,
        ]);
    }

    private function seedActorSession(User $user, CarbonImmutable $lastAuthenticatedAt): ActorSession
    {
        return ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'test-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => $lastAuthenticatedAt,
        ]);
    }

    /**
     * Admin role, a privileged cemetery-scope grant for the grave's own
     * cemetery, and a fresh `actor_sessions` row.
     */
    private function fullyAuthorizedAdminFor(GraveRecord $grave): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->grantCemeteryScope($user, (string) $grave->cemetery_id);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();
        $this->seedActorSession($user, CarbonImmutable::now());

        return $user;
    }

    public function test_an_authorized_admin_completes_the_action_and_creates_an_external_renewal(): void
    {
        $grave = GraveRecord::factory()->create();
        $this->fullyAuthorizedAdminFor($grave);

        Livewire::test(ListRenewalOrders::class)
            ->callAction('mark_external_renewal', data: [
                'grave_record_id' => $grave->id,
                'target_due_period' => '2027-03-01',
                'evidence' => 'BUKTI-UI-001',
                'reason' => 'Dibayar langsung di kantor TPU',
            ])
            ->assertHasNoActionErrors()
            ->assertNotified('Perpanjangan eksternal dicatat.');

        $renewal = Renewal::query()->sole();
        $this->assertSame((string) $grave->id, (string) $renewal->grave_record_id);
        $this->assertSame(RenewalSource::EXTERNAL, $renewal->source);
        $this->assertSame(RenewalStatus::DIBAYAR, $renewal->status);
        $this->assertSame('2027-03-01', $renewal->target_due_period->toDateString());
    }

    public function test_a_non_admin_actor_cannot_even_see_the_action(): void
    {
        $grave = GraveRecord::factory()->create();

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->grantCemeteryScope($user, (string) $grave->cemetery_id);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();

        $this->assertFalse(MarkExternalRenewalAction::make()->isAuthorized());
    }

    public function test_an_admin_holding_the_role_but_no_scope_grant_for_the_chosen_cemetery_is_denied(): void
    {
        $grave = GraveRecord::factory()->create();

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        // Deliberately no cemetery-scope grant at all for this actor — the
        // header gate cannot see this (no bound GraveRecord yet), so the
        // button renders; the real refusal must come from
        // RenewalMarkingPolicy inside MarkExternalRenewal::__invoke().
        $this->actingAs($user);
        $this->forgetResolvedActorContext();
        $this->seedActorSession($user, CarbonImmutable::now());

        $this->assertTrue(MarkExternalRenewalAction::make()->isAuthorized());

        Livewire::test(ListRenewalOrders::class)
            ->callAction('mark_external_renewal', data: [
                'grave_record_id' => $grave->id,
                'target_due_period' => '2027-03-01',
                'evidence' => 'BUKTI-UI-002',
                'reason' => 'Dibayar langsung di kantor TPU',
            ])
            ->assertNotified('Gagal mencatat perpanjangan');

        $this->assertSame(0, Renewal::query()->count());
    }

    public function test_a_stale_admin_is_redirected_to_reauthentication_instead_of_the_action_silently_succeeding(): void
    {
        $grave = GraveRecord::factory()->create();

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->grantCemeteryScope($user, (string) $grave->cemetery_id);
        $this->actingAs($user);
        $this->forgetResolvedActorContext();
        // 900 seconds is the documented default freshness window — an hour
        // ago is unambiguously stale under it.
        $this->seedActorSession($user, CarbonImmutable::now()->subHour());

        Livewire::test(ListRenewalOrders::class)
            ->callAction('mark_external_renewal', data: [
                'grave_record_id' => $grave->id,
                'target_due_period' => '2027-03-01',
                'evidence' => 'BUKTI-UI-003',
                'reason' => 'Dibayar langsung di kantor TPU',
            ])
            ->assertNotified('Perlu verifikasi ulang')
            ->assertRedirect(route('filament.admin.pages.verifikasi-ulang-kata-sandi'));

        $this->assertSame(0, Renewal::query()->count());
        $this->assertSame('money_action', session()->get(RequireRecentAuthentication::REASON_SESSION_KEY));
        $this->assertSame(route('filament.admin.resources.pesanan-perpanjangan.index'), session()->get('url.intended'));
    }

    public function test_a_duplicate_grave_and_period_surfaces_the_same_duplicate_exception_the_online_path_raises(): void
    {
        $grave = GraveRecord::factory()->create();
        $this->fullyAuthorizedAdminFor($grave);

        Livewire::test(ListRenewalOrders::class)
            ->callAction('mark_external_renewal', data: [
                'grave_record_id' => $grave->id,
                'target_due_period' => '2027-03-01',
                'evidence' => 'BUKTI-UI-004',
                'reason' => 'Dibayar langsung di kantor TPU',
            ])
            ->assertNotified('Perpanjangan eksternal dicatat.');

        $this->assertSame(1, Renewal::query()->count());

        Livewire::test(ListRenewalOrders::class)
            ->callAction('mark_external_renewal', data: [
                'grave_record_id' => $grave->id,
                'target_due_period' => '2027-03-01',
                'evidence' => 'BUKTI-UI-005',
                'reason' => 'Dibayar langsung di kantor TPU, lagi',
            ])
            ->assertNotified('Periode ini sudah tercatat');

        $this->assertSame(1, Renewal::query()->count());
    }

    /**
     * Regression lock: a malformed `target_due_period` (e.g. `'bukan-tanggal'`)
     * previously reached `MarkExternalRenewal::__invoke()` unvalidated and blew
     * up as an uncaught `Carbon\Exceptions\InvalidFormatException` when
     * `Renewal::create()` cast it — an unhandled 500 instead of the clean
     * "Gagal mencatat perpanjangan" refusal the sibling action shows for an
     * equivalent bad-input case. The field's `date_format:Y-m-d` rule now
     * refuses this at form validation, before the domain call ever runs.
     */
    public function test_a_malformed_target_due_period_is_refused_with_a_form_error_not_an_uncaught_exception(): void
    {
        $grave = GraveRecord::factory()->create();
        $this->fullyAuthorizedAdminFor($grave);

        Livewire::test(ListRenewalOrders::class)
            ->callAction('mark_external_renewal', data: [
                'grave_record_id' => $grave->id,
                'target_due_period' => 'bukan-tanggal',
                'evidence' => 'BUKTI-UI-006',
                'reason' => 'Dibayar langsung di kantor TPU',
            ])
            ->assertHasActionErrors(['target_due_period' => 'date_format']);

        $this->assertSame(0, Renewal::query()->count());
    }
}

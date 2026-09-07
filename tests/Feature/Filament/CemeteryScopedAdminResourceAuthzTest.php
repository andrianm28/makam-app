<?php

declare(strict_types=1);

namespace Tests\Feature\Filament;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Memorial\Actions\CreateMemorialProfile;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Domain\Memorial\Models\ModerationCase;
use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Domain\Visitation\Models\VisitationBooking;
use App\Filament\Admin\Resources\CemeteryVisitationPolicies\CemeteryVisitationPolicyResource;
use App\Filament\Admin\Resources\MemorialProfiles\MemorialProfileResource;
use App\Filament\Admin\Resources\ModerationCases\ModerationCaseResource;
use App\Filament\Admin\Resources\VisitationBookings\VisitationBookingsResource;
use App\Models\User;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment;
use App\Platform\IdentityAccess\Scopes\Actions\RevokeScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\GrantsActorRoles;
use Tests\TestCase;

/**
 * AUTHZ-03: `getEloquentQuery()` on `VisitationBookingsResource`,
 * `MemorialProfileResource`, `ModerationCaseResource`, and
 * `CemeteryVisitationPolicyResource` used to fall through to an
 * UNFILTERED query whenever the acting actor held zero
 * `scope_assignments` cemetery grants — including an `operator` (or any
 * non-`admin`/`restricted_admin` role) whose only grant was revoked, or
 * who never held one at all. That is the opposite of the intended
 * "no grant = no rows" default the vendor/operator `ScopesToCurrent*`
 * traits already enforce.
 *
 * The negative criterion this file proves for all four resources: a
 * cemetery-granted `operator` sees only their cemetery's rows, and once
 * that grant is REVOKED (not merely "never granted"), the same operator
 * sees zero rows — not every row. `admin`/`restricted_admin` remain
 * unconstrained by design (checked explicitly, not left as a side effect
 * of holding no grant).
 */
final class CemeteryScopedAdminResourceAuthzTest extends TestCase
{
    use GrantsActorRoles;
    use RefreshDatabase;

    private function actingOperator(): User
    {
        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::OPERATOR);
        $this->actingAs($user);

        return $user;
    }

    private function grantCemetery(User $user, string $cemeteryId): void
    {
        app(GrantScopeAssignment::class)(
            actorIdentifier: $user->id,
            entityType: ScopeEntityType::CEMETERY,
            entityId: $cemeteryId,
            grantLevel: ScopeGrantLevel::PRIVILEGED,
            reason: 'Test fixture: cemetery-scoped Admin resource authz.',
            grantedBy: null,
        );
        $this->forgetResolvedActorContext();
    }

    private function revokeCemetery(User $user, string $cemeteryId): void
    {
        app(RevokeScopeAssignment::class)(
            actorIdentifier: $user->id,
            entityType: ScopeEntityType::CEMETERY,
            entityId: $cemeteryId,
            reason: 'Test fixture: revoking the cemetery grant under test.',
            revokedBy: null,
        );
        $this->forgetResolvedActorContext();
    }

    private function booking(Cemetery $cemetery): VisitationBooking
    {
        $policy = CemeteryVisitationPolicy::query()->create([
            'cemetery_id' => $cemetery->id,
            'operating_hours' => array_fill_keys(
                CemeteryVisitationPolicy::WEEKDAY_KEYS,
                ['open' => '08:00', 'close' => '16:00'],
            ),
            'daily_capacity' => 10,
        ]);

        return VisitationBooking::query()->create([
            'cemetery_id' => $cemetery->id,
            'policy_id' => $policy->id,
            'visit_date' => now()->addDay()->toDateString(),
            'visitor_count' => 1,
            'contact_phone' => '081234567890',
            'status' => 'requested',
            'idempotency_key' => (string) Str::uuid(),
            'reference' => 'VST-TEST-'.Str::random(8),
        ]);
    }

    private function policyFor(Cemetery $cemetery): CemeteryVisitationPolicy
    {
        return CemeteryVisitationPolicy::query()->create([
            'cemetery_id' => $cemetery->id,
            'operating_hours' => array_fill_keys(
                CemeteryVisitationPolicy::WEEKDAY_KEYS,
                ['open' => '08:00', 'close' => '16:00'],
            ),
            'daily_capacity' => 10,
        ]);
    }

    private function memorialProfileFor(Cemetery $cemetery): MemorialProfile
    {
        $grave = GraveRecord::factory()->create(['cemetery_id' => $cemetery->id]);

        return app(CreateMemorialProfile::class)(
            $grave,
            'test-actor',
            'admin',
            'public',
            AuditSource::Console,
        );
    }

    private function moderationCaseFor(Cemetery $cemetery): ModerationCase
    {
        $profile = $this->memorialProfileFor($cemetery);

        return ModerationCase::query()->create([
            'memorial_profile_id' => $profile->id,
            'reported_content_type' => 'memorial_contents',
            'reported_content_id' => (string) Str::uuid(),
            'status' => ModerationCase::STATUS_OPEN,
        ]);
    }

    public function test_visitation_bookings_resource_closes_the_query_for_a_revoked_grant(): void
    {
        $mine = Cemetery::factory()->create();
        $other = Cemetery::factory()->create();
        $this->booking($mine);
        $this->booking($other);

        $user = $this->actingOperator();
        $this->grantCemetery($user, (string) $mine->id);

        $this->assertSame(1, VisitationBookingsResource::getEloquentQuery()->count());

        $this->revokeCemetery($user, (string) $mine->id);

        $this->assertSame(0, VisitationBookingsResource::getEloquentQuery()->count());
    }

    public function test_visitation_bookings_resource_admin_sees_everything_with_no_grant(): void
    {
        $mine = Cemetery::factory()->create();
        $other = Cemetery::factory()->create();
        $this->booking($mine);
        $this->booking($other);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $this->assertSame(2, VisitationBookingsResource::getEloquentQuery()->count());
    }

    public function test_memorial_profile_resource_closes_the_query_for_a_revoked_grant(): void
    {
        $mine = Cemetery::factory()->create();
        $other = Cemetery::factory()->create();
        $this->memorialProfileFor($mine);
        $this->memorialProfileFor($other);

        $user = $this->actingOperator();
        $this->grantCemetery($user, (string) $mine->id);

        $this->assertSame(1, MemorialProfileResource::getEloquentQuery()->count());

        $this->revokeCemetery($user, (string) $mine->id);

        $this->assertSame(0, MemorialProfileResource::getEloquentQuery()->count());
    }

    public function test_memorial_profile_resource_admin_sees_everything_with_no_grant(): void
    {
        $mine = Cemetery::factory()->create();
        $other = Cemetery::factory()->create();
        $this->memorialProfileFor($mine);
        $this->memorialProfileFor($other);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::RESTRICTED_ADMIN);
        $this->actingAs($user);

        $this->assertSame(2, MemorialProfileResource::getEloquentQuery()->count());
    }

    public function test_moderation_case_resource_closes_the_query_for_a_revoked_grant(): void
    {
        $mine = Cemetery::factory()->create();
        $other = Cemetery::factory()->create();
        $this->moderationCaseFor($mine);
        $this->moderationCaseFor($other);

        $user = $this->actingOperator();
        $this->grantCemetery($user, (string) $mine->id);

        $this->assertSame(1, ModerationCaseResource::getEloquentQuery()->count());

        $this->revokeCemetery($user, (string) $mine->id);

        $this->assertSame(0, ModerationCaseResource::getEloquentQuery()->count());
    }

    public function test_moderation_case_resource_admin_sees_everything_with_no_grant(): void
    {
        $mine = Cemetery::factory()->create();
        $other = Cemetery::factory()->create();
        $this->moderationCaseFor($mine);
        $this->moderationCaseFor($other);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $this->assertSame(2, ModerationCaseResource::getEloquentQuery()->count());
    }

    public function test_cemetery_visitation_policy_resource_closes_the_query_for_a_revoked_grant(): void
    {
        $mine = Cemetery::factory()->create();
        $other = Cemetery::factory()->create();
        $this->policyFor($mine);
        $this->policyFor($other);

        $user = $this->actingOperator();
        $this->grantCemetery($user, (string) $mine->id);

        $this->assertSame(1, CemeteryVisitationPolicyResource::getEloquentQuery()->count());

        $this->revokeCemetery($user, (string) $mine->id);

        $this->assertSame(0, CemeteryVisitationPolicyResource::getEloquentQuery()->count());
    }

    public function test_cemetery_visitation_policy_resource_admin_sees_everything_with_no_grant(): void
    {
        $mine = Cemetery::factory()->create();
        $other = Cemetery::factory()->create();
        $this->policyFor($mine);
        $this->policyFor($other);

        $user = User::factory()->create();
        $this->grantRoleTo($user, ActorRole::ADMIN);
        $this->actingAs($user);

        $this->assertSame(2, CemeteryVisitationPolicyResource::getEloquentQuery()->count());
    }
}

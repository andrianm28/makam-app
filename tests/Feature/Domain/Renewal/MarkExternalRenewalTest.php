<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Renewal;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Renewal\Actions\MarkExternalRenewal;
use App\Domain\Renewal\Actions\OpenRenewal;
use App\Domain\Renewal\Exceptions\DuplicateRenewalPeriodException;
use App\Domain\Renewal\Models\Renewal;
use App\Domain\Renewal\Models\RenewalExternalMarking;
use App\Domain\Renewal\RenewalSource;
use App\Domain\Renewal\RenewalStatus;
use App\Models\User;
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class MarkExternalRenewalTest extends TestCase
{
    use RefreshDatabase;

    private function createAdminUser(): User
    {
        return User::factory()->create();
    }

    private function grantAdminRole(User $user): void
    {
        ActorRoleAssignment::create([
            'actor_identifier' => (string) $user->getAuthIdentifier(),
            'role' => ActorRole::ADMIN,
        ]);
    }

    private function grantCemeteryScope(User $user, string $cemeteryId): void
    {
        ScopeAssignment::create([
            'actor_identifier' => (string) $user->getAuthIdentifier(),
            'entity_type' => 'cemetery',
            'entity_id' => $cemeteryId,
        ]);
    }

    public function test_an_operator_may_not_mark_an_external_renewal(): void
    {
        $user = $this->createAdminUser();
        ActorRoleAssignment::create([
            'actor_identifier' => (string) $user->getAuthIdentifier(),
            'role' => ActorRole::OPERATOR,
        ]);

        $grave = GraveRecord::factory()->create();

        $this->actingAs($user);

        $this->expectException(AuthorizationException::class);

        app(MarkExternalRenewal::class)(
            $grave,
            '2027-03-01',
            evidence: 'BUKTI-001',
            reason: 'Dibayar langsung di kantor TPU'
        );
    }

    public function test_an_admin_without_a_scope_grant_for_the_cemetery_is_denied(): void
    {
        $admin = $this->createAdminUser();
        $this->grantAdminRole($admin);

        $grantedCemetery = Cemetery::query()->first();
        $this->grantCemeteryScope($admin, (string) $grantedCemetery->id);

        $otherCemetery = Cemetery::query()->where('id', '!=', $grantedCemetery->id)->first();
        $grave = GraveRecord::create([
            'cemetery_id' => $otherCemetery->id,
            'deceased_name' => 'Someone Else',
            'block' => 'B-1',
            'death_date' => '2023-01-01',
            'due_date' => '2027-03-01',
            'access_mode' => 'open',
            'source' => 'contoh',
            'source_updated_at' => now(),
        ]);

        $this->actingAs($admin);

        $this->expectException(AuthorizationException::class);

        app(MarkExternalRenewal::class)(
            $grave,
            '2027-03-01',
            evidence: 'BUKTI-002',
            reason: 'Dibayar langsung'
        );
    }

    public function test_a_unicode_blank_reason_is_rejected_and_writes_no_marking(): void
    {
        $admin = $this->createAdminUser();
        $this->grantAdminRole($admin);
        $grave = GraveRecord::factory()->create();
        $this->grantCemeteryScope($admin, (string) $grave->cemetery_id);

        $this->actingAs($admin);

        try {
            app(MarkExternalRenewal::class)(
                $grave,
                '2027-03-01',
                evidence: 'BUKTI-003',
                reason: "\u{00A0}"
            );
        } catch (AuditReasonRequiredException) {
            // expected
        }

        $this->assertSame(0, RenewalExternalMarking::query()->count());
        $this->assertSame(0, Renewal::query()->count());
    }

    public function test_a_marked_external_renewal_blocks_a_later_online_renewal_for_that_period(): void
    {
        $admin = $this->createAdminUser();
        $this->grantAdminRole($admin);
        $grave = GraveRecord::factory()->create();
        $this->grantCemeteryScope($admin, (string) $grave->cemetery_id);

        $this->actingAs($admin);

        $period = $grave->due_date->toDateString();

        app(MarkExternalRenewal::class)(
            $grave,
            $period,
            evidence: 'BUKTI-004',
            reason: 'Dibayar di kantor TPU'
        );

        $this->expectException(DuplicateRenewalPeriodException::class);

        app(OpenRenewal::class)($grave);
    }

    public function test_a_valid_external_marking_creates_renewal_and_marking_rows(): void
    {
        $admin = $this->createAdminUser();
        $this->grantAdminRole($admin);
        $grave = GraveRecord::factory()->create();
        $this->grantCemeteryScope($admin, (string) $grave->cemetery_id);

        $this->actingAs($admin);

        app(MarkExternalRenewal::class)(
            $grave,
            '2027-03-01',
            evidence: 'BUKTI-005',
            reason: 'Pembayaran offline di TPU'
        );

        $this->assertSame(1, Renewal::query()->count());
        $this->assertSame(1, RenewalExternalMarking::query()->count());

        $renewal = Renewal::query()->sole();
        $this->assertSame(RenewalSource::EXTERNAL, $renewal->source);
        $this->assertSame('2027-03-01', $renewal->target_due_period->toDateString());
        $this->assertSame(RenewalStatus::MENUNGGU_PEMBAYARAN, $renewal->status);
    }
}

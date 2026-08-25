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
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `MarkExternalRenewal` / `RenewalMarkingPolicy` — AC10, under Ruling B
 * (explicit human sign-off, 12 Aug 2026): **`admin` only**, `operator`
 * explicitly denied, role AND a privileged cemetery scope grant required.
 *
 * ---------------------------------------------------------------------------
 * Every denial test isolates the ONE check it claims to exercise
 * ---------------------------------------------------------------------------
 * This is the property an earlier revision of this file did not have. Its
 * operator test granted no cemetery scope, so the denial it observed came from
 * the scope check — deleting the entire role check left all five tests green.
 * A test that passes with the rule removed does not test the rule.
 *
 * So: the role tests grant a full privileged scope and vary ONLY the role; the
 * scope tests grant the admin role and vary ONLY the scope. Each denial can
 * then have exactly one cause.
 */
final class MarkExternalRenewalTest extends TestCase
{
    use RefreshDatabase;

    private function actor(): User
    {
        return User::factory()->create();
    }

    private function grantRole(User $user, string $role): void
    {
        ActorRoleAssignment::create([
            'actor_identifier' => (string) $user->getAuthIdentifier(),
            'role' => $role,
        ]);
    }

    private function grantCemeteryScope(
        User $user,
        string $cemeteryId,
        string $level = ScopeGrantLevel::PRIVILEGED,
    ): void {
        ScopeAssignment::create([
            'actor_identifier' => (string) $user->getAuthIdentifier(),
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => $cemeteryId,
            'grant_level' => $level,
        ]);
    }

    private function graveInAnotherCemetery(Cemetery $notThisOne): GraveRecord
    {
        $other = Cemetery::query()->where('id', '!=', $notThisOne->id)->firstOrFail();

        return GraveRecord::create([
            'cemetery_id' => $other->id,
            'deceased_name' => 'Someone Else',
            'block' => 'B-1',
            'death_date' => '2023-01-01',
            'due_date' => '2027-03-01',
            'access_mode' => 'open',
            'source' => 'contoh',
            'source_updated_at' => now(),
        ]);
    }

    /**
     * Ruling B's operator denial. The operator holds a FULL privileged scope
     * grant for this very cemetery, so the scope check cannot be what refuses
     * — only the role check can be. Remove the role check and this fails.
     */
    public function test_an_operator_with_a_privileged_scope_grant_is_still_denied(): void
    {
        $user = $this->actor();
        $this->grantRole($user, ActorRole::OPERATOR);

        $grave = GraveRecord::factory()->create();
        $this->grantCemeteryScope($user, (string) $grave->cemetery_id);

        $this->actingAs($user);

        $this->expectException(AuthorizationException::class);

        app(MarkExternalRenewal::class)(
            $grave,
            '2027-03-01',
            evidence: 'BUKTI-001',
            reason: 'Dibayar langsung di kantor TPU'
        );
    }

    /**
     * The symmetric case: an actor holding no role at all, but a full
     * privileged scope grant. Scope alone must never authorize.
     */
    public function test_an_actor_with_scope_but_no_role_is_denied(): void
    {
        $user = $this->actor();

        $grave = GraveRecord::factory()->create();
        $this->grantCemeteryScope($user, (string) $grave->cemetery_id);

        $this->actingAs($user);

        $this->expectException(AuthorizationException::class);

        app(MarkExternalRenewal::class)(
            $grave,
            '2027-03-01',
            evidence: 'BUKTI-006',
            reason: 'Dibayar langsung di kantor TPU'
        );
    }

    public function test_a_guest_is_denied(): void
    {
        $grave = GraveRecord::factory()->create();

        $this->expectException(AuthorizationException::class);

        app(MarkExternalRenewal::class)(
            $grave,
            '2027-03-01',
            evidence: 'BUKTI-007',
            reason: 'Dibayar langsung di kantor TPU'
        );
    }

    /**
     * Admin role held, privileged grant held — but for a DIFFERENT cemetery
     * than the grave's. Only the scope check can refuse this one.
     */
    public function test_an_admin_without_a_scope_grant_for_the_cemetery_is_denied(): void
    {
        $admin = $this->actor();
        $this->grantRole($admin, ActorRole::ADMIN);

        $grantedCemetery = Cemetery::query()->firstOrFail();
        $this->grantCemeteryScope($admin, (string) $grantedCemetery->id);

        $grave = $this->graveInAnotherCemetery($grantedCemetery);

        $this->actingAs($admin);

        $this->expectException(AuthorizationException::class);

        app(MarkExternalRenewal::class)(
            $grave,
            '2027-03-01',
            evidence: 'BUKTI-002',
            reason: 'Dibayar langsung'
        );
    }

    /**
     * The grant LEVEL is load-bearing. A `read` grant is what an auditor gets
     * for read-only visibility; if it authorized this money-attestation write,
     * every auditor would silently hold an admin capability.
     */
    public function test_an_admin_with_only_a_read_level_grant_is_denied(): void
    {
        $admin = $this->actor();
        $this->grantRole($admin, ActorRole::ADMIN);

        $grave = GraveRecord::factory()->create();
        $this->grantCemeteryScope($admin, (string) $grave->cemetery_id, ScopeGrantLevel::READ);

        $this->actingAs($admin);

        $this->expectException(AuthorizationException::class);

        app(MarkExternalRenewal::class)(
            $grave,
            '2027-03-01',
            evidence: 'BUKTI-008',
            reason: 'Dibayar langsung'
        );
    }

    /**
     * A `scope_assignments` row with a NULL `grant_level` states no authority.
     * Treating "unset" as "any level" would make the level check pass for
     * exactly the rows that never named one.
     */
    public function test_an_admin_with_a_grant_of_no_stated_level_is_denied(): void
    {
        $admin = $this->actor();
        $this->grantRole($admin, ActorRole::ADMIN);

        $grave = GraveRecord::factory()->create();
        ScopeAssignment::create([
            'actor_identifier' => (string) $admin->getAuthIdentifier(),
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => (string) $grave->cemetery_id,
            'grant_level' => null,
        ]);

        $this->actingAs($admin);

        $this->expectException(AuthorizationException::class);

        app(MarkExternalRenewal::class)(
            $grave,
            '2027-03-01',
            evidence: 'BUKTI-009',
            reason: 'Dibayar langsung'
        );
    }

    public function test_a_unicode_blank_reason_is_rejected_and_writes_no_marking(): void
    {
        $admin = $this->authorizedAdminFor($grave = GraveRecord::factory()->create());

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
        $admin = $this->authorizedAdminFor($grave = GraveRecord::factory()->create());

        $this->actingAs($admin);

        app(MarkExternalRenewal::class)(
            $grave,
            $grave->due_date->toDateString(),
            evidence: 'BUKTI-004',
            reason: 'Dibayar di kantor TPU'
        );

        $this->expectException(DuplicateRenewalPeriodException::class);

        app(OpenRenewal::class)($grave);
    }

    /**
     * The mirror of the test above, and the half that was missing: the ADMIN
     * path must fail the same typed way when the online path got there first.
     * Untranslated, the constraint violation escaped as a raw database error,
     * which no admin surface could handle and which leaked the index name.
     */
    public function test_an_online_renewal_blocks_a_later_external_marking_for_that_period(): void
    {
        $admin = $this->authorizedAdminFor($grave = GraveRecord::factory()->create());

        app(OpenRenewal::class)($grave);

        $this->actingAs($admin);

        $this->expectException(DuplicateRenewalPeriodException::class);

        app(MarkExternalRenewal::class)(
            $grave,
            $grave->due_date->toDateString(),
            evidence: 'BUKTI-010',
            reason: 'Dibayar di kantor TPU'
        );
    }

    /**
     * An admin certifying "paid externally, with evidence" is attesting that
     * the money already changed hands. Recording MENUNGGU_PEMBAYARAN would
     * tell a family that had already paid in person to pay again.
     */
    public function test_a_valid_external_marking_records_the_renewal_as_settled(): void
    {
        $admin = $this->authorizedAdminFor($grave = GraveRecord::factory()->create());

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
        $this->assertSame(RenewalStatus::DIBAYAR, $renewal->status);
        $this->assertTrue($renewal->isSettled());
        $this->assertNotNull($renewal->settled_at);
    }

    /**
     * The audit row is the only durable record of who exercised this
     * capability, and nothing else in this file would notice if the recorded
     * role drifted back to a hardcoded constant.
     */
    public function test_the_marking_writes_an_audit_row_naming_the_real_authorizing_role(): void
    {
        $admin = $this->authorizedAdminFor($grave = GraveRecord::factory()->create());

        $this->actingAs($admin);

        app(MarkExternalRenewal::class)(
            $grave,
            '2027-03-01',
            evidence: 'BUKTI-011',
            reason: 'Pembayaran offline di TPU'
        );

        $event = AuditEvent::query()->where('action', 'RENEWAL_EXTERNAL_MARKING')->sole();

        $this->assertSame(ActorRole::ADMIN, $event->actor_role);
        $this->assertSame((string) $admin->getAuthIdentifier(), (string) $event->actor_ref);
        $this->assertSame('Pembayaran offline di TPU', $event->reason);
        $this->assertSame(Renewal::query()->sole()->id, $event->subject_id);
    }

    private function authorizedAdminFor(GraveRecord $grave): User
    {
        $admin = $this->actor();
        $this->grantRole($admin, ActorRole::ADMIN);
        $this->grantCemeteryScope($admin, (string) $grave->cemetery_id);

        return $admin;
    }
}

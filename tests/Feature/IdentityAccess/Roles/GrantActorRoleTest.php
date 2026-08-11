<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Roles;

use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Roles\Actions\GrantActorRole;
use App\Platform\IdentityAccess\Roles\Actions\RevokeActorRole;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\ActorRoleReader;
use App\Platform\IdentityAccess\Roles\RoleAuditActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * `GrantActorRole` / `RevokeActorRole` — the only write path to
 * `actor_role_assignments`. See the design doc's decision 5 and
 * `Audit::wrap()`'s own doc block for the atomicity guarantee under test
 * here: a committed grant with no audit row, or an audit row for a grant
 * that rolled back, are both defects.
 */
final class GrantActorRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_grants_a_role_and_writes_one_audit_row_in_the_same_transaction(): void
    {
        $assignment = app(GrantActorRole::class)(
            actorIdentifier: 42,
            role: ActorRole::FINANCE,
            reason: 'Finance lead onboarding, ticket OPS-114',
            grantedBy: 1,
        );

        $this->assertTrue($assignment->isActive());
        $this->assertDatabaseHas('audit_events', [
            'action' => RoleAuditActions::GRANT,
            'actor_ref' => '1',
            'source' => 'console',
        ]);
        $this->assertSame([ActorRole::FINANCE], (new ActorRoleReader)->rolesForActor(42));
    }

    public function test_it_refuses_a_role_outside_the_closed_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(GrantActorRole::class)(42, 'wizard', 'nope', 1);
    }

    public function test_a_rejected_grant_writes_no_audit_row_and_no_assignment(): void
    {
        try {
            app(GrantActorRole::class)(42, 'wizard', 'nope', 1);
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertDatabaseCount('actor_role_assignments', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_it_requires_a_reason(): void
    {
        $this->expectException(AuditReasonRequiredException::class);

        app(GrantActorRole::class)(42, ActorRole::FINANCE, '   ', 1);
    }

    public function test_a_rejected_grant_for_a_missing_reason_writes_no_assignment_either(): void
    {
        try {
            app(GrantActorRole::class)(42, ActorRole::FINANCE, '   ', 1);
        } catch (AuditReasonRequiredException) {
            // expected
        }

        $this->assertDatabaseCount('actor_role_assignments', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_it_revokes_a_role_and_writes_one_audit_row_returning_the_count_revoked(): void
    {
        app(GrantActorRole::class)(42, ActorRole::FINANCE, 'Finance lead onboarding, ticket OPS-114', 1);

        $revokedCount = app(RevokeActorRole::class)(
            actorIdentifier: 42,
            role: ActorRole::FINANCE,
            reason: 'Left the team',
            revokedBy: 1,
        );

        $this->assertSame(1, $revokedCount);
        $this->assertDatabaseCount('actor_role_assignments', 1);
        $this->assertDatabaseHas('audit_events', [
            'action' => RoleAuditActions::REVOKE,
            'actor_ref' => '1',
            'source' => 'console',
        ]);
        $this->assertSame([], (new ActorRoleReader)->rolesForActor(42));
    }

    public function test_revoke_requires_a_reason(): void
    {
        app(GrantActorRole::class)(42, ActorRole::FINANCE, 'Finance lead onboarding, ticket OPS-114', 1);

        $this->expectException(AuditReasonRequiredException::class);

        app(RevokeActorRole::class)(42, ActorRole::FINANCE, '   ', 1);
    }

    public function test_the_reason_is_never_written_to_metadata(): void
    {
        app(GrantActorRole::class)(42, ActorRole::FINANCE, 'Finance lead onboarding, ticket OPS-114', 1);

        $event = AuditEvent::query()
            ->where('action', RoleAuditActions::GRANT)
            ->firstOrFail();

        $this->assertSame('Finance lead onboarding, ticket OPS-114', $event->reason);
        $this->assertArrayNotHasKey('reason', $event->metadata ?? []);
    }
}

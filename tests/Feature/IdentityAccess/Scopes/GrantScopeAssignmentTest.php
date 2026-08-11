<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Scopes;

use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment;
use App\Platform\IdentityAccess\Scopes\Actions\RevokeScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentReader;
use App\Platform\IdentityAccess\Scopes\ScopeAuditActions;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use InvalidArgumentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `GrantScopeAssignment` / `RevokeScopeAssignment` — the only write path to
 * `scope_assignments`. Mirrors `GrantActorRoleTest` — see that file's own
 * doc block for the atomicity guarantee under test.
 */
final class GrantScopeAssignmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_grants_a_scope_and_writes_one_audit_row_in_the_same_transaction(): void
    {
        $assignment = app(GrantScopeAssignment::class)(
            actorIdentifier: 42,
            entityType: ScopeEntityType::CEMETERY,
            entityId: 4,
            grantLevel: ScopeGrantLevel::PRIVILEGED,
            reason: 'Cemetery operator onboarding, ticket OPS-115',
            grantedBy: 1,
        );

        $this->assertTrue($assignment->isActive());
        $this->assertDatabaseHas('audit_events', [
            'action' => ScopeAuditActions::GRANT,
            'actor_ref' => '1',
            'source' => 'console',
        ]);
        $this->assertSame(['cemetery:4'], (new ScopeAssignmentReader())->scopeStringsForActor(42));
    }

    public function test_it_grants_a_scope_with_no_grant_level(): void
    {
        $assignment = app(GrantScopeAssignment::class)(
            actorIdentifier: 42,
            entityType: ScopeEntityType::VENDOR,
            entityId: 9,
            grantLevel: null,
            reason: 'Vendor onboarding, ticket OPS-116',
            grantedBy: 1,
        );

        $this->assertNull($assignment->grant_level);
        $this->assertTrue($assignment->isActive());
    }

    public function test_it_refuses_an_entity_type_outside_the_closed_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(GrantScopeAssignment::class)(42, 'wizard_realm', 4, null, 'nope', 1);
    }

    public function test_it_refuses_a_grant_level_outside_the_closed_list(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(GrantScopeAssignment::class)(42, ScopeEntityType::CEMETERY, 4, 'super_privileged', 'nope', 1);
    }

    public function test_a_rejected_grant_writes_no_audit_row_and_no_assignment(): void
    {
        try {
            app(GrantScopeAssignment::class)(42, 'wizard_realm', 4, null, 'nope', 1);
        } catch (InvalidArgumentException) {
            // expected
        }

        $this->assertDatabaseCount('scope_assignments', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_it_requires_a_reason(): void
    {
        $this->expectException(AuditReasonRequiredException::class);

        app(GrantScopeAssignment::class)(42, ScopeEntityType::CEMETERY, 4, null, '   ', 1);
    }

    public function test_a_rejected_grant_for_a_missing_reason_writes_no_assignment_either(): void
    {
        try {
            app(GrantScopeAssignment::class)(42, ScopeEntityType::CEMETERY, 4, null, '   ', 1);
        } catch (AuditReasonRequiredException) {
            // expected
        }

        $this->assertDatabaseCount('scope_assignments', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_it_revokes_a_scope_and_a_revoked_grant_disappears_from_the_reader(): void
    {
        app(GrantScopeAssignment::class)(42, ScopeEntityType::CEMETERY, 4, ScopeGrantLevel::PRIVILEGED, 'Cemetery operator onboarding, ticket OPS-115', 1);

        $revokedCount = app(RevokeScopeAssignment::class)(
            actorIdentifier: 42,
            entityType: ScopeEntityType::CEMETERY,
            entityId: 4,
            reason: 'Left the team',
            revokedBy: 1,
        );

        $this->assertSame(1, $revokedCount);
        $this->assertDatabaseCount('scope_assignments', 1);
        $this->assertDatabaseHas('audit_events', [
            'action' => ScopeAuditActions::REVOKE,
            'actor_ref' => '1',
            'source' => 'console',
        ]);
        $this->assertSame([], (new ScopeAssignmentReader())->scopeStringsForActor(42));
    }

    public function test_revoke_requires_a_reason(): void
    {
        app(GrantScopeAssignment::class)(42, ScopeEntityType::CEMETERY, 4, null, 'Cemetery operator onboarding, ticket OPS-115', 1);

        $this->expectException(AuditReasonRequiredException::class);

        app(RevokeScopeAssignment::class)(42, ScopeEntityType::CEMETERY, 4, '   ', 1);
    }

    public function test_the_reason_is_never_written_to_metadata(): void
    {
        // Twin of GrantActorRoleTest's test of the same name. The reason is
        // operator-authored free text: it belongs in the audit `reason`
        // column and nowhere else. Copying it into metadata would route
        // unbounded operator prose into a field governed by
        // MetadataAllowlist, against AGENTS.md §Observability.
        app(GrantScopeAssignment::class)(
            actorIdentifier: 42,
            entityType: ScopeEntityType::CEMETERY,
            entityId: 4,
            grantLevel: ScopeGrantLevel::PRIVILEGED,
            reason: 'Cemetery operator onboarding, ticket OPS-115',
            grantedBy: 1,
        );

        $event = AuditEvent::query()
            ->where('action', ScopeAuditActions::GRANT)
            ->firstOrFail();

        $this->assertSame('Cemetery operator onboarding, ticket OPS-115', $event->reason);

        // Pin that metadata is genuinely populated before asserting what is
        // absent from it — otherwise an empty metadata array would satisfy
        // the assertion below without the guarantee holding for real.
        $this->assertSame(['new_state' => 'cemetery:4'], $event->metadata);
        $this->assertArrayNotHasKey('reason', $event->metadata ?? []);
    }
}

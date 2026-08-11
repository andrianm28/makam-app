<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Console;

use App\Platform\Audit\Models\AuditEvent;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\ActorRoleReader;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use App\Platform\IdentityAccess\Roles\RoleAuditActions;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentReader;
use App\Platform\IdentityAccess\Scopes\ScopeAuditActions;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\IdentityAccess\Scopes\ScopeGrantLevel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The four `identity:*` console commands — the only operator-facing
 * surface onto Task 4's audited grant/revoke Actions
 * (`platform-identity-seam` design doc decision 5). Console-only by human
 * ruling: these commands exist so a role or scope can ever be granted at
 * all, since there is no HTTP, Livewire, or Filament surface that does.
 */
final class IdentityGrantCommandsTest extends TestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------
    // identity:grant-role
    // -----------------------------------------------------------------

    public function test_grant_role_command_creates_an_active_assignment(): void
    {
        $this->artisan('identity:grant-role', [
            'actor' => '42',
            'role' => ActorRole::FINANCE,
            '--reason' => 'Finance lead onboarding, ticket OPS-114',
        ])->assertSuccessful();

        $this->assertDatabaseHas('actor_role_assignments', [
            'actor_identifier' => '42',
            'role' => ActorRole::FINANCE,
            'revoked_at' => null,
        ]);
        $this->assertSame([ActorRole::FINANCE], (new ActorRoleReader)->rolesForActor(42));
    }

    public function test_grant_role_command_fails_without_a_reason(): void
    {
        $this->artisan('identity:grant-role', ['actor' => '42', 'role' => ActorRole::FINANCE])
            ->assertFailed();

        $this->assertDatabaseCount('actor_role_assignments', 0);
    }

    public function test_grant_role_command_fails_with_a_blank_reason(): void
    {
        $this->artisan('identity:grant-role', [
            'actor' => '42', 'role' => ActorRole::FINANCE, '--reason' => '   ',
        ])->assertFailed();

        $this->assertDatabaseCount('actor_role_assignments', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_grant_role_command_rejects_an_unknown_role_with_a_readable_message(): void
    {
        $this->artisan('identity:grant-role', [
            'actor' => '42', 'role' => 'wizard', '--reason' => 'x',
        ])
            ->assertFailed()
            ->expectsOutputToContain(ActorRole::FINANCE);

        $this->assertDatabaseCount('actor_role_assignments', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_grant_role_command_does_not_echo_the_reason_text(): void
    {
        $this->artisan('identity:grant-role', [
            'actor' => '42',
            'role' => ActorRole::FINANCE,
            '--reason' => 'THIS-SECRET-REASON-TEXT-9182',
        ])
            ->assertSuccessful()
            ->doesntExpectOutputToContain('THIS-SECRET-REASON-TEXT-9182');
    }

    // -----------------------------------------------------------------
    // identity:revoke-role
    // -----------------------------------------------------------------

    public function test_revoke_role_command_soft_revokes(): void
    {
        ActorRoleAssignment::create(['actor_identifier' => '42', 'role' => ActorRole::FINANCE]);

        $this->artisan('identity:revoke-role', [
            'actor' => '42', 'role' => ActorRole::FINANCE, '--reason' => 'Left the team',
        ])->assertSuccessful();

        // Soft-revoke: the row survives for history, but the actor no
        // longer resolves the role.
        $this->assertDatabaseCount('actor_role_assignments', 1);
        $this->assertSame([], (new ActorRoleReader)->rolesForActor(42));
    }

    public function test_revoke_role_command_fails_without_a_reason(): void
    {
        ActorRoleAssignment::create(['actor_identifier' => '42', 'role' => ActorRole::FINANCE]);

        $this->artisan('identity:revoke-role', ['actor' => '42', 'role' => ActorRole::FINANCE])
            ->assertFailed();

        $this->assertSame([ActorRole::FINANCE], (new ActorRoleReader)->rolesForActor(42));
    }

    public function test_revoke_role_command_rejects_an_unknown_role(): void
    {
        $this->artisan('identity:revoke-role', [
            'actor' => '42', 'role' => 'wizard', '--reason' => 'x',
        ])
            ->assertFailed()
            ->expectsOutputToContain(ActorRole::FINANCE);
    }

    // -----------------------------------------------------------------
    // identity:grant-scope
    // -----------------------------------------------------------------

    public function test_grant_scope_command_creates_an_active_assignment(): void
    {
        $this->artisan('identity:grant-scope', [
            'actor' => '42',
            'entityType' => ScopeEntityType::CEMETERY,
            'entityId' => '4',
            '--level' => ScopeGrantLevel::PRIVILEGED,
            '--reason' => 'Cemetery operator onboarding, ticket OPS-115',
        ])->assertSuccessful();

        $this->assertDatabaseHas('scope_assignments', [
            'actor_identifier' => '42',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => '4',
            'revoked_at' => null,
        ]);
        $this->assertSame(['cemetery:4'], (new ScopeAssignmentReader)->scopeStringsForActor(42));
    }

    public function test_grant_scope_command_fails_without_a_reason(): void
    {
        $this->artisan('identity:grant-scope', [
            'actor' => '42', 'entityType' => ScopeEntityType::CEMETERY, 'entityId' => '4',
        ])->assertFailed();

        $this->assertDatabaseCount('scope_assignments', 0);
    }

    public function test_grant_scope_command_rejects_an_unknown_entity_type_with_a_readable_message(): void
    {
        $this->artisan('identity:grant-scope', [
            'actor' => '42', 'entityType' => 'spaceship', 'entityId' => '4', '--reason' => 'x',
        ])
            ->assertFailed()
            ->expectsOutputToContain(ScopeEntityType::CEMETERY);

        $this->assertDatabaseCount('scope_assignments', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_grant_scope_command_rejects_an_unknown_grant_level(): void
    {
        $this->artisan('identity:grant-scope', [
            'actor' => '42', 'entityType' => ScopeEntityType::CEMETERY, 'entityId' => '4',
            '--level' => 'godlike', '--reason' => 'x',
        ])
            ->assertFailed()
            ->expectsOutputToContain(ScopeGrantLevel::PRIVILEGED);

        $this->assertDatabaseCount('scope_assignments', 0);
    }

    // -----------------------------------------------------------------
    // identity:revoke-scope
    // -----------------------------------------------------------------

    public function test_revoke_scope_command_soft_revokes(): void
    {
        ScopeAssignment::create([
            'actor_identifier' => '42',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => '4',
        ]);

        $this->artisan('identity:revoke-scope', [
            'actor' => '42', 'entityType' => ScopeEntityType::CEMETERY, 'entityId' => '4',
            '--reason' => 'Left the team',
        ])->assertSuccessful();

        $this->assertDatabaseCount('scope_assignments', 1);
        $this->assertSame([], (new ScopeAssignmentReader)->scopeStringsForActor(42));
    }

    public function test_revoke_scope_command_fails_without_a_reason(): void
    {
        ScopeAssignment::create([
            'actor_identifier' => '42',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => '4',
        ]);

        $this->artisan('identity:revoke-scope', [
            'actor' => '42', 'entityType' => ScopeEntityType::CEMETERY, 'entityId' => '4',
        ])->assertFailed();

        $this->assertSame(['cemetery:4'], (new ScopeAssignmentReader)->scopeStringsForActor(42));
    }

    public function test_revoke_scope_command_rejects_an_unknown_entity_type(): void
    {
        ScopeAssignment::create([
            'actor_identifier' => '42',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => '4',
        ]);

        $this->artisan('identity:revoke-scope', [
            'actor' => '42',
            'entityType' => 'galaxy',
            'entityId' => '4',
            '--reason' => 'Typo in the entity type',
        ])->assertFailed();

        // The real grant must survive a rejected revoke of a bogus type.
        $this->assertSame(['cemetery:4'], (new ScopeAssignmentReader)->scopeStringsForActor(42));
    }

    // -----------------------------------------------------------------
    // Operator attribution
    //
    // audit_events documents a NULL actor_ref as the unattended/system
    // case, so recording null for a human-run grant would mislabel it as
    // a machine action and leave every grant attributable to nobody. The
    // console records the OS account that ran the command, namespaced so
    // it cannot collide with a users.id in the same column.
    // -----------------------------------------------------------------

    public function test_grant_role_command_records_the_console_operator_on_the_audit_row(): void
    {
        $this->artisan('identity:grant-role', [
            'actor' => '42',
            'role' => ActorRole::FINANCE,
            '--reason' => 'Finance lead onboarding, ticket OPS-114',
        ])->assertSuccessful();

        $event = AuditEvent::query()->where('action', RoleAuditActions::GRANT)->firstOrFail();

        $this->assertNotNull($event->actor_ref, 'a human-run grant must not be recorded as an unattended action');
        $this->assertStringStartsWith('console:', (string) $event->actor_ref);
        $this->assertNotSame('console:', $event->actor_ref);
    }

    public function test_revoke_role_command_records_the_console_operator_on_the_audit_row(): void
    {
        ActorRoleAssignment::create(['actor_identifier' => '42', 'role' => ActorRole::FINANCE]);

        $this->artisan('identity:revoke-role', [
            'actor' => '42',
            'role' => ActorRole::FINANCE,
            '--reason' => 'Left the team',
        ])->assertSuccessful();

        $event = AuditEvent::query()->where('action', RoleAuditActions::REVOKE)->firstOrFail();

        $this->assertStringStartsWith('console:', (string) $event->actor_ref);
    }

    public function test_grant_scope_command_records_the_console_operator_on_the_audit_row(): void
    {
        $this->artisan('identity:grant-scope', [
            'actor' => '42',
            'entityType' => ScopeEntityType::CEMETERY,
            'entityId' => '4',
            '--reason' => 'Cemetery operator onboarding, ticket OPS-115',
        ])->assertSuccessful();

        $event = AuditEvent::query()->where('action', ScopeAuditActions::GRANT)->firstOrFail();

        $this->assertStringStartsWith('console:', (string) $event->actor_ref);
    }

    public function test_the_console_operator_ref_cannot_collide_with_a_local_user_id(): void
    {
        // The prefix is the whole point: actor_ref also holds users.id
        // values, so a bare OS account name could be read as a user id.
        $this->artisan('identity:grant-role', [
            'actor' => '42',
            'role' => ActorRole::FINANCE,
            '--reason' => 'Finance lead onboarding, ticket OPS-114',
        ])->assertSuccessful();

        $event = AuditEvent::query()->where('action', RoleAuditActions::GRANT)->firstOrFail();

        $this->assertFalse(
            ctype_digit((string) $event->actor_ref),
            'a console operator ref must never be a bare number indistinguishable from a users.id',
        );
    }

    // -----------------------------------------------------------------
    // Unicode-whitespace reason bypass
    //
    // PHP's trim() strips only ASCII whitespace, so a --reason of a single
    // non-breaking space (U+00A0) reads as non-blank to a trim()-based
    // check while being invisible to a human reviewing the audit trail.
    // A grant justified by one invisible character is indistinguishable,
    // in review, from one nobody authorised.
    //
    // These pin the command-layer guard only. Audit::record()'s own
    // mandatory-reason check now runs the same Unicode-aware pattern, so
    // the bypass is closed at its root for every sensitive action in the
    // application; these tests keep the defence-in-depth layer honest.
    // -----------------------------------------------------------------

    public function test_grant_role_command_rejects_a_unicode_whitespace_only_reason(): void
    {
        $this->artisan('identity:grant-role', [
            'actor' => '42',
            'role' => ActorRole::FINANCE,
            '--reason' => "\u{00A0}",
        ])->assertFailed();

        $this->assertDatabaseCount('actor_role_assignments', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_grant_role_command_rejects_a_reason_that_is_not_valid_utf8(): void
    {
        $this->artisan('identity:grant-role', [
            'actor' => '42',
            'role' => ActorRole::FINANCE,
            '--reason' => "\xA0",
        ])->assertFailed();

        $this->assertDatabaseCount('actor_role_assignments', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_revoke_role_command_rejects_a_unicode_whitespace_only_reason(): void
    {
        ActorRoleAssignment::create(['actor_identifier' => '42', 'role' => ActorRole::FINANCE]);

        $this->artisan('identity:revoke-role', [
            'actor' => '42',
            'role' => ActorRole::FINANCE,
            '--reason' => "\u{3000}",
        ])->assertFailed();

        $this->assertSame([ActorRole::FINANCE], (new ActorRoleReader)->rolesForActor(42));
    }

    public function test_grant_scope_command_rejects_a_unicode_whitespace_only_reason(): void
    {
        $this->artisan('identity:grant-scope', [
            'actor' => '42',
            'entityType' => ScopeEntityType::CEMETERY,
            'entityId' => '4',
            '--reason' => "\u{00A0}\u{3000}",
        ])->assertFailed();

        $this->assertDatabaseCount('scope_assignments', 0);
        $this->assertDatabaseCount('audit_events', 0);
    }

    public function test_revoke_scope_command_rejects_a_unicode_whitespace_only_reason(): void
    {
        ScopeAssignment::create([
            'actor_identifier' => '42',
            'entity_type' => ScopeEntityType::CEMETERY,
            'entity_id' => '4',
        ]);

        $this->artisan('identity:revoke-scope', [
            'actor' => '42',
            'entityType' => ScopeEntityType::CEMETERY,
            'entityId' => '4',
            '--reason' => "\u{00A0}",
        ])->assertFailed();

        $this->assertSame(['cemetery:4'], (new ScopeAssignmentReader)->scopeStringsForActor(42));
    }
}

<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Roles\Actions;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\IdentityAccess\Roles\Models\ActorRoleAssignment;
use App\Platform\IdentityAccess\Roles\RoleAuditActions;

/**
 * The only write path that creates an `actor_role_assignments` row —
 * `platform-identity-seam` design doc decision 5. Console-only by ruling:
 * this class has no HTTP, Livewire, or Filament caller, and none may be
 * added without a new design ruling. `Console\Commands\
 * IdentityGrantRoleCommand` (a later task) is its intended caller.
 *
 * ---------------------------------------------------------------------------
 * Validation happens BEFORE `Audit::wrap()` opens its transaction
 * ---------------------------------------------------------------------------
 * `ActorRole::assertKnown()` runs first, outside any transaction. A rejected
 * grant must leave behind neither an assignment row nor an audit row —
 * running the check inside the transaction would still roll both back
 * correctly, but validating up front keeps the closed-list rule visible at
 * the call site rather than relying on `ActorRoleAssignment`'s own
 * `saving` listener to catch it deep inside the mutation closure.
 *
 * ---------------------------------------------------------------------------
 * `actorRole` is always `ActorRole::SYSTEM` here
 * ---------------------------------------------------------------------------
 * The console operator running `identity:grant-role` is not acting through
 * a resolved `ActorContext` — there is no authenticated web/API session to
 * read a role from. `ActorRole::SYSTEM` is the existing sentinel for "no
 * resolved actor role; this ran unattended/out-of-band," the same value
 * `FinanceVendorPayableAuthorizer::SYSTEM_ROLE` already uses for the
 * equivalent case on the read side.
 */
final readonly class GrantActorRole
{
    public function __invoke(
        int|string $actorIdentifier,
        string $role,
        string $reason,
        int|string|null $grantedBy,
    ): ActorRoleAssignment {
        ActorRole::assertKnown($role);

        return Audit::wrap(
            mutation: fn (): ActorRoleAssignment => ActorRoleAssignment::create([
                'actor_identifier' => (string) $actorIdentifier,
                'role' => $role,
            ]),
            action: RoleAuditActions::GRANT,
            subject: fn (ActorRoleAssignment $assignment): AuditSubject => new AuditSubject('actor_role_assignment', $assignment->id),
            outcome: AuditOutcome::Allowed,
            actorRef: $grantedBy,
            actorRole: ActorRole::SYSTEM,
            source: AuditSource::Console,
            reason: $reason,
            // 'new_state' carries the granted role — see
            // `MetadataAllowlist::ALLOWED_KEYS`'s doc block for why no new
            // key was added for this.
            metadata: ['new_state' => $role],
        );
    }
}

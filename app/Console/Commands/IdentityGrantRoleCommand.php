<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RequiresAuditReason;

use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\IdentityAccess\Roles\Actions\GrantActorRole;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `php artisan identity:grant-role {actor} {role} {--reason=}`
 *
 * The only operator-facing entry point onto `Actions\GrantActorRole` —
 * `platform-identity-seam` design doc decision 5: grants are console-only,
 * audited, and reasoned. No HTTP route, controller, Livewire component, or
 * Filament surface may wrap this Action; that is a human ruling, not a
 * preference, and no self-service or interactive picker is added here that
 * could let a grant proceed without a reason.
 *
 * `--reason` is validated present and non-blank HERE, before the Action
 * runs, so an operator who forgets it gets a readable one-line CLI error
 * instead of an `AuditReasonRequiredException` stack trace. The Action
 * (via `Audit::wrap()` -> `SensitiveActions`) enforces the same rule again
 * independently — this command's own check is a usability layer on top of
 * that enforcement, not a replacement for it.
 *
 * The reason text is operator-authored free text that lands in the audit
 * record (`audit_events.reason`). It is never echoed back to stdout or
 * logged here — `AGENTS.md` §Observability: "Never place restricted data
 * in logs, Pulse, Horizon tags, or error trackers."
 */
final class IdentityGrantRoleCommand extends Command
{
    use RequiresAuditReason;

    protected $signature = 'identity:grant-role {actor} {role} {--reason=}';

    protected $description = 'Grant a role to an actor. Console-only, audited; --reason is mandatory.';

    public function handle(GrantActorRole $grantActorRole): int
    {
        $reason = (string) $this->option('reason');

        if ($this->reasonIsBlank($reason)) {
            $this->error('A non-blank --reason is required to grant a role.');

            return self::FAILURE;
        }

        try {
            $assignment = $grantActorRole(
                actorIdentifier: $this->argument('actor'),
                role: $this->argument('role'),
                reason: $reason,
                // No authenticated ActorContext exists in a console
                // invocation — see `Actions\GrantActorRole`'s own doc
                // block on why `actorRole` is `ActorRole::SYSTEM`
                // regardless. `grantedBy` is null for the same reason:
                // there is no actor identity to reference.
                grantedBy: null,
            );
        } catch (InvalidArgumentException|AuditReasonRequiredException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Granted role [{$assignment->role}] to actor [{$assignment->actor_identifier}]. Audit row written.");

        return self::SUCCESS;
    }
}

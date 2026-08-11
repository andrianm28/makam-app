<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\IdentifiesConsoleOperator;
use App\Console\Commands\Concerns\RequiresAuditReason;
use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\IdentityAccess\Roles\Actions\RevokeActorRole;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `php artisan identity:revoke-role {actor} {role} {--reason=}`
 *
 * The only operator-facing entry point onto `Actions\RevokeActorRole` —
 * structural twin of `IdentityGrantRoleCommand`; see that class's own doc
 * block for the shared background (console-only, `--reason` validated
 * before the Action runs, reason never echoed to stdout).
 *
 * Soft-revoke only: `Actions\RevokeActorRole` sets `revoked_at` on every
 * matching active row rather than deleting any of them, and returns the
 * count revoked, which this command surfaces in its confirmation line.
 */
final class IdentityRevokeRoleCommand extends Command
{
    use IdentifiesConsoleOperator;
    use RequiresAuditReason;

    protected $signature = 'identity:revoke-role {actor} {role} {--reason=}';

    protected $description = 'Revoke a role from an actor. Console-only, audited; --reason is mandatory.';

    public function handle(RevokeActorRole $revokeActorRole): int
    {
        $reason = (string) $this->option('reason');

        if ($this->reasonIsBlank($reason)) {
            $this->error('A non-blank --reason is required to revoke a role.');

            return self::FAILURE;
        }

        $actor = $this->argument('actor');
        $role = $this->argument('role');

        try {
            $revokedCount = $revokeActorRole(
                actorIdentifier: $actor,
                role: $role,
                reason: $reason,
                revokedBy: $this->consoleOperatorRef(),
            );
        } catch (InvalidArgumentException|AuditReasonRequiredException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Revoked role [{$role}] from actor [{$actor}] ({$revokedCount} assignment(s) revoked). Audit row written.");

        return self::SUCCESS;
    }
}

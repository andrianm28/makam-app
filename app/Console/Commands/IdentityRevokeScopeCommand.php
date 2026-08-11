<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\RequiresAuditReason;

use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\IdentityAccess\Scopes\Actions\RevokeScopeAssignment;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `php artisan identity:revoke-scope {actor} {entityType} {entityId} {--reason=}`
 *
 * The only operator-facing entry point onto `Actions\RevokeScopeAssignment`
 * — structural twin of `IdentityRevokeRoleCommand`; see that class's own
 * doc block for the shared background (console-only, `--reason` validated
 * before the Action runs, soft-revoke returns and surfaces a count, reason
 * never echoed to stdout).
 */
final class IdentityRevokeScopeCommand extends Command
{
    use RequiresAuditReason;

    protected $signature = 'identity:revoke-scope {actor} {entityType} {entityId} {--reason=}';

    protected $description = 'Revoke a scope assignment from an actor. Console-only, audited; --reason is mandatory.';

    public function handle(RevokeScopeAssignment $revokeScopeAssignment): int
    {
        $reason = (string) $this->option('reason');

        if ($this->reasonIsBlank($reason)) {
            $this->error('A non-blank --reason is required to revoke a scope.');

            return self::FAILURE;
        }

        $entityType = $this->argument('entityType');
        $entityId = $this->argument('entityId');

        try {
            $revokedCount = $revokeScopeAssignment(
                actorIdentifier: $this->argument('actor'),
                entityType: $entityType,
                entityId: $entityId,
                reason: $reason,
                revokedBy: null,
            );
        } catch (InvalidArgumentException|AuditReasonRequiredException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Revoked scope [{$entityType}:{$entityId}] from actor [{$this->argument('actor')}] ({$revokedCount} assignment(s) revoked). Audit row written.");

        return self::SUCCESS;
    }
}

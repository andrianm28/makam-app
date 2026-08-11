<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\IdentifiesConsoleOperator;
use App\Console\Commands\Concerns\RequiresAuditReason;

use App\Platform\Audit\Exceptions\AuditReasonRequiredException;
use App\Platform\IdentityAccess\Scopes\Actions\GrantScopeAssignment;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * `php artisan identity:grant-scope {actor} {entityType} {entityId} {--level=} {--reason=}`
 *
 * The only operator-facing entry point onto `Actions\GrantScopeAssignment`
 * — structural twin of `IdentityGrantRoleCommand`; see that class's own
 * doc block for the shared background (console-only, `--reason` validated
 * before the Action runs, reason never echoed to stdout).
 *
 * `--level` is optional and forwarded as `null` when omitted or blank —
 * `Actions\GrantScopeAssignment` only validates it against
 * `ScopeGrantLevel::KNOWN_LEVELS` when a non-null value is given, matching
 * `ScopeAssignment`'s own `saving` listener.
 */
final class IdentityGrantScopeCommand extends Command
{
    use IdentifiesConsoleOperator;
    use RequiresAuditReason;

    protected $signature = 'identity:grant-scope {actor} {entityType} {entityId} {--level=} {--reason=}';

    protected $description = 'Grant a scope assignment to an actor. Console-only, audited; --reason is mandatory.';

    public function handle(GrantScopeAssignment $grantScopeAssignment): int
    {
        $reason = (string) $this->option('reason');

        if ($this->reasonIsBlank($reason)) {
            $this->error('A non-blank --reason is required to grant a scope.');

            return self::FAILURE;
        }

        $level = $this->option('level');
        $level = is_string($level) && trim($level) !== '' ? $level : null;

        try {
            $assignment = $grantScopeAssignment(
                actorIdentifier: $this->argument('actor'),
                entityType: $this->argument('entityType'),
                entityId: $this->argument('entityId'),
                grantLevel: $level,
                reason: $reason,
                grantedBy: $this->consoleOperatorRef(),
            );
        } catch (InvalidArgumentException|AuditReasonRequiredException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Granted scope [{$assignment->entity_type}:{$assignment->entity_id}] to actor [{$assignment->actor_identifier}]. Audit row written.");

        return self::SUCCESS;
    }
}

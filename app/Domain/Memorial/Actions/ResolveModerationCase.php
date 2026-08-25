<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Actions;

use App\Domain\Memorial\MemorialAuditActions;
use App\Domain\Memorial\Models\ModerationCase;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use InvalidArgumentException;

/**
 * The audited case-resolution transition for the moderation queue (Task 4's
 * `ModerationCaseResource` — `.kiro/specs/memorial-and-qr/requirements.md`
 * AC6's report intake; the plan's Task 4 brief: "resolve/dismiss with
 * reason + audit").
 *
 * `$resolution` is `ModerationCase::STATUS_RESOLVED` or
 * `ModerationCase::STATUS_DISMISSED` — the two moderator destinations for
 * an open case. A case the moderator has already closed to that state is
 * returned unchanged (idempotent; no second audit), and the case's own
 * status constants are the single vocabulary (`ModerationCase::STATUS_*`).
 *
 * The reason is REQUIRED (a moderator closing a case must record why — the
 * same "a conclusion nobody can review is indistinguishable from none" rule
 * the `AbuseReport` reason guard applies to the intake side) and is carried
 * into the audit row.
 *
 * No outbox event: the catalog carries no case-resolution event; the audit
 * row is the durable trail (same ruling as `ChangeMemorialPrivacy`).
 */
final readonly class ResolveModerationCase
{
    public function __invoke(
        ModerationCase $case,
        string $resolution,
        string $reason,
        int|string $actorReference,
        string $actorRole,
        ?AuditSource $auditSource = null,
    ): ModerationCase {
        if (! in_array($resolution, [ModerationCase::STATUS_RESOLVED, ModerationCase::STATUS_DISMISSED], true)) {
            throw new InvalidArgumentException(
                "Cannot close moderation case [{$case->getKey()}] to [{$resolution}]: a moderator may set ".
                'only resolved or dismissed.'
            );
        }

        if (Audit::reasonIsBlank($reason)) {
            throw new InvalidArgumentException(
                "Cannot close moderation case [{$case->getKey()}]: a resolution requires a reason."
            );
        }

        if ((string) $case->status === $resolution) {
            return $case;
        }

        return Audit::wrap(
            mutation: function () use ($case, $resolution): ModerationCase {
                $case->forceFill(['status' => $resolution])->save();

                return $case->fresh() ?? $case;
            },
            action: $resolution === ModerationCase::STATUS_RESOLVED
                ? MemorialAuditActions::MEMORIAL_MODERATION_CASE_RESOLVED
                : MemorialAuditActions::MEMORIAL_MODERATION_CASE_DISMISSED,
            subject: fn (ModerationCase $row): AuditSubject => new AuditSubject('moderation_case', $row->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource ?? AuditSource::Panel,
            reason: $reason,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}

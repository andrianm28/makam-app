<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Actions;

use App\Domain\Memorial\MemorialAuditActions;
use App\Domain\Memorial\MemorialModerationState;
use App\Domain\Memorial\Models\MemorialContent;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;
use InvalidArgumentException;

/**
 * AC6's moderation transition: pending → approved/rejected/hidden,
 * audited and emitted as `memorial.content_moderated.v1` in the same
 * transaction.
 *
 * `pending` is the submission default, not a moderator's destination —
 * `ModerateMemorialContent` refuses it. Any other from-state is allowed
 * (re-hiding an approved message is the normal flow), so re-moderation
 * is legitimate and simply emits another audit/event pair.
 *
 * `$targetState` accepts the enum or its string value. No outbox
 * idempotency key: every moderation act must be recorded (re-setting a
 * DIFFERENT state must emit again), and re-setting the SAME state is a
 * no-op return below — so no key can express both. Consumers rely on
 * the at-least-once queue contract instead.
 */
final readonly class ModerateMemorialContent
{
    public function __invoke(
        MemorialContent $content,
        MemorialModerationState|string $targetState,
        int|string $actorReference,
        string $actorRole,
        ?AuditSource $auditSource = null,
    ): MemorialContent {
        $target = $targetState instanceof MemorialModerationState
            ? $targetState->value
            : $targetState;

        MemorialModerationState::assertKnown($target);

        if (! in_array($target, MemorialModerationState::MODERATOR_DESTINATIONS, true)) {
            throw new InvalidArgumentException(
                "Cannot moderate memorial content [{$content->getKey()}] to [{$target}]: a moderator may set ".
                'only approved, rejected, or hidden.'
            );
        }

        if ((string) $content->moderation_state === $target) {
            return $content;
        }

        return Audit::wrap(
            mutation: function () use ($content, $target): MemorialContent {
                $content->forceFill(['moderation_state' => $target])->save();

                Outbox::record(
                    eventName: 'memorial.content_moderated.v1',
                    eventVersion: 1,
                    aggregateType: 'memorial_content',
                    aggregateId: (string) $content->getKey(),
                    data: [
                        'memorial_profile_id' => (string) $content->memorial_profile_id,
                        'moderation_state' => $target,
                    ],
                    classification: OutboxClassification::Internal,
                );

                return $content->fresh() ?? $content;
            },
            action: MemorialAuditActions::MEMORIAL_CONTENT_MODERATED,
            subject: fn (MemorialContent $row): AuditSubject => new AuditSubject('memorial_content', $row->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource ?? AuditSource::Panel,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}

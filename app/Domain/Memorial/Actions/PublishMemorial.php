<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Actions;

use App\Domain\Memorial\MemorialAuditActions;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;

/**
 * The private → published transition. Independent of moderation content:
 * the plan's Task 3 brief rules this out explicitly ("NO — publish is
 * independent") — publishing declares the profile's public intent; the
 * allowlist projection still only renders approved content.
 *
 * Idempotent: publishing an already-published profile returns it
 * unchanged and writes nothing (no second audit, no second event) —
 * the design's "retry after an ambiguous response never gets stuck"
 * discipline.
 */
final readonly class PublishMemorial
{
    public function __invoke(
        MemorialProfile $profile,
        int|string $actorReference,
        string $actorRole,
        ?AuditSource $auditSource = null,
    ): MemorialProfile {
        if ($profile->published_at !== null) {
            return $profile;
        }

        return Audit::wrap(
            mutation: function () use ($profile): MemorialProfile {
                $profile->forceFill([
                    'published_at' => now(),
                    'unpublished_at' => null,
                ])->save();

                Outbox::record(
                    eventName: 'memorial.published.v1',
                    eventVersion: 1,
                    aggregateType: 'memorial_profile',
                    aggregateId: (string) $profile->getKey(),
                    data: [
                        'grave_record_id' => (string) $profile->grave_record_id,
                    ],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "memorial.published:{$profile->getKey()}",
                );

                return $profile->fresh() ?? $profile;
            },
            action: MemorialAuditActions::MEMORIAL_PUBLISHED,
            subject: fn (MemorialProfile $row): AuditSubject => new AuditSubject('memorial_profile', $row->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource ?? AuditSource::Panel,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}

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
 * AC5's immediate moderator unpublish. Published → unpublished is
 * INSTANT — `published_at` clears and `unpublished_at` records when, in
 * the same transaction as the audit row and the outbox event.
 *
 * Emits `memorial.unpublished.v1` — the PRE-CATALOGUED event
 * (`docs/contracts/event-catalog.md` already lists it, Producer:
 * Memorial, Consumer: Public read/QR). The action must emit EXACTLY
 * that name; it is the only event name the catalog had before this
 * batch, so the unpublish direction is the one whose name is fixed.
 *
 * Idempotent: unpublishing an already-unpublished profile returns it
 * unchanged and writes nothing — a moderator retrying after an
 * ambiguous response never gets stuck (design.md's Error handling
 * section).
 */
final readonly class UnpublishMemorial
{
    public function __invoke(
        MemorialProfile $profile,
        int|string $actorReference,
        string $actorRole,
        ?AuditSource $auditSource = null,
    ): MemorialProfile {
        if ($profile->published_at === null) {
            return $profile;
        }

        return Audit::wrap(
            mutation: function () use ($profile): MemorialProfile {
                $profile->forceFill([
                    'published_at' => null,
                    'unpublished_at' => now(),
                ])->save();

                Outbox::record(
                    eventName: 'memorial.unpublished.v1',
                    eventVersion: 1,
                    aggregateType: 'memorial_profile',
                    aggregateId: (string) $profile->getKey(),
                    data: [
                        'grave_record_id' => (string) $profile->grave_record_id,
                    ],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "memorial.unpublished:{$profile->getKey()}",
                );

                return $profile->fresh() ?? $profile;
            },
            action: MemorialAuditActions::MEMORIAL_UNPUBLISHED,
            subject: fn (MemorialProfile $row): AuditSubject => new AuditSubject('memorial_profile', $row->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource ?? AuditSource::Panel,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}

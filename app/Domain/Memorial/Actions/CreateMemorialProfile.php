<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Actions;

use App\Domain\GraveRegistry\Models\GraveRecord;
use App\Domain\Memorial\MemorialAuditActions;
use App\Domain\Memorial\MemorialPrivacyMode;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;

/**
 * AC1/AC7's profile creation. `grave_record_id` is the ONLY link taken
 * from the `GraveRecord` — nothing on the grave record is copied onto
 * the profile (AC7), and nothing on the profile writes back to it.
 *
 * Privacy defaults to `private` (AC1) unless `$privacyMode` names
 * another of `MemorialPrivacyMode`'s four modes (validated by the
 * model's saving guard).
 *
 * Idempotent per grave — one profile per grave (the unique
 * `grave_record_id` index is the database backstop): a duplicate
 * creation returns the INCUMBENT profile and writes nothing, mirroring
 * `ReservePlot`'s courtesy fast path ("an idempotent return is not a
 * creation — no hold, no audit"). A concurrent race on the unique index
 * surfaces as a `QueryException` rather than a silent fork.
 *
 * Audit + outbox ride inside the same transaction as the insert
 * (AGENTS.md: critical domain events are inserted into the
 * transactional outbox in the same database transaction as state
 * mutation).
 */
final readonly class CreateMemorialProfile
{
    public function __invoke(
        GraveRecord $grave,
        int|string $actorReference,
        string $actorRole,
        ?string $privacyMode = null,
        AuditSource $auditSource = AuditSource::Panel,
    ): MemorialProfile {
        $incumbent = MemorialProfile::query()
            ->where('grave_record_id', $grave->getKey())
            ->first();

        if ($incumbent instanceof MemorialProfile) {
            return $incumbent;
        }

        return Audit::wrap(
            mutation: function () use ($grave, $privacyMode): MemorialProfile {
                $profile = MemorialProfile::query()->create([
                    'grave_record_id' => $grave->getKey(),
                    'privacy_mode' => $privacyMode ?? MemorialPrivacyMode::DEFAULT,
                ]);

                Outbox::record(
                    eventName: 'memorial.profile_created.v1',
                    eventVersion: 1,
                    aggregateType: 'memorial_profile',
                    aggregateId: (string) $profile->getKey(),
                    data: [
                        'grave_record_id' => (string) $grave->getKey(),
                        'privacy_mode' => (string) $profile->privacy_mode,
                    ],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "memorial.profile_created:{$profile->getKey()}",
                );

                return $profile;
            },
            action: MemorialAuditActions::MEMORIAL_PROFILE_CREATED,
            subject: fn (MemorialProfile $profile): AuditSubject => new AuditSubject('memorial_profile', $profile->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}

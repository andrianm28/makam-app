<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Actions;

use App\Domain\Memorial\MemorialAuditActions;
use App\Domain\Memorial\MemorialPrivacyMode;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;

/**
 * The audited privacy-mode transition for a memorial profile (Task 4's
 * family surface: `.kiro/specs/memorial-and-qr/requirements.md` AC2's four
 * modes; the plan's Task 4 brief — "change privacy (family_only/unlisted/
 * public) audited").
 *
 * The mode change is a visibility decision with consequences for who the QR
 * resolves to, so it is audited (`MEMORIAL_PRIVACY_CHANGED`) with the
 * previous and new modes in the audit metadata — the trail always answers
 * "what did this memorial's visibility used to be, and who changed it".
 *
 * Idempotent: setting the profile's CURRENT mode returns it unchanged and
 * writes nothing (the design's retry discipline, same as
 * `PublishMemorial`/`UnpublishMemorial`).
 *
 * No outbox event: the event catalog carries no memorial privacy event, and
 * this batch does not invent one — the audit row is the durable trail here.
 */
final readonly class ChangeMemorialPrivacy
{
    public function __invoke(
        MemorialProfile $profile,
        string $privacyMode,
        int|string $actorReference,
        string $actorRole,
        ?AuditSource $auditSource = null,
    ): MemorialProfile {
        MemorialPrivacyMode::assertKnown($privacyMode);

        if ((string) $profile->privacy_mode === $privacyMode) {
            return $profile;
        }

        return Audit::wrap(
            mutation: function () use ($profile, $privacyMode): MemorialProfile {
                $profile->forceFill(['privacy_mode' => $privacyMode])->save();

                return $profile->fresh() ?? $profile;
            },
            action: MemorialAuditActions::MEMORIAL_PRIVACY_CHANGED,
            subject: fn (MemorialProfile $row): AuditSubject => new AuditSubject('memorial_profile', $row->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource ?? AuditSource::Panel,
            correlationId: app(CorrelationContext::class)->current()?->value,
            metadata: [
                'previous_state' => (string) $profile->privacy_mode,
                'new_state' => $privacyMode,
            ],
        );
    }
}

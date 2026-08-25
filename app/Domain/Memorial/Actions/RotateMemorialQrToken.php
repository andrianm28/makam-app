<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Actions;

use App\Domain\Memorial\MemorialAuditActions;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Domain\Memorial\Models\MemorialQrToken;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Outbox\Outbox;
use App\Platform\Outbox\OutboxClassification;

/**
 * AC5's token rotation: revoke the current active token in place
 * (`revoked_at` + `rotated_at`) and mint a NEW random token
 * (`Str::random(48)`, never derived) in the same transaction as the
 * audit row and the `memorial.qr_token_rotated.v1` outbox event.
 *
 * The old token row is mutated, not deleted and not rewritten — the old
 * physical QR code now fails exactly like a forgery (the uniform
 * "not available" response), per design.md's Error handling section.
 * The partial unique index on `(memorial_profile_id) WHERE revoked_at
 * IS NULL` releases on the revoke, so the new row can insert.
 *
 * Returns the NEW active token. Idempotency: rotation is a deliberate
 * act (each call revokes and mints — there is no "already rotated" no-op
 * state, the moderator wants a fresh token).
 */
final readonly class RotateMemorialQrToken
{
    public function __invoke(
        MemorialProfile $profile,
        int|string $actorReference,
        string $actorRole,
        ?AuditSource $auditSource = null,
    ): MemorialQrToken {
        return Audit::wrap(
            mutation: function () use ($profile): MemorialQrToken {
                $current = MemorialQrToken::activeFor($profile);

                if ($current instanceof MemorialQrToken) {
                    $current->forceFill([
                        'revoked_at' => now(),
                        'rotated_at' => now(),
                    ])->save();
                }

                $replacement = MemorialQrToken::issueFor($profile);

                Outbox::record(
                    eventName: 'memorial.qr_token_rotated.v1',
                    eventVersion: 1,
                    aggregateType: 'memorial_qr_token',
                    aggregateId: (string) $replacement->getKey(),
                    data: [
                        'memorial_profile_id' => (string) $profile->getKey(),
                        'revoked_token_id' => $current instanceof MemorialQrToken ? (string) $current->getKey() : null,
                    ],
                    classification: OutboxClassification::Internal,
                    idempotencyKey: "memorial.qr_token_rotated:{$replacement->getKey()}",
                );

                return $replacement;
            },
            action: MemorialAuditActions::MEMORIAL_QR_ROTATED,
            subject: fn (MemorialQrToken $token): AuditSubject => new AuditSubject('memorial_qr_token', $token->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource ?? AuditSource::Panel,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }
}

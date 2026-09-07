<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Listeners;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSubject;
use Illuminate\Auth\Events\Logout;

/**
 * SEC-08: audits every logout on the standard
 * `Illuminate\Auth\Events\Logout` event — a sibling of, but deliberately
 * separate from, `RecordActorSessionOnLogout` (which revokes the
 * `actor_sessions` row; this only writes the audit trail entry, per
 * `Audit::record()`'s "one write API" rule keeping audit and domain state
 * writes independent).
 *
 * `$event->user` can be `null` (Laravel allows logging out an already-guest
 * request) — recorded as `actorRef: null`, `actorRole: 'guest'`, the same
 * "no actor identity to reference" shape used elsewhere in this batch.
 *
 * `AUTH_LOGOUT` is not on `SensitiveActions::ACTIONS`, so no `$reason` is
 * required or passed.
 */
final class RecordAuthAuditOnLogout
{
    public const string ACTION = 'AUTH_LOGOUT';

    public function handle(Logout $event): void
    {
        $actorRef = $event->user?->getAuthIdentifier();

        Audit::record(
            action: self::ACTION,
            subject: new AuditSubject('user', $actorRef ?? 'unknown'),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRef !== null ? 'authenticated_actor' : 'guest',
            source: AuthAuditSource::resolve(),
        );
    }
}

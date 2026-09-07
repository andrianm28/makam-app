<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Listeners;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSubject;
use Illuminate\Auth\Events\Login;

/**
 * SEC-08: audits every successful authentication on the standard
 * `Illuminate\Auth\Events\Login` event — the same event
 * `RecordActorSessionOnLogin` already listens to, and (per that listener's
 * own doc block) the one Filament's `/admin` panel login dispatches too,
 * since it authenticates through the same `web` guard.
 *
 * Never given, and never reads, the submitted email/password: `Login` does
 * not carry credentials at all, only the resolved `$user` and `$guard`.
 *
 * `AUTH_LOGIN` is not on `SensitiveActions::ACTIONS`, so no `$reason` is
 * required or passed — see `Audit::record()`'s doc block.
 */
final class RecordAuthAuditOnLogin
{
    public const string ACTION = 'AUTH_LOGIN';

    public function handle(Login $event): void
    {
        $actorRef = $event->user->getAuthIdentifier();

        Audit::record(
            action: self::ACTION,
            subject: new AuditSubject('user', $actorRef),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: 'authenticated_actor',
            source: AuthAuditSource::resolve(),
        );
    }
}

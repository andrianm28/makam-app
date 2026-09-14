<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Listeners;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSubject;
use Illuminate\Auth\Events\Lockout;

/**
 * SEC-08: audits a login rate-limit lockout on the standard
 * `Illuminate\Auth\Events\Lockout` event — dispatched by
 * `LoginPage::login()` when `RateLimiter::tooManyAttempts()` trips, before
 * any credential is even checked.
 *
 * `Lockout` carries only `$request`, never a resolved user or the
 * submitted credentials — there is no identity to record as `actorRef`, so
 * this is `null` with `actorRole: 'guest'`, the same "no actor identity to
 * reference at all" shape `Audit::record()`'s own doc block documents for
 * events with no actor. The IP address is the only correlator available,
 * carried on `AuditSubject` (a reference, not sensitive content) rather
 * than in `metadata`.
 *
 * `AUTH_LOCKOUT` is not on `SensitiveActions::ACTIONS`, so no `$reason` is
 * required or passed.
 */
final class RecordAuthAuditOnLockout
{
    public const string ACTION = 'AUTH_LOCKOUT';

    public function handle(Lockout $event): void
    {
        $ip = $event->request->ip() ?? '0.0.0.0';

        Audit::record(
            action: self::ACTION,
            subject: new AuditSubject('login_lockout', $ip),
            outcome: AuditOutcome::Denied,
            actorRef: null,
            actorRole: 'guest',
            source: AuthAuditSource::resolve(),
        );
    }
}

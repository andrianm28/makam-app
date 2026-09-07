<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Listeners;

use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSubject;
use Illuminate\Auth\Events\Failed;

/**
 * SEC-08: audits every failed authentication attempt on the standard
 * `Illuminate\Auth\Events\Failed` event.
 *
 * ---------------------------------------------------------------------------
 * Never the submitted credentials
 * ---------------------------------------------------------------------------
 * `Failed::$credentials` is the raw array the caller passed to
 * `Auth::attempt()` — for `LoginPage::login()` that is
 * `['email' => ..., 'password' => ...]`. This listener never reads
 * `$event->credentials` at all, matching the discipline
 * `PasswordReauthentication::submit()`'s failed-password branch already
 * follows: audit that an attempt failed, never what was typed.
 *
 * `$event->user` is non-null only when the email resolved to a real account
 * but the password was wrong; null when the email itself does not exist.
 * Recording `null` for the unknown-account case (rather than guessing an
 * identity, or logging the email as a stand-in identity) preserves the same
 * no-enumeration posture `LoginPage` already keeps in its user-facing error
 * copy ("Email atau kata sandi salah." for both cases) — this audit trail
 * must not become a side channel that reveals which emails have accounts.
 *
 * `AUTH_LOGIN_FAILED` is not on `SensitiveActions::ACTIONS`, so no
 * `$reason` is required or passed.
 */
final class RecordAuthAuditOnLoginFailed
{
    public const string ACTION = 'AUTH_LOGIN_FAILED';

    public function handle(Failed $event): void
    {
        $actorRef = $event->user?->getAuthIdentifier();

        Audit::record(
            action: self::ACTION,
            subject: new AuditSubject('user', $actorRef ?? 'unknown'),
            outcome: AuditOutcome::Failed,
            actorRef: $actorRef,
            actorRole: $actorRef !== null ? 'authenticated_actor' : 'guest',
            source: AuthAuditSource::resolve(),
        );
    }
}

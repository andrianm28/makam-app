<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Listeners;

use App\Platform\Audit\AuditSource;
use Illuminate\Http\Request;

/**
 * Shared `AuditSource` resolution for the four SEC-08 auth-event listeners
 * (`RecordAuthAuditOnLogin`, `RecordAuthAuditOnLoginFailed`,
 * `RecordAuthAuditOnLockout`, `RecordAuthAuditOnLogout`) — kept in one place
 * so all four agree on the same rule rather than each re-deriving it.
 *
 * None of Laravel's `Login`/`Failed`/`Lockout`/`Logout` events carry a guard
 * name that distinguishes a Filament panel login from a public `/masuk`
 * login: both authenticate through the same `web` guard (no distinct
 * `->authGuard()` declared anywhere — see
 * `RecordActorSessionOnLogin`'s own doc block for the same observation).
 * The current request path is the only signal available, so a request under
 * `/admin` or `/vendor` is treated as `AuditSource::Panel`; everything else
 * is `AuditSource::Api` — the existing convention this codebase already
 * uses for public-site HTTP actions that are neither Panel, Job, nor
 * Console (see `SaveBookingDraftStep`, `StartBookingDraft`).
 *
 * `AuditSource` has no `Web` case, and none is added here: that enum's own
 * doc block asks for a "deliberate" extension when a genuinely new kind of
 * caller appears, and `Api` already covers "public HTTP request, not a
 * panel" — this is not that.
 */
final class AuthAuditSource
{
    public static function resolve(): AuditSource
    {
        /** @var Request $request */
        $request = app(Request::class);

        if ($request->is('admin/*') || $request->is('vendor/*')) {
            return AuditSource::Panel;
        }

        return AuditSource::Api;
    }
}

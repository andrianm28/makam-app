<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentService;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * `RequireRecentAuthentication`'s first real attachment (see `routes/web.php`
 * for the route) — Task 6, `mfa-reauthentication-integration`.
 *
 * Reached only after that middleware's freshness check has already passed
 * (either the actor's session was already fresh, or they just completed
 * `MfaChallenge` and were redirected back here via the `url.intended`
 * session key both classes share). Calls `ReauthenticationService::satisfy()`
 * first to close out that challenge's audit trail — see that service's own
 * class doc block for why this is a future controller's job, never the
 * middleware's — then performs the actual revoke through
 * `MfaEnrolmentService::revoke()`, the only writer of `mfa_enrolments` rows.
 */
final class DisableMfaController extends Controller
{
    /**
     * Must match the `$reason` this controller's own route passes to
     * `RequireRecentAuthentication::class.':...'` in `routes/web.php` — the
     * two are not otherwise linked by any shared constant (no enum owns
     * re-authentication reason strings; see that middleware's own doc
     * block for why).
     */
    private const string REASON = 'mfa_disable';

    public function __invoke(): RedirectResponse
    {
        $user = Auth::user();
        $actorContext = app(ActorContext::class);

        app(ReauthenticationService::class)->satisfy(
            actorRef: $actorContext->identityReference,
            actorRole: 'authenticated_actor',
            reason: self::REASON,
            source: AuditSource::Panel,
        );

        $enrolment = MfaEnrolment::query()
            ->where('user_id', $user->id)
            ->where('status', MfaEnrolmentStatus::CONFIRMED)
            ->latest('id')
            ->firstOrFail();

        app(MfaEnrolmentService::class)->revoke(
            enrolment: $enrolment,
            actorRef: $actorContext->identityReference,
            actorRole: 'authenticated_actor',
            reason: 'User-initiated self-service disable via /admin/mfa/disable',
            source: AuditSource::Panel,
        );

        return redirect()->route('filament.admin.pages.mfa-settings');
    }
}

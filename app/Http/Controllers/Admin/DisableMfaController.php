<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentService;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * `RequireRecentAuthentication`'s first real attachment (see `routes/web.php`
 * for the route) — Task 6, `mfa-reauthentication-integration`.
 *
 * Reached only after that middleware's freshness check has already passed
 * (either the actor's session was already fresh, or they just completed
 * `MfaChallenge` and were redirected back here via the `url.intended`
 * session key both classes share). Performs the revoke through
 * `MfaEnrolmentService::revoke()`, the only writer of `mfa_enrolments` rows.
 *
 * This controller deliberately records NO re-authentication event. It used
 * to call `ReauthenticationService::satisfy()` unconditionally on every
 * invocation, which verified nothing and minted the very proof a sensitive
 * action is supposed to require — `FinancialLedger`'s bulk export controller
 * was corrected for the identical anti-pattern. `MfaChallenge::submit()`,
 * the one surface that actually verifies a re-proof, now writes that row
 * (carrying this route's own `mfa_disable` reason, threaded through
 * `RequireRecentAuthentication::REASON_SESSION_KEY`), so there is nothing
 * left for this controller to close out.
 */
final class DisableMfaController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $user = Auth::user();
        $actorContext = app(ActorContext::class);

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

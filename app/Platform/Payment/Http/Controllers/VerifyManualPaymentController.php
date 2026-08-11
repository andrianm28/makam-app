<?php

declare(strict_types=1);

namespace App\Platform\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Rules\NonBlankReason;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use App\Platform\Payment\Models\PaymentVerification;
use App\Platform\Payment\PaymentVerificationDecision;
use App\Platform\Payment\VerifyManualPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * `RequireRecentAuthentication`'s SECOND real attachment anywhere in this
 * repo — `App\Http\Controllers\Admin\DisableMfaController` is the first and
 * this class follows its precedent exactly (see `routes/web.php` for the
 * route, which reuses `DisableMfaController`'s literal
 * `['web', 'auth', RequireRecentAuthentication::class.':...']` shape and its
 * `filament.admin.pages.mfa-challenge` challenge destination — no dedicated
 * challenge page exists for payment verification specifically, and
 * inventing one is outside this task's scope).
 *
 * Reached only after that middleware's freshness check has already passed.
 * Calls `ReauthenticationService::satisfy()` first, exactly like
 * `DisableMfaController` does, to close out that challenge's audit trail
 * before performing the actual decision through `VerifyManualPayment`, the
 * only writer of a `payment_verifications` row's decision.
 *
 * No admin UI screen (Filament resource or otherwise) exists yet for
 * reviewing manual payment submissions — the Wave 1c ruling's "Explicitly
 * unavailable and NOT TESTED" list excludes it from this task's scope. This
 * controller redirects to the admin dashboard afterward rather than to a
 * verification list/detail screen that does not exist, the same honest
 * "nothing to bounce back to yet" posture `task-4-report.md` §6.4 records
 * for `MANUAL_REVIEW`'s missing resolution screen.
 */
final class VerifyManualPaymentController extends Controller
{
    /**
     * Must match the `$reason` this controller's own route passes to
     * `RequireRecentAuthentication::class.':...'` in `routes/web.php` — see
     * that middleware's own doc block for why the two are not otherwise
     * linked by a shared constant. Distinct from the mandatory audit
     * `reason` the request body supplies below, which explains WHY the
     * admin approved or rejected this specific submission.
     */
    private const string REASON = 'payment_manual_verification';

    public function __invoke(Request $request, string $paymentVerification): RedirectResponse
    {
        $actorContext = app(ActorContext::class);

        app(ReauthenticationService::class)->satisfy(
            actorRef: $actorContext->identityReference,
            actorRole: 'authenticated_actor',
            reason: self::REASON,
            source: AuditSource::Panel,
        );

        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(['approve', 'reject'])],
            // `required` plus the `TrimStrings` middleware still let a
            // control or private-use character through; it would then reach
            // `Audit::record()` and surface as a 500 rather than a 422.
            'reason' => ['required', 'string', new NonBlankReason],
        ]);

        $verification = PaymentVerification::query()->findOrFail($paymentVerification);

        app(VerifyManualPayment::class)->verify(
            verification: $verification,
            decision: PaymentVerificationDecision::from($validated['decision']),
            reason: $validated['reason'],
            actorRef: $actorContext->identityReference,
            actorRole: 'authenticated_actor',
            source: AuditSource::Panel,
        );

        return redirect()->route('filament.admin.pages.dashboard');
    }
}

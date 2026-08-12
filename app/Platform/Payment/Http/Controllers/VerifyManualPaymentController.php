<?php

declare(strict_types=1);

namespace App\Platform\Payment\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Rules\NonBlankReason;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use App\Platform\Payment\Contracts\PaymentActionAuthorizer;
use App\Platform\Payment\Exceptions\PaymentActionNotAuthorisedException;
use App\Platform\Payment\Models\PaymentVerification;
use App\Platform\Payment\PaymentVerificationDecision;
use App\Platform\Payment\RecordPaymentActionRefusal;
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
 * Authorizes first (below), then calls `ReauthenticationService::satisfy()`
 * to close out that challenge's audit trail, before performing the actual
 * decision through `VerifyManualPayment`, the only writer of a
 * `payment_verifications` row's decision.
 *
 * ---------------------------------------------------------------------------
 * Authorization, and why it precedes both `satisfy()` and `findOrFail()`
 * ---------------------------------------------------------------------------
 * This route ships `['web', 'auth', RequireRecentAuthentication::class...]`
 * and nothing else. `config/auth.php` defines exactly one guard — `web`,
 * provider `users` — with no separate admin guard, so `auth` alone asserted
 * only "some row exists in the shared users table": any authenticated user
 * whose last login fell inside
 * `config('reauthentication.freshness_seconds')` (default 900 s) could
 * approve or reject a manual payment. That was the WHOLE precondition — no
 * MFA was involved. `EnforceMfaChallenge` is attached only to the Filament
 * panel's middleware array (`Providers\Filament\AdminPanelProvider`) and
 * inline on the standalone `/admin/finance/exports` route; this is a plain
 * `Route::post` in neither place, and `RequireRecentAuthentication` reads
 * only `ActorContext::$lastAuthenticatedAt`, never MFA state. That the
 * adjacent finance-export route DOES carry `EnforceMfaChallenge` is what
 * makes the omission here conspicuous rather than merely uniform. Do not
 * credit this path with a compensating control it does not have.
 * `PaymentActionAuthorizer` closes the authority gap, and a refusal becomes
 * a 403 per `Admin\FinanceExportController`'s convention (this app has no
 * framework-level exception mapping).
 *
 * Ordering is load-bearing in three ways:
 *
 * - Before `ReauthenticationService::satisfy()`, which verifies nothing and
 *   unconditionally writes a `satisfied` `reauthentication_events` row plus
 *   an `Allowed` audit row and clears the MFA rate limiter. Calling it
 *   first, as this controller used to, minted a "re-proved their identity"
 *   trail and reset the limiter for an actor about to be refused.
 * - Before validation, so a refused actor learns nothing about whether
 *   their payload was well-formed.
 * - Before `PaymentVerification::findOrFail()`. A role-only check placed
 *   after the lookup would turn this endpoint into an existence oracle over
 *   verification ids — 403 for a real one, 404 for a fake one. Authorizing
 *   first means an unauthorised actor always gets 403, whatever id they
 *   supply. Same defence the ledger module's manual payout Action
 *   documents, and simpler here precisely because the check needs no record.
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
     *
     * Also the subject id of the refusal audit row, so that row names which
     * of the two admin payment endpoints was refused without carrying any
     * caller-supplied value — see `RecordPaymentActionRefusal`.
     */
    private const string REASON = 'payment_manual_verification';

    public function __invoke(Request $request, string $paymentVerification): RedirectResponse
    {
        $actorContext = app(ActorContext::class);

        try {
            $actorRole = app(PaymentActionAuthorizer::class)->authorize($actorContext);
        } catch (PaymentActionNotAuthorisedException) {
            // Exactly one `Denied` row per refusal, written before the 403 so
            // a probe of this endpoint cannot be invisible. It names the
            // refused ACTION, never the `{paymentVerification}` segment —
            // that segment is caller-chosen, and writing it would put an
            // unbounded attacker-supplied value in `audit_events.subject_id`
            // and re-open the existence question this ordering closes. See
            // `RecordPaymentActionRefusal`.
            app(RecordPaymentActionRefusal::class)->record($actorContext, self::REASON);

            abort(403);
        }

        app(ReauthenticationService::class)->satisfy(
            actorRef: $actorContext->identityReference,
            // The role the authorizer approved, not the `authenticated_actor`
            // sentinel this used to hardcode. `Roles\ActorRole`'s own doc
            // block defines that sentinel as meaning "no role applies" and
            // forbids it ever being grantable — so the audit trail for these
            // decisions previously recorded the ABSENCE of a role on every
            // one of them.
            actorRole: $actorRole,
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
            actorRole: $actorRole,
            source: AuditSource::Panel,
        );

        return redirect()->route('filament.admin.pages.dashboard');
    }
}

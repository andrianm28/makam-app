<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Http\Middleware\EnforceMfaChallenge;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\Actions\RecordActorSessionAuthentication;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Mfa\MfaChallengeService;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus;
use App\Platform\IdentityAccess\Mfa\MfaRecoveryService;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * The one challenge surface both `EnforceMfaChallenge` (login-time) and
 * `DisableMfaController` (Task 6, via `RequireRecentAuthentication`)
 * redirect to. Route name `filament.admin.pages.mfa-challenge` — CONFIRMED,
 * see `EnforceMfaChallenge`'s class-level doc block for the full trace.
 *
 * Reads the CONFIRMED `MfaEnrolment` for the CURRENT AUTHENTICATED user
 * directly (`Auth::user()`) — never trusts a route parameter for which
 * enrolment to check against, since this page only ever concerns "prove you
 * are this session's own logged-in user."
 *
 * `strlen($this->code) > 6` distinguishes a 6-digit TOTP code from an
 * 11-character `XXXXX-XXXXX` recovery code (`MfaVerificationMethod`'s two
 * known formats) — one field, no separate UI toggle, since both lengths are
 * fixed and never overlap.
 */
final class MfaChallenge extends Page
{
    /**
     * The fallback `$reason` for a challenge that guards no sensitive action
     * — i.e. an `EnforceMfaChallenge` login-time challenge, or someone
     * opening this page directly. A `RequireRecentAuthentication` challenge
     * supplies the real per-action reason instead, through
     * `RequireRecentAuthentication::REASON_SESSION_KEY`; see
     * `reasonForThisChallenge()`.
     *
     * Generic, and deliberately not a per-action string: naming a sensitive
     * action that nothing in the request actually points at would fabricate
     * proof for an action the actor was never challenged for.
     */
    public const string REAUTHENTICATION_REASON = 'mfa_challenge';

    protected static ?string $slug = 'mfa-challenge';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.admin.pages.mfa-challenge';

    public string $code = '';

    public function submit(): void
    {
        $user = Auth::user();

        $enrolment = MfaEnrolment::query()
            ->where('user_id', $user->id)
            ->where('status', MfaEnrolmentStatus::CONFIRMED)
            ->latest('id')
            ->firstOrFail();

        $actorContext = app(ActorContext::class);
        $ip = request()->ip() ?? '0.0.0.0';

        $result = strlen($this->code) > 6
            ? app(MfaRecoveryService::class)->redeem(
                enrolment: $enrolment,
                submittedCode: $this->code,
                actorRef: $actorContext->identityReference,
                actorRole: 'authenticated_actor',
                source: AuditSource::Panel,
                ip: $ip,
            )
            : app(MfaChallengeService::class)->challenge(
                enrolment: $enrolment,
                submittedCode: $this->code,
                actorRef: $actorContext->identityReference,
                actorRole: 'authenticated_actor',
                source: AuditSource::Panel,
                ip: $ip,
            );

        if (! $result->valid) {
            $this->addError('code', 'Kode tidak valid. Silakan coba lagi.');

            return;
        }

        session()->put(EnforceMfaChallenge::SESSION_KEY, now()->toIso8601String());

        // Both writes below are reached ONLY on `$result->valid === true`.
        //
        // The `actor_sessions` refresh is what makes
        // `RequireRecentAuthentication` work at all: that middleware reads
        // `ActorContext::$lastAuthenticatedAt`, which the adapter derives
        // from this table, and until this call existed the column was
        // written at login and nowhere else — so an actor who re-proved
        // their identity right here was still stale on the next request and
        // was redirected straight back to this page, forever.
        // The PANEL's guard, not `Auth::getDefaultDriver()`: the two agree
        // today only because `AdminPanelProvider` declares no `->authGuard()`,
        // and the login listener writes the guard actually used
        // (`$event->guard`). Reading the panel's own guard keeps the two
        // writers of this column from drifting if a panel ever declares one.
        app(RecordActorSessionAuthentication::class)(
            $user->getAuthIdentifier(),
            Filament::getAuthGuard(),
            request(),
        );

        // The `satisfied` half of the pair `RequireRecentAuthentication`
        // already writes the `challenged` half of — same actor/role/source/
        // ip shape that middleware passes to `challenge()`. Never given the
        // submitted code or any other restricted value; this service takes
        // none.
        app(ReauthenticationService::class)->satisfy(
            actorRef: $actorContext->identityReference,
            actorRole: 'authenticated_actor',
            reason: $this->reasonForThisChallenge(),
            source: AuditSource::Panel,
            ip: $ip,
        );

        // Dashboard's real registered route name is `filament.admin.pages.dashboard`
        // (same `Page::registerRoutes()` -> `Route::name('pages.')` wrapping this
        // class's own doc block traces for `mfa-challenge` — `Pages\Dashboard` never
        // overrides `registerRoutes()`, only `getRoutePath()`), NOT
        // `filament.admin.dashboard` — the plan doc's guess, corrected here.
        //
        // `redirectIntended()` (Livewire's `HandlesRedirects` trait) is the exact
        // `session()->pull('url.intended', $default)` + redirect mechanism
        // `redirect()->intended()` uses on the HTTP side — the same key
        // `EnforceMfaChallenge`/`RequireRecentAuthentication` already populate.
        $this->redirectIntended(route('filament.admin.pages.dashboard'));
    }

    /**
     * The sensitive action this challenge was raised for, if any.
     *
     * `pull()`, not `get()`: one completed challenge must yield proof for
     * exactly one sensitive action. Leaving the key in the session would let
     * a later, unchallenged visit to this page mint a second satisfied row
     * for the same action.
     *
     * Reached only on a valid result, so a mistyped code leaves the key in
     * place for the retry the actor is about to make.
     */
    private function reasonForThisChallenge(): string
    {
        $reason = session()->pull(RequireRecentAuthentication::REASON_SESSION_KEY);

        return is_string($reason) && $reason !== '' ? $reason : self::REAUTHENTICATION_REASON;
    }
}

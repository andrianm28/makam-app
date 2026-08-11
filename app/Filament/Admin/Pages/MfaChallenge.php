<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Http\Middleware\EnforceMfaChallenge;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\Actions\RecordActorSessionAuthentication;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Mfa\MfaChallengeService;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus;
use App\Platform\IdentityAccess\Mfa\MfaRecoveryService;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
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
     * The `$reason` recorded on the `reauthentication_events` row this page
     * writes on success. Deliberately generic: this page is the redirect
     * target of BOTH `EnforceMfaChallenge` (login-time panel access, which
     * has no sensitive action behind it at all) and
     * `RequireRecentAuthentication` (which does know a per-action reason,
     * but threads only `url.intended` through the session, not the reason).
     * Nothing observable at this point in the flow distinguishes the two,
     * so recording the actual proof that happened here — an MFA challenge —
     * is the honest value. Naming a specific sensitive action would be
     * fabricated: the `challenged` row already carries the real reason.
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
        app(RecordActorSessionAuthentication::class)(
            $user->getAuthIdentifier(),
            Auth::getDefaultDriver(),
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
            reason: self::REAUTHENTICATION_REASON,
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
}

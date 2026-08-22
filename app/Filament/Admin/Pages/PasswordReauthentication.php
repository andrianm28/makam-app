<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\Actions\RecordActorSessionAuthentication;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationAuditActions;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationRateLimiter;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Replaces the now-removed `MfaChallenge` Filament page as the step-up
 * re-authentication surface every `RequireRecentAuthentication`-gated
 * route and every inline `ReauthenticationGuard::assertFresh()` catch
 * redirects to. MFA has been removed entirely (see
 * `docs/adr/0024-use-session-auth-and-mfa.md`'s superseding note and
 * `docs/adr/0035-beta-launch-accepted-risks.md` item 10) — this page is
 * the ONLY step-up mechanism now, not one branch of a TOTP-vs-password
 * choice `ReauthenticationService`'s own doc block once anticipated
 * needing.
 *
 * `Hash::check()` against the authenticated user's own stored hash, not
 * `Auth::validate()` with a re-supplied email: this page only ever concerns
 * "prove you are this session's own logged-in user," and the email is
 * already known from the session — asking for it again would only let a
 * wrong-email submission fail in a way this page would then have to
 * explain, for no real security benefit.
 *
 * ---------------------------------------------------------------------------
 * Rate limiting and failure audit — own context, distinct from
 * `ReauthenticationService`'s challenge-raising bucket
 * ---------------------------------------------------------------------------
 * Uses `ReauthenticationRateLimiter` directly under this page's own
 * `RATE_LIMIT_CONTEXT`, deliberately different from
 * `ReauthenticationService::RATE_LIMIT_CONTEXT` ('reauthentication-challenge')
 * — that context is hit by the middleware on every stale-session request,
 * a different rate to bound than this page's actual password-guessing
 * attempts. Sharing one bucket would mean the middleware's own
 * challenge-raising traffic and this page's submissions burned the same
 * budget, which is wrong. A failed submission also writes a
 * `REAUTHENTICATION_FAILED` audit row (never the submitted password
 * itself) so a brute-force attempt against this page leaves the same
 * kind of trail the removed MFA challenge page did.
 */
final class PasswordReauthentication extends Page
{
    public const string ROUTE_NAME = 'filament.admin.pages.password-reauthentication';

    /**
     * The fallback `$reason` for a challenge that guards no specific
     * sensitive action (see `RequireRecentAuthentication::REASON_SESSION_KEY`'s
     * own doc block for why a per-action reason is threaded instead when
     * one exists).
     */
    public const string REAUTHENTICATION_REASON = 'password_reauthentication';

    /**
     * Distinct from `ReauthenticationService::RATE_LIMIT_CONTEXT`
     * ('reauthentication-challenge') — see this class's own doc block.
     */
    private const string RATE_LIMIT_CONTEXT = 'password-reauthentication';

    protected static ?string $slug = 'password-reauthentication';

    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.admin.pages.password-reauthentication';

    public string $password = '';

    public function getTitle(): string
    {
        return 'Verifikasi Ulang';
    }

    public function submit(): void
    {
        $user = Auth::user();

        $actorContext = app(ActorContext::class);
        $ip = request()->ip() ?? '0.0.0.0';
        $rateLimitKey = $actorContext->identityReference ?? 'guest';

        if (ReauthenticationRateLimiter::tooManyAttempts(self::RATE_LIMIT_CONTEXT, $rateLimitKey, $ip)) {
            $this->password = '';
            $this->addError('password', 'Terlalu banyak percobaan. Coba lagi nanti.');

            return;
        }

        ReauthenticationRateLimiter::hit(self::RATE_LIMIT_CONTEXT, $rateLimitKey, $ip);

        if (! Hash::check($this->password, $user->password)) {
            Audit::record(
                action: ReauthenticationAuditActions::FAILED,
                subject: new AuditSubject('password_reauthentication', $rateLimitKey),
                outcome: AuditOutcome::Failed,
                actorRef: $actorContext->identityReference,
                actorRole: 'authenticated_actor',
                source: AuditSource::Panel,
            );

            $this->password = '';
            $this->addError('password', 'Kata sandi salah. Silakan coba lagi.');

            return;
        }

        ReauthenticationRateLimiter::clear(self::RATE_LIMIT_CONTEXT, $rateLimitKey, $ip);

        $this->password = '';

        // Refreshes `actor_sessions` so `RequireRecentAuthentication` sees
        // a fresh `ActorContext::$lastAuthenticatedAt` — without this
        // write, an actor who just re-proved their identity here would
        // still read as stale on the next request and loop back to this
        // page. Uses the PANEL's own guard (not `Auth::getDefaultDriver()`)
        // so this write and the login listener's write of the same column
        // never drift.
        app(RecordActorSessionAuthentication::class)(
            $user->getAuthIdentifier(),
            Filament::getAuthGuard(),
            request(),
        );

        // Writes the `satisfied` half of the pair `RequireRecentAuthentication`
        // already wrote the `challenged` half of. Never given the submitted
        // password or any other restricted value; this service takes none.
        app(ReauthenticationService::class)->satisfy(
            actorRef: $actorContext->identityReference,
            actorRole: 'authenticated_actor',
            reason: $this->reasonForThisChallenge(),
            source: AuditSource::Panel,
            ip: $ip,
        );

        $this->redirectIntended(route('filament.admin.pages.dashboard'));
    }

    /**
     * Pulled (not merely read) from the session so one completed challenge
     * yields proof for exactly one sensitive action.
     */
    private function reasonForThisChallenge(): string
    {
        $reason = session()->pull(RequireRecentAuthentication::REASON_SESSION_KEY);

        return is_string($reason) && $reason !== '' ? $reason : self::REAUTHENTICATION_REASON;
    }
}

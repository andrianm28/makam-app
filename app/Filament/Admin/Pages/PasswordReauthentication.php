<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\Actions\RecordActorSessionAuthentication;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

/**
 * Replaces `MfaChallenge` as the step-up re-authentication surface every
 * `RequireRecentAuthentication`-gated route and every inline
 * `ReauthenticationGuard::assertFresh()` catch redirects to. MFA has been
 * removed entirely (see `docs/adr/0024-use-session-auth-and-mfa.md`'s
 * superseding note and `docs/adr/0035-beta-launch-accepted-risks.md` item
 * 10) — this page is the ONLY step-up mechanism now, not one branch of a
 * TOTP-vs-password choice `ReauthenticationService`'s own doc block once
 * anticipated needing.
 *
 * `Hash::check()` against the authenticated user's own stored hash, not
 * `Auth::validate()` with a re-supplied email: this page only ever concerns
 * "prove you are this session's own logged-in user" (the same rule
 * `MfaChallenge`'s doc block stated for reading `MfaEnrolment`), and the
 * email is already known from the session — asking for it again would only
 * let a wrong-email submission fail in a way this page would then have to
 * explain, for no real security benefit.
 */
final class PasswordReauthentication extends Page
{
    public const string ROUTE_NAME = 'filament.admin.pages.password-reauthentication';

    /**
     * The fallback `$reason` for a challenge that guards no specific
     * sensitive action — mirrors `MfaChallenge::REAUTHENTICATION_REASON`'s
     * exact role (see `RequireRecentAuthentication::REASON_SESSION_KEY`'s
     * own doc block for why a per-action reason is threaded instead when
     * one exists).
     */
    public const string REAUTHENTICATION_REASON = 'password_reauthentication';

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

        if (! Hash::check($this->password, $user->password)) {
            $this->addError('password', 'Kata sandi salah. Silakan coba lagi.');

            return;
        }

        $this->password = '';

        // Same pair of writes `MfaChallenge::submit()` performs on success,
        // for the identical reasons — see that class's own doc comments on
        // each call for the full explanation this file does not repeat.
        app(RecordActorSessionAuthentication::class)(
            $user->getAuthIdentifier(),
            Filament::getAuthGuard(),
            request(),
        );

        $actorContext = app(ActorContext::class);

        app(ReauthenticationService::class)->satisfy(
            actorRef: $actorContext->identityReference,
            actorRole: 'authenticated_actor',
            reason: $this->reasonForThisChallenge(),
            source: AuditSource::Panel,
            ip: request()->ip() ?? '0.0.0.0',
        );

        $this->redirectIntended(route('filament.admin.pages.dashboard'));
    }

    /**
     * Identical logic to `MfaChallenge::reasonForThisChallenge()` — pulled
     * (not merely read) from the session so one completed challenge yields
     * proof for exactly one sensitive action.
     */
    private function reasonForThisChallenge(): string
    {
        $reason = session()->pull(RequireRecentAuthentication::REASON_SESSION_KEY);

        return is_string($reason) && $reason !== '' ? $reason : self::REAUTHENTICATION_REASON;
    }
}

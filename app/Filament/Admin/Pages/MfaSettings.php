<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentService;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus;
use App\Platform\IdentityAccess\Mfa\MfaRecoveryService;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * Self-service MFA enrolment/status/recovery-code page for the CURRENTLY
 * AUTHENTICATED `/admin` user — reads `Auth::user()` directly, never a
 * route parameter, same "this only ever concerns this session's own
 * logged-in user" rule `MfaChallenge`'s doc block establishes. Route name
 * `filament.admin.pages.mfa-settings` — same CONFIRMED slug -> route-name
 * pattern Task 4 traced against the real installed Filament 5.7.3 source
 * (see `MfaChallenge`'s doc block for the citation); not re-traced here.
 *
 * `mount()` auto-starts a PENDING enrolment when none exists at all — see
 * `MfaEnrolmentService::startEnrolment()`'s own "always supersedes, safe to
 * call repeatedly" doc block. A visit that never confirms leaves a PENDING
 * row the next visit's `startEnrolment()` silently supersedes; no cleanup
 * needed. This means the QR/otpauth URI is ready to scan the instant the
 * page loads for a not-yet-enrolled actor, rather than requiring a
 * separate "start enrolling" click.
 *
 * The "Disable" affordance in this page's Blade view is a plain link to
 * `admin.mfa.disable` (a future task's route, gated there by
 * `RequireRecentAuthentication`), never a method this page implements —
 * disabling is a distinct, step-up-authenticated action this page does not
 * itself perform.
 */
final class MfaSettings extends Page
{
    protected static ?string $slug = 'mfa-settings';

    protected string $view = 'filament.admin.pages.mfa-settings';

    public ?MfaEnrolment $enrolment = null;

    public string $enrolmentStatus = '';

    public string $confirmationCode = '';

    /**
     * The `otpauth://` URI for the current PENDING enrolment's secret, or
     * `null` once the enrolment is CONFIRMED (or revoked) — see
     * `refreshOtpauthUri()`. No QR image is rendered: `composer.json` has
     * no QR-rendering package among its dependencies (checked before
     * writing this page), and adding one for a single URI-as-text display
     * would be a new dependency this plan does not otherwise need. The
     * Blade view instead renders the raw URI with a copy-to-clipboard
     * affordance — a deliberate scope-minimizing choice, not an oversight.
     */
    public ?string $otpauthUri = null;

    /** @var list<string> */
    public array $displayedRecoveryCodes = [];

    public static function getNavigationLabel(): string
    {
        return 'Autentikasi Dua Faktor';
    }

    public function getTitle(): string
    {
        return 'Autentikasi Dua Faktor';
    }

    public function mount(): void
    {
        $user = Auth::user();

        $this->enrolment = MfaEnrolment::query()
            ->where('user_id', $user->id)
            ->where('status', '!=', MfaEnrolmentStatus::REVOKED)
            ->latest('id')
            ->first();

        if ($this->enrolment === null) {
            $this->enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
        }

        $this->enrolmentStatus = $this->enrolment->status;
        $this->refreshOtpauthUri();
    }

    public function confirmEnrolment(): void
    {
        $actorContext = app(ActorContext::class);

        $result = app(MfaEnrolmentService::class)->confirm(
            enrolment: $this->enrolment,
            submittedCode: $this->confirmationCode,
            actorRef: $actorContext->identityReference,
            actorRole: 'authenticated_actor',
            source: AuditSource::Panel,
        );

        if (! $result->valid) {
            $this->addError('confirmationCode', 'Kode tidak valid. Silakan coba lagi.');

            return;
        }

        $this->enrolment = $result->enrolment;
        $this->enrolmentStatus = $this->enrolment->status;
        $this->displayedRecoveryCodes = $result->recoveryCodes;
        $this->confirmationCode = '';
        $this->refreshOtpauthUri();
    }

    /**
     * Re-queries for a CONFIRMED enrolment (`firstOrFail()`) rather than
     * trusting `$this->enrolment` — same guard shape `MfaChallenge::submit()`
     * uses, for the same reason: a Livewire component's public methods are
     * invocable over the wire regardless of what the Blade view currently
     * renders, so the view's `@if ($isConfirmed)` around the "regenerate"
     * button is not itself an access boundary.
     * `MfaRecoveryService::regenerate()` performs no such check itself
     * (unlike its sibling `redeem()`), so without this guard a still-PENDING,
     * never-proven-possession enrolment could be given a stored, usable
     * batch of recovery codes.
     */
    public function regenerateRecoveryCodes(): void
    {
        $user = Auth::user();

        $enrolment = MfaEnrolment::query()
            ->where('user_id', $user->id)
            ->where('status', MfaEnrolmentStatus::CONFIRMED)
            ->latest('id')
            ->firstOrFail();

        $actorContext = app(ActorContext::class);

        $this->displayedRecoveryCodes = app(MfaRecoveryService::class)->regenerate(
            enrolment: $enrolment,
            actorRef: $actorContext->identityReference,
            actorRole: 'authenticated_actor',
            source: AuditSource::Panel,
        );

        $this->enrolment = $enrolment;
        $this->enrolmentStatus = $enrolment->status;
    }

    /**
     * Only a PENDING enrolment's secret is unconfirmed and therefore still
     * worth displaying for scanning/entry — a CONFIRMED (or superseded)
     * enrolment's secret has no reason to be shown again.
     */
    private function refreshOtpauthUri(): void
    {
        $user = Auth::user();

        $this->otpauthUri = $this->enrolment->isPending()
            ? app(MfaEnrolmentService::class)->otpauthUriFor(
                enrolment: $this->enrolment,
                accountLabel: $user->email,
            )
            : null;
    }
}

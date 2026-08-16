<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\Audit\AuditSource;
use App\Platform\FeatureGate\GateActivationRecorder;
use App\Platform\FeatureGate\Models\FeatureGate;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use App\Platform\IdentityAccess\Roles\ActorRole;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * Admin operational surface for the `feature_gates` registry — the P2
 * `admin-data-management` plan's Task 2 page. Lists every gate row with its
 * current state and evidence, and offers per-row open/close transitions
 * through `GateActivationRecorder` — the ONLY write path to
 * `feature_gates.state` (that class's own doc block).
 *
 * ---------------------------------------------------------------------------
 * Authorization: view and transition are different gates
 * ---------------------------------------------------------------------------
 * VIEW — `canAccess()` delegates to the shared
 * `MasterDataAdminAuthorizerContract` (four back-office roles: admin,
 * restricted_admin, operator, finance), the same policy every master-data
 * resource and Task 1's `SiteSettingsResource` consult, so the list can
 * never be visible to a role that resource family denies. `canAccess()` is
 * answered twice from the same authorizer (static predicate for the panel's
 * mount/navigation guard; the live call in `transitionGate` re-checks the
 * fresh `ActorContext` at the moment of the mutation, so a role revoked
 * mid-session cannot keep mutating gates).
 *
 * TRANSITIONS — admin-only. The four back-office roles may VIEW the
 * registry; only `ActorRole::ADMIN` may change a gate's state. A
 * restricted_admin/operator/finance actor calling `transitionGate()` gets a
 * danger notification and no write. This is the plan's explicit boundary:
 * "MasterData authorizer — 4 back-office roles VIEW; transitions admin-only
 * (in_array ActorRole::ADMIN in $actor->roles)".
 *
 * ---------------------------------------------------------------------------
 * The mutation path: recent re-authentication -> evidence -> recorder
 * ---------------------------------------------------------------------------
 * `transitionGate()` is the Filament-side version of the Change flow
 * `GateActivationRecorder`'s doc block names ("Privileged action -> recent
 * re-authentication -> evidence reference required -> audited -> outbox
 * event emitted"): it asserts the actor's `lastAuthenticatedAt` is fresh via
 * `ReauthenticationGuard` FIRST, and when stale redirects to the
 * `filament.admin.pages.mfa-challenge` challenge page threading the pending
 * reason (`RequireRecentAuthentication::REASON_SESSION_KEY`) and the
 * intended return URL — the exact session-key/route pair the
 * `RequireRecentAuthentication` middleware uses, so a satisfied challenge
 * routes back here via `MfaChallenge`'s `redirectIntended()`. Only a fresh
 * actor reaches `GateActivationRecorder::record()`, which enforces
 * non-blank evidence and reason and commits the activation row + audit
 * record + outbox event atomically. This page never enforces evidence
 * itself — the recorder is the evidence gate, and this page surfaces its
 * `MissingActivationEvidenceException` (or any other failure) as a danger
 * notification with no state change.
 *
 * `auditRoleFor()` is the same deterministic role walk every other admin
 * resource uses (most-privileged first), feeding `record()`'s mandatory
 * `actor_role` so the audit row names a real role, never a placeholder.
 */
final class FeatureGateAdmin extends Page
{
    protected static ?string $slug = 'feature-gates';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockOpen;

    protected string $view = 'filament.admin.pages.feature-gate-admin';

    /**
     * Evidence reference and reason for the transition currently being
     * confirmed in the modal — Livewire-mutable, bound to the modal's
     * textareas with `wire:model`, and cleared after a successful
     * transition. Kept on the page (not per-row state) because the modal
     * edits exactly one transition at a time, driven by `activeGateId`.
     */
    public string $evidence = '';

    public string $reason = '';

    /**
     * The gate whose transition the modal is confirming, or `null` when no
     * transition is in progress. `beginTransition()` sets it; a successful
     * `transitionGate()` and `cancelTransition()` clear it.
     */
    public ?string $activeGateId = null;

    /**
     * The target state of the active transition — `'open'` or `'closed'`,
     * both values `GateActivationRecorder` recognises.
     */
    public string $activeToState = 'open';

    public static function canAccess(): bool
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }

        return true;
    }

    public static function getNavigationLabel(): string
    {
        return 'Gerbang Fitur';
    }

    public function getTitle(): string
    {
        return 'Gerbang Fitur';
    }

    /**
     * Every gate row with its activation history, in registry order — the
     * page's single data source (gate_id, capability, type, owner, state,
     * evidence_reference, effective_at are all plain columns of
     * `feature_gates`; the badge and the evidence columns render from them).
     *
     * @return Collection<int, FeatureGate>
     */
    public function gates(): Collection
    {
        return FeatureGate::query()->with('activations')->orderBy('gate_id')->get();
    }

    /**
     * Opens the transition modal for one gate, resetting the evidence and
     * reason fields so a previous transition's text never leaks into the
     * next one. Purely view state — no authorization or mutation here.
     */
    public function beginTransition(string $gateId, string $toState): void
    {
        $this->activeGateId = $gateId;
        $this->activeToState = $toState;
        $this->evidence = '';
        $this->reason = '';
    }

    /**
     * Closes the transition modal without mutating anything.
     */
    public function cancelTransition(): void
    {
        $this->activeGateId = null;
        $this->evidence = '';
        $this->reason = '';
    }

    /**
     * The one Livewire action that changes a gate's state. Order matters and
     * is deliberate:
     *
     * 1. ADMIN-ONLY check — the first gate. A non-admin back-office role
     *    that can view the page gets a danger notification and never touches
     *    the guard, the recorder, or the session.
     * 2. RECENT RE-AUTHENTICATION — `ReauthenticationGuard::assertFresh()`
     *    throws when the actor's `lastAuthenticatedAt` is missing or older
     *    than `reauthentication.freshness_seconds`. On that path the reason
     *    is threaded into the session and the actor is redirected to the
     *    challenge page, with `url.intended` pointing back here so a
     *    satisfied challenge returns to this same page.
     * 3. EVIDENCE-GATED RECORD — `GateActivationRecorder::record()` refuses
     *    blank evidence/reason (`MissingActivationEvidenceException`) and
     *    invalid target states (`InvalidArgumentException`); any failure
     *    surfaces as a danger notification with no state change, because the
     *    recorder's own transaction rolls back everything. A success clears
     *    the modal state.
     */
    public function transitionGate(string $gateId, string $toState, string $evidenceReference, string $reason): void
    {
        $actor = app(ActorContext::class);

        if (! in_array(ActorRole::ADMIN, $actor->roles, true)) {
            Notification::make()->danger()->title('Hanya admin yang dapat mengubah gerbang fitur.')->send();

            return;
        }

        try {
            app(ReauthenticationGuard::class)->assertFresh($actor);
        } catch (ReauthenticationRequiredException) {
            session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, 'feature_gate');
            session()->put('url.intended', route('filament.admin.pages.feature-gates'));
            Notification::make()->warning()->title('Perlu verifikasi ulang')->send();
            redirect()->route('filament.admin.pages.mfa-challenge');

            return;
        }

        try {
            app(GateActivationRecorder::class)->record(
                gateId: $gateId,
                actorReference: $actor->identityReference ?? 0,
                toState: $toState,
                evidenceReference: $evidenceReference,
                reason: $reason,
                actorRole: FeatureGateAdmin::auditRoleFor($actor),
                auditSource: AuditSource::Panel,
            );
            Notification::make()->success()->title('Gerbang diperbarui.')->send();
            $this->activeGateId = null;
            $this->evidence = '';
            $this->reason = '';
        } catch (\Throwable $exception) {
            Notification::make()->danger()->title('Gagal memperbarui gerbang')->body($exception->getMessage())->send();
        }
    }

    /**
     * The same deterministic role walk every other admin resource uses
     * (`SiteSettingsResource::auditRoleFor()`, master-data resources): the
     * most-privileged held role names the audit row. Falls back to the
     * honest generic labels for the (unreachable through this page) case of
     * an actor with no back-office role.
     */
    public static function auditRoleFor(ActorContext $actor): string
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            if ($actor->hasRole($role)) {
                return $role;
            }
        }

        return $actor->isAuthenticated() ? 'authenticated_actor' : 'guest';
    }
}

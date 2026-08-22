# MFA Removal and Password Re-authentication Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Fix a live production bug where the money-route re-authentication challenge page crashes for every admin (none is MFA-enrolled), by replacing it with a real password-only re-authentication page, then remove the MFA feature entirely per an explicit user decision, and correct every governance document that still claims MFA is mandatory or that its absence is a reversible, self-service-pending state.

**Architecture:** Add one new Filament page (`PasswordReauthentication`) that satisfies the exact same `RequireRecentAuthentication` / `ReauthenticationService` contract `MfaChallenge` currently satisfies, repoint every one of the ~13 real call sites that hardcode the old challenge page's route name, then delete the entire `app/Platform/IdentityAccess/Mfa/**` module, its 3 tables, and its 16 tests — but first relocate the one class inside that module (`MfaRateLimiter`) that `ReauthenticationService` genuinely still needs at runtime, since it is not MFA-specific despite its name and namespace.

**Tech Stack:** Laravel 13, Filament 5.7.3 (Livewire-based admin panel pages), PostgreSQL 18 (production/CI), PHPUnit Feature/Unit tests with `RefreshDatabase`.

**Spec:** No formal spec document exists for this reversal — it is a direct, explicit user decision made in conversation on 22 Aug 2026, following discovery of a live bug during investigation of Phase 2 Workstream 3 (Security/compliance) of the release-readiness roadmap at `/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md`. This plan's Context section below is the authoritative record of what was decided and why; treat it as the spec.

## Context — verified ground truth, do not re-derive

**The bug this plan fixes (independent of the MFA-removal decision):** `RequireRecentAuthentication` (`app/Http/Middleware/RequireRecentAuthentication.php`) gates several money-adjacent admin routes with a 900-second (15-minute) freshness window (`config/reauthentication.php`), well inside the 120-minute session lifetime (`config/session.php`), so staleness triggers routinely in ordinary use. When it triggers, every real call site redirects to `filament.admin.pages.mfa-challenge` (`App\Filament\Admin\Pages\MfaChallenge`). That page's `submit()` method does `MfaEnrolment::query()->where('status', MfaEnrolmentStatus::CONFIRMED)->firstOrFail()` — and per `docs/adr/0035-beta-launch-accepted-risks.md` item 10 (the user's explicit 19 Aug 2026 decision), **zero admin accounts are MFA-enrolled**. Any admin idle more than 15 minutes who then attempts a gated action is redirected to a page that throws a `ModelNotFoundException` (HTTP 404) the instant they try to prove anything, because there is no confirmed enrolment to query. This is broken today, in the code merged at commit `c43b320`.

**The user's decision (22 Aug 2026, in conversation, not a written spec):** remove the MFA feature entirely — not just leave it unenforced. This is a genuine reversal of `docs/adr/0024-use-session-auth-and-mfa.md`'s "mandatory TOTP MFA for privileged roles" decision and of `AGENTS.md` line 45's identical binding requirement. The user explicitly confirmed both: (a) removing the code, and (b) updating `AGENTS.md` and `ADR-0024` so the governance documents stop contradicting what the code actually does.

**Real blast radius (found by reading the actual code, not assumed from the plan's own recommended scope):** the original roadmap's D4 lane text ("money-route hardening... enrol MFA on every beta admin account") undersold how many places hardcode the MFA challenge page as a redirect target. A repo-wide search for the literal string `'filament.admin.pages.mfa-challenge'` outside the Mfa module and its own tests found it in **13 real, functional locations**, not the 2-3 the roadmap's own wording implied:

- 3 `routes/web.php` middleware attachments: `/admin/finance/exports` (line ~571, reason `bulk_financial_export`), `/admin/payments/manual-verifications/{paymentVerification}/verify` (line ~642, reason `payment_manual_verification`), `/admin/payments/reversals/{reversalType}` (line ~682, reason `payment_reversal`).
- 1 route to delete outright: `/admin/mfa/disable` (line ~535, `DisableMfaController`) — nothing is left to disable once MFA is removed.
- 7 Filament action classes with an inline `catch (ReauthenticationRequiredException) { ... redirect()->route('filament.admin.pages.mfa-challenge'); }` pattern (via `ReauthenticationGuard::assertFresh()`, a small, fully MFA-agnostic freshness class needing no changes itself): `app/Filament/Admin/Pages/FeatureGateAdmin.php:212` (feature-gate transitions — **this is the page that flips `G-PAY-01` and every other production feature gate**), `app/Filament/Admin/Resources/BookingOrders/Actions/TransitionOrderAction.php:200`, `app/Filament/Admin/Resources/GravePlots/Tables/GravePlotsTable.php:284`, `app/Filament/Admin/Resources/MarketplaceOrders/Actions/MarkMarketplaceOrderPaidAction.php:89`, `app/Filament/Admin/Resources/PreNeedCases/Actions/PreNeedCaseActions.php:668`, `app/Filament/Admin/Resources/RenewalOrders/Actions/RecordExternalRenewalPaymentAction.php:78`.
- 1 controller with its own inline catch, in addition to its route-level middleware: `app/Http/Controllers/Admin/FinanceExportController.php:86` (catches `BulkFinancialExportReauthenticationRequiredException`, a belt-and-suspenders check on top of the route's own `RequireRecentAuthentication` attachment).
- 6 test files asserting `assertRedirect(route('filament.admin.pages.mfa-challenge'))`: `tests/Feature/Filament/FeatureGateAdminTest.php:215`, `tests/Feature/FinancialLedger/BulkFinancialExportTest.php:458,517`, `tests/Feature/IdentityAccess/AdminPanelHttpAccessTest.php:200`, `tests/Feature/Payment/RecordPaymentReversalRouteTest.php:191,659`, `tests/Feature/Payment/VerifyManualPaymentRouteTest.php:226,678`.

**`RequireRecentAuthentication` and `ReauthenticationGuard` need zero changes.** Both are already fully generic — `RequireRecentAuthentication::handle()` takes `$challengeRouteName` as a caller-supplied parameter (never hardcodes MFA), and `ReauthenticationGuard::assertFresh()` only reads `ActorContext::$lastAuthenticatedAt` against a config freshness window. `ReauthenticationService.php`'s own doc block (lines ~44-51) explicitly anticipated this exact fallback: *"whether an actor proves freshness via a TOTP/recovery-code challenge or via a password re-entry form depends on `ActorContext::$mfaState`... anything else falls back to password re-confirmation"* — this plan builds precisely the fallback that doc block already named as the intended design.

**`MfaRateLimiter` is not MFA-specific and must be relocated, not deleted.** `app/Platform/IdentityAccess/Mfa/MfaRateLimiter.php` is a small, generic, static, actor+IP-keyed attempt limiter (5 attempts / 60 seconds) with zero MFA-specific logic. `ReauthenticationService.php` (staying, not being deleted) calls `MfaRateLimiter::tooManyAttempts()` / `::hit()` / `::clear()` directly in its own `challenge()` and `satisfy()` methods (lines 113, 117, 178). Deleting the Mfa module before relocating this class would break `ReauthenticationService` at the class-loading level. `tests/Feature/IdentityAccess/Reauthentication/ReauthenticationServiceTest.php` also references it and needs its import updated (not deleted — that test covers `ReauthenticationService`, which stays).

**`ActorContext::$mfaState` is a real constructor property, not a comment.** `app/Platform/IdentityAccess/ActorContext.php` has a `public readonly string $mfaState` property and 4 `MFA_STATE_*` constants, populated for real by `App\Platform\IdentityAccess\Adapters\LocalUsersTableIdentityAccessAdapter::resolveMfaState()` (lines 83, 112, 116-117), which queries the (being-deleted) `MfaEnrolment` model. Four test files outside the Mfa test directory construct `ActorContext` with a named `mfaState:` argument and must be updated once that constructor parameter is removed: `tests/Feature/IdentityAccess/LocalUsersTableIdentityAccessAdapterTest.php`, `tests/Unit/Domain/OrderWorkflow/OrderTransitionAuthorizerTest.php`, `tests/Unit/Platform/IdentityAccess/ActorContextTest.php`, `tests/Unit/Platform/ReauthenticationGuardTest.php`.

**Doc-comment-only references (not functional dependencies) exist in ~15 more files** across `app/Domain/**`, `app/Filament/**`, `app/Platform/Audit/**`, `app/Platform/FinancialLedger/**`, `app/Platform/DocumentVault/**`, and `app/Platform/IdentityAccess/**` — these cite `MfaEnrolmentStatus` (or similar) purely as a naming-pattern precedent example inside a doc block, never a real `use` import or instantiation. Confirmed by reading several: e.g. `app/Domain/CemeteryDirectory/CemeteryType.php:24`, `app/Domain/Faq/FaqArticlePublishState.php:13`. These need a mechanical sweep so no comment cites a class that no longer exists, but this is not behavioral work.

**D4's rate-limiting half is already closed** — `throttle:mfa-disable` / `throttle:financial-export` / `throttle:payment-manual-verification` / `throttle:payment-reversal` all already exist in `routes/web.php` and `app/Providers/AppServiceProvider.php`. This plan's Task 3 removes the `throttle:mfa-disable` limiter along with the route it guards (nothing left to throttle), and leaves the other three untouched. No separate rate-limiting task is needed.

**Not in scope for this plan:** D6 (credential hygiene / rotation) is separate, unscoped work. Full-scale production infrastructure decisions are Phase 3 scope. `docs/domain/traceability-matrix.md:392`'s citation of `FeatureGateAdminTest::test_a_stale_actor_is_redirected_to_the_challenge_instead_of_transitioning` needs no wording change — it already describes the *behavior* ("re-auth lapse → challenge, fail closed"), not the specific mechanism; the test itself is corrected in Task 3.

## Global Constraints

- PHP `declare(strict_types=1);` on every new file, matching every existing file read during this plan's research.
- Follow this codebase's `final class` convention for every new class.
- New Filament pages register via `AdminPanelProvider.php`'s explicit `->pages([...])` array — this codebase deliberately does not use `->discoverPages()` (see that provider's own doc block).
- Migrations follow expand/contract (`AGENTS.md` §Database): do not edit the 3 original `create_mfa_*_table` migrations. Add one new migration whose `up()` drops them.
- Never place restricted data (passwords, tokens) in logs, audit metadata, or error trackers (`AGENTS.md` §Observability) — the new page's password field is never logged or included in any `Audit::record()`/`Audit::wrap()` metadata payload, matching how `MfaChallenge::submit()` never includes the submitted TOTP code.
- Every write to `reauthentication_events` / `actor_sessions` goes through the existing `ReauthenticationService` / `RecordActorSessionAuthentication` action — never a raw `DB::table()` insert, matching the whole codebase's established pattern.
- Test commands in this repo run via `php artisan test <path>` against a real disposable Postgres/Redis pair (this host's established Docker containers, per this session's own prior tasks), never assumed to pass from static reading alone.
- Run `bash ci/verify-docs.sh` after every task that touches `docs/` or `AGENTS.md` — it scans for hardcoded design values and (per this repo's convention) structural doc issues.

---

### Task 1: Build the password re-authentication page

**Files:**
- Create: `app/Filament/Admin/Pages/PasswordReauthentication.php`
- Create: `resources/views/filament/admin/pages/password-reauthentication.blade.php`
- Test: `tests/Feature/IdentityAccess/Reauthentication/PasswordReauthenticationPageTest.php`
- Test: `tests/Feature/IdentityAccess/Reauthentication/PasswordReauthenticationSatisfiesRecentAuthenticationTest.php`

**Interfaces:**
- Consumes: `App\Http\Middleware\RequireRecentAuthentication::REASON_SESSION_KEY` (existing, unchanged), `App\Platform\IdentityAccess\Actions\RecordActorSessionAuthentication` (existing, `__invoke(int|string $userId, string $guard, Request $request): ActorSession`, unchanged), `App\Platform\IdentityAccess\Reauthentication\ReauthenticationService::satisfy(int|string|null $actorRef, string $actorRole, string $reason, AuditSource $source, string $ip = '0.0.0.0'): ReauthenticationEvent` (existing, unchanged), `App\Platform\IdentityAccess\ActorContext` (existing, unchanged).
- Produces: `App\Filament\Admin\Pages\PasswordReauthentication::ROUTE_NAME` (`string`, value `'filament.admin.pages.password-reauthentication'`) and `::REAUTHENTICATION_REASON` (`string`, value `'password_reauthentication'`) — both consumed by Task 3's repoint work. Route name derivation follows the exact same confirmed chain `EnforceMfaChallenge`'s doc block traces for `MfaChallenge`: panel id `admin` + `pages.` + slug `password-reauthentication` -> `filament.admin.pages.password-reauthentication`.

- [ ] **Step 1: Write the failing page test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Reauthentication;

use App\Filament\Admin\Pages\PasswordReauthentication;
use App\Models\User;
use App\Platform\IdentityAccess\Models\ActorSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class PasswordReauthenticationPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_correct_password_redirects_and_refreshes_the_actor_session(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);
        $this->actingAs($user);

        Livewire::test(PasswordReauthentication::class)
            ->set('password', 'correct-horse-battery-staple')
            ->call('submit')
            ->assertRedirect();

        $this->assertSame(
            1,
            ActorSession::query()->where('user_id', $user->id)->count(),
            'A successful password check must refresh the actor_sessions freshness row.',
        );
    }

    public function test_the_wrong_password_shows_an_error_and_writes_no_session_row(): void
    {
        $user = User::factory()->create(['password' => bcrypt('correct-horse-battery-staple')]);
        $this->actingAs($user);

        Livewire::test(PasswordReauthentication::class)
            ->set('password', 'wrong-password')
            ->call('submit')
            ->assertHasErrors(['password']);

        $this->assertSame(
            0,
            ActorSession::query()->where('user_id', $user->id)->count(),
            'A failed password check must never write an actor_sessions row.',
        );
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test tests/Feature/IdentityAccess/Reauthentication/PasswordReauthenticationPageTest.php`
Expected: FAIL — class `App\Filament\Admin\Pages\PasswordReauthentication` does not exist.

- [ ] **Step 3: Create the Filament page**

```php
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
```

- [ ] **Step 4: Create the Blade view**

```blade
{{--
    resources/views/filament/admin/pages/password-reauthentication.blade.php

    View for App\Filament\Admin\Pages\PasswordReauthentication — replaces
    mfa-challenge.blade.php. Same Filament Blade component set and the same
    tokens.css-traced colour utilities that view already established as
    correct for this panel (see that file's own header comment for why
    <x-mk.*> primitives do not apply here).
--}}
<x-filament-panels::page>
    <form wire:submit="submit" class="grid gap-y-6">
        <p class="text-sm text-neutral-600">
            Masukkan kata sandi Anda untuk melanjutkan tindakan ini.
        </p>

        <div class="grid gap-y-1.5">
            <label for="password-reauthentication-password" class="text-sm font-medium text-neutral-800">
                Kata sandi
            </label>

            <x-filament::input.wrapper :valid="! $errors->has('password')">
                <x-filament::input
                    id="password-reauthentication-password"
                    type="password"
                    wire:model="password"
                    autocomplete="current-password"
                    autofocus
                    required
                />
            </x-filament::input.wrapper>

            @error('password')
                <p class="text-sm text-danger-700">{{ $message }}</p>
            @enderror
        </div>

        <x-filament::button type="submit">
            Verifikasi
        </x-filament::button>
    </form>
</x-filament-panels::page>
```

- [ ] **Step 5: Register the page in AdminPanelProvider**

Modify `app/Providers/Filament/AdminPanelProvider.php`: add `use App\Filament\Admin\Pages\PasswordReauthentication;` alongside the other `Pages\*` imports (do not remove the `MfaChallenge`/`MfaSettings` imports yet — Task 4 removes those once every redirect target is repointed), and add `PasswordReauthentication::class,` to the `->pages([...])` array (any position — the array order is not load-bearing, matching the existing entries' own unordered layout).

- [ ] **Step 6: Run the page test to verify it passes**

Run: `php artisan test tests/Feature/IdentityAccess/Reauthentication/PasswordReauthenticationPageTest.php`
Expected: PASS — 2 tests, both assertions green.

- [ ] **Step 7: Write the failing recent-authentication integration test**

This mirrors `tests/Feature/IdentityAccess/Reauthentication/MfaChallengeSatisfiesRecentAuthenticationTest.php`'s exact structure (ad-hoc fixture route, `crossRequestBoundary()` via `$this->app->forgetScopedInstances()`), swapping TOTP-code submission for password submission:

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Reauthentication;

use App\Filament\Admin\Pages\PasswordReauthentication;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Models\User;
use App\Platform\IdentityAccess\Models\ActorSession;
use App\Platform\IdentityAccess\Reauthentication\Models\ReauthenticationEvent;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use Tests\TestCase;

final class PasswordReauthenticationSatisfiesRecentAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private const string SENSITIVE_REASON = 'bank_account_change';

    private const string PASSWORD = 'correct-horse-battery-staple';

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware('web')
            ->get('/__test/sensitive-action', function () {
                return response()->json(['ok' => true]);
            })
            ->middleware(RequireRecentAuthentication::class.':'.self::SENSITIVE_REASON.',test.reauth.challenge');

        Route::middleware('web')
            ->get('/__test/reauth-challenge', function () {
                return response('challenge-page', 200);
            })
            ->name('test.reauth.challenge');

        app('router')->getRoutes()->refreshNameLookups();
    }

    private function staleSessionFor(User $user): ActorSession
    {
        return ActorSession::query()->create([
            'user_id' => $user->id,
            'session_id' => 'stale-session-'.$user->id,
            'guard' => 'web',
            'last_authenticated_at' => CarbonImmutable::now()->subHour(),
        ]);
    }

    private function crossRequestBoundary(): void
    {
        $this->app->forgetScopedInstances();
    }

    public function test_a_stale_actor_passes_the_same_gate_after_a_correct_password(): void
    {
        $user = User::factory()->create(['password' => bcrypt(self::PASSWORD)]);
        $this->staleSessionFor($user);
        $this->actingAs($user);

        $this->get('/__test/sensitive-action')->assertRedirect(route('test.reauth.challenge'));

        $this->crossRequestBoundary();

        Livewire::actingAs($user)
            ->test(PasswordReauthentication::class)
            ->set('password', self::PASSWORD)
            ->call('submit')
            ->assertRedirect();

        $this->crossRequestBoundary();

        $this->get('/__test/sensitive-action')
            ->assertOk()
            ->assertJson(['ok' => true]);
    }

    public function test_the_satisfied_event_carries_the_sensitive_action_that_raised_the_challenge(): void
    {
        $user = User::factory()->create(['password' => bcrypt(self::PASSWORD)]);
        $this->staleSessionFor($user);
        $this->actingAs($user);

        $this->get('/__test/sensitive-action')->assertRedirect(route('test.reauth.challenge'));

        $this->crossRequestBoundary();

        Livewire::actingAs($user)
            ->test(PasswordReauthentication::class)
            ->set('password', self::PASSWORD)
            ->call('submit')
            ->assertRedirect();

        $satisfied = ReauthenticationEvent::query()
            ->where('outcome', ReauthenticationOutcome::SATISFIED)
            ->latest('id')
            ->first();

        $this->assertNotNull($satisfied);
        $this->assertSame(self::SENSITIVE_REASON, $satisfied->reason);
    }

    public function test_a_wrong_password_writes_no_satisfied_event_and_leaves_the_actor_stale(): void
    {
        $user = User::factory()->create(['password' => bcrypt(self::PASSWORD)]);
        $staleSession = $this->staleSessionFor($user);
        $staleAt = $staleSession->last_authenticated_at;
        $this->actingAs($user);

        Livewire::actingAs($user)
            ->test(PasswordReauthentication::class)
            ->set('password', 'not-the-password')
            ->call('submit')
            ->assertHasErrors(['password']);

        $this->assertSame(
            0,
            ReauthenticationEvent::query()->where('outcome', ReauthenticationOutcome::SATISFIED)->count(),
        );
        $this->assertTrue(
            $staleSession->refresh()->last_authenticated_at->equalTo($staleAt),
            'A failed challenge must leave last_authenticated_at untouched.',
        );

        $this->crossRequestBoundary();

        $this->get('/__test/sensitive-action')->assertRedirect(route('test.reauth.challenge'));
    }
}
```

- [ ] **Step 8: Run the test to verify it fails, then passes**

Run: `php artisan test tests/Feature/IdentityAccess/Reauthentication/PasswordReauthenticationSatisfiesRecentAuthenticationTest.php`
Expected first run: PASS already, since Steps 1-6 already built the consuming class — if any assertion fails, fix `PasswordReauthentication::submit()` before proceeding, do not adjust the test to match broken behavior.

- [ ] **Step 9: Commit**

```bash
git add app/Filament/Admin/Pages/PasswordReauthentication.php \
  resources/views/filament/admin/pages/password-reauthentication.blade.php \
  app/Providers/Filament/AdminPanelProvider.php \
  tests/Feature/IdentityAccess/Reauthentication/PasswordReauthenticationPageTest.php \
  tests/Feature/IdentityAccess/Reauthentication/PasswordReauthenticationSatisfiesRecentAuthenticationTest.php
git commit -m "feat(identity-access): add password re-authentication page

Replaces the MFA challenge page as the step-up target for
RequireRecentAuthentication-gated routes and ReauthenticationGuard
catches. Fixes a live bug where the old target crashed for every
admin, since none is MFA-enrolled."
```

---

### Task 2: Relocate `MfaRateLimiter` out of the Mfa module

**Files:**
- Create: `app/Platform/IdentityAccess/Reauthentication/ReauthenticationRateLimiter.php`
- Modify: `app/Platform/IdentityAccess/Reauthentication/ReauthenticationService.php`
- Modify: `tests/Feature/IdentityAccess/Reauthentication/ReauthenticationServiceTest.php`
- Delete: `app/Platform/IdentityAccess/Mfa/MfaRateLimiter.php` (its logic moves wholesale into the new file above — same class body, new name/namespace/location)

**Interfaces:**
- Consumes: nothing new.
- Produces: `App\Platform\IdentityAccess\Reauthentication\ReauthenticationRateLimiter` with the exact same static API `MfaRateLimiter` had (`tooManyAttempts`, `hit`, `clear`, `availableInSeconds`) — Task 4's Mfa-module deletion can then remove `MfaRateLimiter.php` with zero remaining internal consumers (the Mfa module's own `MfaChallengeService`/`MfaEnrolmentService`/`MfaRecoveryService` calls to it are deleted along with those files in Task 4).

- [ ] **Step 1: Create the relocated class**

```php
<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Reauthentication;

use Illuminate\Support\Facades\RateLimiter;

/**
 * Relocated from `App\Platform\IdentityAccess\Mfa\MfaRateLimiter` when the
 * MFA module was removed (see `docs/adr/0024-use-session-auth-and-mfa.md`'s
 * superseding note) — this class was never MFA-specific despite its old
 * name and namespace; `ReauthenticationService::challenge()`/`::satisfy()`
 * were already its real, sole surviving consumers. Behaviour is unchanged
 * from the original: a generic, static, actor+IP-keyed attempt limiter
 * using Laravel's built-in `RateLimiter` facade.
 *
 * ---------------------------------------------------------------------------
 * Threshold: 5 attempts per 60 seconds (unchanged from the original class)
 * ---------------------------------------------------------------------------
 * Conservative and documented rather than an unbounded loop. Generous
 * enough that a legitimate actor mistyping a password twice is never
 * blocked, while bounding brute-force attempts to a trivial rate.
 */
final class ReauthenticationRateLimiter
{
    private const int MAX_ATTEMPTS = 5;

    private const int DECAY_SECONDS = 60;

    public static function tooManyAttempts(string $context, int|string $actorRef, string $ip): bool
    {
        return RateLimiter::tooManyAttempts(self::key($context, $actorRef, $ip), self::MAX_ATTEMPTS);
    }

    /**
     * Record one attempt. Returns the number of attempts recorded so far in
     * the current decay window.
     */
    public static function hit(string $context, int|string $actorRef, string $ip): int
    {
        return RateLimiter::hit(self::key($context, $actorRef, $ip), self::DECAY_SECONDS);
    }

    /**
     * Reset the counter — called on a SUCCESSFUL verification so a
     * legitimate actor who mistyped once or twice is not left artificially
     * close to the threshold.
     */
    public static function clear(string $context, int|string $actorRef, string $ip): void
    {
        RateLimiter::clear(self::key($context, $actorRef, $ip));
    }

    public static function availableInSeconds(string $context, int|string $actorRef, string $ip): int
    {
        return RateLimiter::availableIn(self::key($context, $actorRef, $ip));
    }

    /**
     * @param  string  $context  A short discriminator so different
     *                           verification mechanisms for the same
     *                           actor+IP do not share one bucket.
     */
    private static function key(string $context, int|string $actorRef, string $ip): string
    {
        return "{$context}:{$actorRef}:{$ip}";
    }
}
```

- [ ] **Step 2: Update `ReauthenticationService.php`'s import and 3 call sites**

Modify `app/Platform/IdentityAccess/Reauthentication/ReauthenticationService.php`:
- Change `use App\Platform\IdentityAccess\Mfa\MfaRateLimiter;` to `use App\Platform\IdentityAccess\Reauthentication\ReauthenticationRateLimiter;` (or drop the `use` entirely since the class is now in the same namespace — verify which the codebase prefers by checking whether other same-namespace references in this file already omit their own namespace's `use` statements; if uncertain, keep the explicit `use` for clarity, matching this file's existing dense-doc-comment, explicit style).
- Replace all 3 real call sites (`MfaRateLimiter::tooManyAttempts(...)` at line 113, `MfaRateLimiter::hit(...)` at line 117, `MfaRateLimiter::clear(...)` at line 178) with `ReauthenticationRateLimiter::` in place of `MfaRateLimiter::` — same arguments, unchanged.
- Update the doc-comment mentions of `MfaRateLimiter` (lines ~62, ~65, ~150) to say `ReauthenticationRateLimiter` instead, so the comments describe the real class.

- [ ] **Step 3: Update `ReauthenticationServiceTest.php`'s reference**

Read `tests/Feature/IdentityAccess/Reauthentication/ReauthenticationServiceTest.php` in full, find its `use App\Platform\IdentityAccess\Mfa\MfaRateLimiter;` import and any `MfaRateLimiter::` call (e.g. asserting rate-limit behavior or clearing state between tests), and replace both with `App\Platform\IdentityAccess\Reauthentication\ReauthenticationRateLimiter` / `ReauthenticationRateLimiter::` — same arguments, unchanged.

- [ ] **Step 4: Delete the old file**

```bash
rm app/Platform/IdentityAccess/Mfa/MfaRateLimiter.php
```

- [ ] **Step 5: Run the affected tests**

Run: `php artisan test tests/Feature/IdentityAccess/Reauthentication/ReauthenticationServiceTest.php`
Expected: PASS — unchanged behavior, only the class name/location changed.

Run: `php artisan test tests/Feature/IdentityAccess/Mfa/MfaChallengeServiceTest.php`
Expected: this test still references the OLD `MfaRateLimiter` import inside the Mfa module — it is deleted in Task 4, not fixed here. If this task's own verification run fails on this specific file, that failure is expected and resolves in Task 4; do not fix it in this task.

- [ ] **Step 6: Commit**

```bash
git add app/Platform/IdentityAccess/Reauthentication/ReauthenticationRateLimiter.php \
  app/Platform/IdentityAccess/Reauthentication/ReauthenticationService.php \
  tests/Feature/IdentityAccess/Reauthentication/ReauthenticationServiceTest.php
git rm app/Platform/IdentityAccess/Mfa/MfaRateLimiter.php
git commit -m "refactor(identity-access): relocate MfaRateLimiter to ReauthenticationRateLimiter

Not MFA-specific despite its old name/namespace — ReauthenticationService
is its real, sole remaining consumer once MFA is removed. Moved ahead of
the Mfa module deletion so ReauthenticationService never has a broken
intermediate state."
```

---

### Task 3: Repoint every real redirect target, delete the disable route

**Files:**
- Modify: `routes/web.php` (delete the `/admin/mfa/disable` route block entirely; repoint 3 middleware attachments)
- Modify: `app/Filament/Admin/Pages/FeatureGateAdmin.php`
- Modify: `app/Filament/Admin/Resources/BookingOrders/Actions/TransitionOrderAction.php`
- Modify: `app/Filament/Admin/Resources/GravePlots/Tables/GravePlotsTable.php`
- Modify: `app/Filament/Admin/Resources/MarketplaceOrders/Actions/MarkMarketplaceOrderPaidAction.php`
- Modify: `app/Filament/Admin/Resources/PreNeedCases/Actions/PreNeedCaseActions.php`
- Modify: `app/Filament/Admin/Resources/RenewalOrders/Actions/RecordExternalRenewalPaymentAction.php`
- Modify: `app/Http/Controllers/Admin/FinanceExportController.php`
- Delete: `app/Http/Controllers/Admin/DisableMfaController.php`
- Modify: `tests/Feature/Filament/FeatureGateAdminTest.php`
- Modify: `tests/Feature/FinancialLedger/BulkFinancialExportTest.php`
- Modify: `tests/Feature/IdentityAccess/AdminPanelHttpAccessTest.php`
- Modify: `tests/Feature/Payment/RecordPaymentReversalRouteTest.php`
- Modify: `tests/Feature/Payment/VerifyManualPaymentRouteTest.php`
- Delete: `tests/Feature/IdentityAccess/Mfa/DisableMfaControllerTest.php`

**Interfaces:**
- Consumes: `App\Filament\Admin\Pages\PasswordReauthentication::ROUTE_NAME` (from Task 1).
- Produces: nothing new — this task's deliverable is that every real code path that used to redirect to a now-broken challenge page redirects to the working one instead.

- [ ] **Step 1: Delete the `/admin/mfa/disable` route and controller**

In `routes/web.php`, delete the entire route block (the doc comment above it and the `Route::post('/admin/mfa/disable', ...)` call through its `->name('admin.mfa.disable');` line — matching the block quoted in this plan's Context section). Delete the `use App\Http\Controllers\Admin\DisableMfaController;` import line. Delete the file:

```bash
rm app/Http/Controllers/Admin/DisableMfaController.php
rm tests/Feature/IdentityAccess/Mfa/DisableMfaControllerTest.php
```

Also delete the `throttle:mfa-disable` rate limiter registration in `app/Providers/AppServiceProvider.php` — grep that file for `'mfa-disable'` and remove its `RateLimiter::for(...)` block, since the route it throttled no longer exists.

- [ ] **Step 2: Repoint the 3 `routes/web.php` middleware attachments**

Replace each of these 3 exact strings in `routes/web.php`:
- `RequireRecentAuthentication::class.':bulk_financial_export,filament.admin.pages.mfa-challenge'` -> `RequireRecentAuthentication::class.':bulk_financial_export,filament.admin.pages.password-reauthentication'`
- `RequireRecentAuthentication::class.':payment_manual_verification,filament.admin.pages.mfa-challenge'` -> `RequireRecentAuthentication::class.':payment_manual_verification,filament.admin.pages.password-reauthentication'`
- `RequireRecentAuthentication::class.':payment_reversal,filament.admin.pages.mfa-challenge'` -> `RequireRecentAuthentication::class.':payment_reversal,filament.admin.pages.password-reauthentication'`

Remove `EnforceMfaChallenge::class,` from all 3 of these routes' `->middleware([...])` arrays (it no-ops today only because no admin is enrolled; once the whole class is deleted in Task 4 it must not still be referenced here). Do not remove `throttle:financial-export` / `throttle:payment-manual-verification` / `throttle:payment-reversal` — those stay.

- [ ] **Step 3: Repoint the 7 inline redirect call sites**

In each of these 7 files, replace the exact literal `redirect()->route('filament.admin.pages.mfa-challenge');` with `redirect()->route(\App\Filament\Admin\Pages\PasswordReauthentication::ROUTE_NAME);` (or add `use App\Filament\Admin\Pages\PasswordReauthentication;` at the top of the file and write `redirect()->route(PasswordReauthentication::ROUTE_NAME);` — match whichever import style the file's existing `use` block already follows):

- `app/Filament/Admin/Pages/FeatureGateAdmin.php` (line ~212)
- `app/Filament/Admin/Resources/BookingOrders/Actions/TransitionOrderAction.php` (line ~200)
- `app/Filament/Admin/Resources/GravePlots/Tables/GravePlotsTable.php` (line ~284)
- `app/Filament/Admin/Resources/MarketplaceOrders/Actions/MarkMarketplaceOrderPaidAction.php` (line ~89)
- `app/Filament/Admin/Resources/PreNeedCases/Actions/PreNeedCaseActions.php` (line ~668)
- `app/Filament/Admin/Resources/RenewalOrders/Actions/RecordExternalRenewalPaymentAction.php` (line ~78)
- `app/Http/Controllers/Admin/FinanceExportController.php` (line ~86)

Also update each file's doc-comment mentions of `filament.admin.pages.mfa-challenge` / `MfaChallenge` (e.g. `FeatureGateAdmin.php` lines ~57-62) to describe the real current target, so the comment matches the code.

- [ ] **Step 4: Update the 6 test files' assertions**

In each of these files, replace every `route('filament.admin.pages.mfa-challenge')` with `route('filament.admin.pages.password-reauthentication')` (or `route(\App\Filament\Admin\Pages\PasswordReauthentication::ROUTE_NAME)`, matching the file's own import style):

- `tests/Feature/Filament/FeatureGateAdminTest.php` (line ~215)
- `tests/Feature/FinancialLedger/BulkFinancialExportTest.php` (lines ~458, ~517)
- `tests/Feature/IdentityAccess/AdminPanelHttpAccessTest.php` (line ~200 — also rename the test method `test_an_enrolled_operator_hitting_the_dashboard_is_redirected_to_the_mfa_challenge` at line ~191, since no actor is "enrolled" anymore; read the surrounding test body first — if it relies on MFA-enrollment fixture setup that no longer applies, this whole test case may need deleting rather than renaming, since the underlying scenario it tested (an enrolled actor at login) no longer exists once `EnforceMfaChallenge` is gone. Delete the test method if its premise is now impossible to construct; do not leave a renamed test asserting a scenario that can't occur.)
- `tests/Feature/Payment/RecordPaymentReversalRouteTest.php` (lines ~191, ~659)
- `tests/Feature/Payment/VerifyManualPaymentRouteTest.php` (lines ~226, ~678)

- [ ] **Step 5: Run the affected test files**

Run: `php artisan test tests/Feature/Filament/FeatureGateAdminTest.php tests/Feature/FinancialLedger/BulkFinancialExportTest.php tests/Feature/IdentityAccess/AdminPanelHttpAccessTest.php tests/Feature/Payment/RecordPaymentReversalRouteTest.php tests/Feature/Payment/VerifyManualPaymentRouteTest.php`
Expected: PASS — every redirect assertion now matches the real target.

- [ ] **Step 6: Commit**

```bash
git add routes/web.php app/Providers/AppServiceProvider.php \
  app/Filament/Admin/Pages/FeatureGateAdmin.php \
  app/Filament/Admin/Resources/BookingOrders/Actions/TransitionOrderAction.php \
  app/Filament/Admin/Resources/GravePlots/Tables/GravePlotsTable.php \
  app/Filament/Admin/Resources/MarketplaceOrders/Actions/MarkMarketplaceOrderPaidAction.php \
  app/Filament/Admin/Resources/PreNeedCases/Actions/PreNeedCaseActions.php \
  app/Filament/Admin/Resources/RenewalOrders/Actions/RecordExternalRenewalPaymentAction.php \
  app/Http/Controllers/Admin/FinanceExportController.php \
  tests/Feature/Filament/FeatureGateAdminTest.php \
  tests/Feature/FinancialLedger/BulkFinancialExportTest.php \
  tests/Feature/IdentityAccess/AdminPanelHttpAccessTest.php \
  tests/Feature/Payment/RecordPaymentReversalRouteTest.php \
  tests/Feature/Payment/VerifyManualPaymentRouteTest.php
git rm app/Http/Controllers/Admin/DisableMfaController.php \
  tests/Feature/IdentityAccess/Mfa/DisableMfaControllerTest.php
git commit -m "fix(identity-access): repoint every money-route re-auth redirect off the MFA challenge page

13 real call sites (3 route middleware attachments, 7 inline Filament
action redirects, 1 controller's own inline catch, plus the /admin/mfa/
disable route+controller deleted outright) all pointed at a page that
crashes for every admin today, since none is MFA-enrolled. Repoints all
of them to the new password re-authentication page."
```

---

### Task 4: Delete the MFA module, its tables, and its remaining tests

**Files:**
- Delete: entire `app/Platform/IdentityAccess/Mfa/` directory (24 remaining files after Task 2 relocated `MfaRateLimiter`)
- Delete: `app/Filament/Admin/Pages/MfaChallenge.php`, `app/Filament/Admin/Pages/MfaSettings.php`
- Delete: `app/Http/Middleware/EnforceMfaChallenge.php`
- Delete: `resources/views/filament/admin/pages/mfa-challenge.blade.php`, `resources/views/filament/admin/pages/mfa-settings.blade.php`
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Modify: `app/Platform/IdentityAccess/ActorContext.php`
- Modify: `app/Platform/IdentityAccess/Adapters/LocalUsersTableIdentityAccessAdapter.php`
- Create: `database/migrations/2026_08_22_120000_drop_mfa_tables.php`
- Modify: `tests/Feature/IdentityAccess/LocalUsersTableIdentityAccessAdapterTest.php`
- Modify: `tests/Unit/Domain/OrderWorkflow/OrderTransitionAuthorizerTest.php`
- Modify: `tests/Unit/Platform/IdentityAccess/ActorContextTest.php`
- Modify: `tests/Unit/Platform/ReauthenticationGuardTest.php`
- Delete: 12 remaining Mfa test files (listed in Step 4 below)

**Interfaces:**
- Consumes: nothing new.
- Produces: nothing new — this task's deliverable is that the MFA feature no longer exists anywhere in `app/`, `resources/`, or `database/migrations/`.

- [ ] **Step 1: Remove `AdminPanelProvider.php`'s remaining MFA references**

Remove `MfaChallenge::class,` and `MfaSettings::class,` from the `->pages([...])` array. Remove `EnforceMfaChallenge::class,` from the `->middleware([...])` array. Remove the `use App\Filament\Admin\Pages\MfaChallenge;`, `use App\Filament\Admin\Pages\MfaSettings;`, and `use App\Http\Middleware\EnforceMfaChallenge;` import lines.

- [ ] **Step 2: Remove `$mfaState` from `ActorContext`**

In `app/Platform/IdentityAccess/ActorContext.php`: remove the 4 `MFA_STATE_*` constants (`MFA_STATE_NOT_APPLICABLE`, `MFA_STATE_NOT_ENROLLED`, `MFA_STATE_ENROLMENT_PENDING`, `MFA_STATE_ENROLLED`) and their doc comments, remove the `public readonly string $mfaState = self::MFA_STATE_NOT_APPLICABLE,` constructor parameter, and remove the class-level doc-block paragraph describing `$mfaState` (the one starting `- $mfaState — S3-T2...`).

In `app/Platform/IdentityAccess/Adapters/LocalUsersTableIdentityAccessAdapter.php`: remove the `mfaState: $this->resolveMfaState($identity),` line from wherever `ActorContext` is constructed, and delete the entire `resolveMfaState()` private method (it queries the being-deleted `MfaEnrolment` model). Update the class-level doc comment's mention of `mfaState` (line ~53) to remove the now-false claim that this field is real/wired.

- [ ] **Step 3: Fix the 4 external test files that construct `ActorContext` with `mfaState:`**

In each of these, remove the `mfaState: ActorContext::MFA_STATE_*,` named argument from every `new ActorContext(...)` call (the constructor no longer accepts it):

- `tests/Feature/IdentityAccess/LocalUsersTableIdentityAccessAdapterTest.php` — this file's assertions ALSO check `$context->mfaState` directly (e.g. `$this->assertSame(ActorContext::MFA_STATE_NOT_APPLICABLE, $context->mfaState);`) — remove every such assertion line, not just the constructor arguments. Read the file in full first: some test method names describe MFA-state resolution specifically (e.g. around lines 182-273) and may need deleting entirely if their whole premise was testing `resolveMfaState()`'s behavior, which no longer exists.
- `tests/Unit/Domain/OrderWorkflow/OrderTransitionAuthorizerTest.php` — remove the `mfaState: ActorContext::MFA_STATE_NOT_APPLICABLE,` line from its `actor()` helper's `new ActorContext(...)` call.
- `tests/Unit/Platform/IdentityAccess/ActorContextTest.php` — remove the `mfaState:`-related assertions (e.g. `$this->assertSame(ActorContext::MFA_STATE_NOT_APPLICABLE, ActorContext::guest()->mfaState);`) and remove `'mfaState'` from the property-list array at line ~113 that iterates over `ActorContext`'s properties.
- `tests/Unit/Platform/ReauthenticationGuardTest.php` — remove the `mfaState: ActorContext::MFA_STATE_ENROLLED,` line from its `actor()` helper.

- [ ] **Step 4: Delete the remaining Mfa test files**

```bash
rm tests/Feature/IdentityAccess/Mfa/EnforceMfaChallengeMiddlewareTest.php
rm tests/Feature/IdentityAccess/Mfa/EnforceMfaChallengeRealHttpTest.php
rm tests/Feature/IdentityAccess/Mfa/MfaAuditSafetyTest.php
rm tests/Feature/IdentityAccess/Mfa/MfaChallengePageTest.php
rm tests/Feature/IdentityAccess/Mfa/MfaChallengeServiceTest.php
rm tests/Feature/IdentityAccess/Mfa/MfaEndToEndFlowTest.php
rm tests/Feature/IdentityAccess/Mfa/MfaEnrolmentServiceTest.php
rm tests/Feature/IdentityAccess/Mfa/MfaRecoveryServiceTest.php
rm tests/Feature/IdentityAccess/Mfa/MfaSettingsPageTest.php
rm tests/Feature/IdentityAccess/Reauthentication/MfaChallengeSatisfiesRecentAuthenticationTest.php
rm tests/Unit/Platform/IdentityAccess/Mfa/Totp/Base32Test.php
rm tests/Unit/Platform/IdentityAccess/Mfa/Totp/HotpRfc4226VectorsTest.php
rm tests/Unit/Platform/IdentityAccess/Mfa/Totp/OtpAuthUriTest.php
rm tests/Unit/Platform/IdentityAccess/Mfa/Totp/TotpRfc6238VectorsTest.php
rm tests/Unit/Platform/IdentityAccess/Mfa/Totp/TotpVerifyReplayAndDriftTest.php
```

(`MfaChallengeSatisfiesRecentAuthenticationTest.php` is superseded by Task 1's `PasswordReauthenticationSatisfiesRecentAuthenticationTest.php`, already covering the same contract.)

- [ ] **Step 5: Delete the Mfa module, its Filament pages, middleware, and views**

```bash
rm -rf app/Platform/IdentityAccess/Mfa
rm app/Filament/Admin/Pages/MfaChallenge.php
rm app/Filament/Admin/Pages/MfaSettings.php
rm app/Http/Middleware/EnforceMfaChallenge.php
rm resources/views/filament/admin/pages/mfa-challenge.blade.php
rm resources/views/filament/admin/pages/mfa-settings.blade.php
```

- [ ] **Step 6: Write the migration that drops the 3 MFA tables**

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Drops the 3 tables the removed MFA feature owned — see
 * `docs/adr/0024-use-session-auth-and-mfa.md`'s superseding note and
 * `docs/adr/0035-beta-launch-accepted-risks.md` item 10 for why the
 * feature was removed rather than merely left unenforced.
 *
 * Does NOT edit `2026_07_26_150000_create_mfa_enrolments_table.php` /
 * `..._150100_create_mfa_recovery_codes_table.php` /
 * `..._150200_create_mfa_challenges_table.php` — `AGENTS.md`'s
 * expand/contract migration discipline. Drop order is FK-safe: both
 * `mfa_recovery_codes` and `mfa_challenges` reference `mfa_enrolments`
 * (`cascadeOnDelete()`), so they drop first.
 *
 * `down()` deliberately does NOT recreate the tables. This codebase's own
 * convention (`AGENTS.md` §Database: "do not rely on destructive
 * production down() migrations for rollback") means rollback recovery
 * happens by restoring from a real backup, not by re-running a migration
 * that could not restore the enrolment/recovery-code data that existed
 * before this migration ran even if it did recreate empty tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('mfa_recovery_codes');
        Schema::dropIfExists('mfa_challenges');
        Schema::dropIfExists('mfa_enrolments');
    }

    public function down(): void
    {
        // Intentionally no-op — see this file's class-level doc comment.
    }
};
```

- [ ] **Step 7: Run the migration and the full affected test surface**

Run: `php artisan migrate`
Expected: the new migration runs cleanly, 3 tables dropped.

Run: `php artisan test tests/Feature/IdentityAccess tests/Unit/Platform/IdentityAccess tests/Unit/Domain/OrderWorkflow/OrderTransitionAuthorizerTest.php tests/Unit/Platform/ReauthenticationGuardTest.php`
Expected: PASS — no remaining reference to any deleted MFA class anywhere in this surface.

Run: `php artisan test` (full suite)
Expected: PASS. If any unexpected file still references a deleted MFA class, fix it here — do not leave a broken reference for a later task to discover.

- [ ] **Step 8: Commit**

```bash
git add app/Providers/Filament/AdminPanelProvider.php \
  app/Platform/IdentityAccess/ActorContext.php \
  app/Platform/IdentityAccess/Adapters/LocalUsersTableIdentityAccessAdapter.php \
  database/migrations/2026_08_22_120000_drop_mfa_tables.php \
  tests/Feature/IdentityAccess/LocalUsersTableIdentityAccessAdapterTest.php \
  tests/Unit/Domain/OrderWorkflow/OrderTransitionAuthorizerTest.php \
  tests/Unit/Platform/IdentityAccess/ActorContextTest.php \
  tests/Unit/Platform/ReauthenticationGuardTest.php
git rm -r app/Platform/IdentityAccess/Mfa \
  app/Filament/Admin/Pages/MfaChallenge.php \
  app/Filament/Admin/Pages/MfaSettings.php \
  app/Http/Middleware/EnforceMfaChallenge.php \
  resources/views/filament/admin/pages/mfa-challenge.blade.php \
  resources/views/filament/admin/pages/mfa-settings.blade.php \
  tests/Feature/IdentityAccess/Mfa \
  tests/Feature/IdentityAccess/Reauthentication/MfaChallengeSatisfiesRecentAuthenticationTest.php \
  tests/Unit/Platform/IdentityAccess/Mfa
git commit -m "feat(identity-access): remove the MFA feature entirely

Per the user's explicit 22 Aug 2026 decision (see ADR-0024's superseding
note). Deletes the whole app/Platform/IdentityAccess/Mfa module, its 2
Filament pages, its login-time enforcement middleware, its 3 database
tables, ActorContext's now-meaningless mfaState field, and 16 tests.
Every real redirect target and the ActorContext dependency were already
repointed/cleaned up in the two prior commits, so this leaves no dangling
reference."
```

---

### Task 5: Doc-comment sweep

**Files:**
- Modify (comment-only, no behavioral change): every file found in Step 1 below.

**Interfaces:**
- Consumes: nothing.
- Produces: nothing — purely a documentation-accuracy pass.

- [ ] **Step 1: Find every remaining doc-comment reference to a deleted MFA class**

Run: `grep -rln "MfaEnrolmentStatus\|MfaEnrolment\b\|MfaChallengeService\|MfaRecoveryService\|MfaEnrolmentService" app/ database/migrations/ config/ --include='*.php' | xargs grep -L "^use App\\\\Platform\\\\IdentityAccess\\\\Mfa"`

This lists files that mention a deleted class name but do NOT `use`-import it — i.e., doc-comment-only references (the ones that DO `use`-import it are real code dependencies; if this command finds any file that both matches this filter AND still has real code using the class, that is a Task 1-4 gap, not doc-sweep scope — stop and fix it in the relevant earlier task instead of papering over it here).

- [ ] **Step 2: Update each found comment**

For each file found, read its specific comment (already partially catalogued in this plan's Context section — `app/Domain/CemeteryDirectory/CemeteryType.php:24`, `app/Domain/Faq/FaqArticlePublishState.php:13`, and others in `app/Domain/Faq/*`, `app/Domain/Marketplace/ProductCode.php`, `app/Domain/ServiceCatalog/*`, `app/Platform/Audit/*`, `app/Platform/DocumentVault/*`, `app/Platform/FinancialLedger/*`, `app/Platform/Payment/*`, `config/reauthentication.php`), and replace the specific class-name citation with a different real, still-existing precedent class that makes the same point. Good replacements already confirmed to exist in this codebase: `App\Platform\IdentityAccess\Reauthentication\ReauthenticationOutcome` (a closed-list-string-enum precedent, same shape `MfaEnrolmentStatus` was cited for) or `App\Domain\Faq\FaqArticlePublishState` (if the citing file IS `FaqArticlePublishState.php` itself, pick a different precedent, e.g. `App\Domain\Marketplace\ProductCode`). Read each citing comment's actual point before picking a replacement — the replacement must make the same point the original class did (e.g. "a closed list validated at the application layer, not a Postgres enum").

Also update `config/reauthentication.php`'s doc comment (currently compares its own threshold-as-config-file choice against `MfaRateLimiter`'s constant-based threshold) to cite `ReauthenticationRateLimiter` instead — this one has a precise, correct replacement (the class moved, didn't disappear).

Also update `app/Platform/DocumentVault/Actions/IssueSignedUrl.php`'s doc comment (cites `IdentityAccess\Mfa\MfaRateLimiter`'s "established shape" as a design precedent) to cite `IdentityAccess\Reauthentication\ReauthenticationRateLimiter` instead — same reasoning, correct replacement.

- [ ] **Step 3: Verify no stale reference remains**

Run: `grep -rn "MfaEnrolmentStatus\|MfaChallengeService\|MfaRecoveryService\|MfaEnrolmentService\|Mfa\\\\MfaRateLimiter" app/ database/migrations/ config/ --include='*.php'`
Expected: no output (or only output inside files that legitimately still reference these as part of a git-history/changelog-style note, if any — read any remaining hit before deciding whether it needs fixing).

- [ ] **Step 4: Run `ci/verify-docs.sh` and the full test suite**

Run: `bash ci/verify-docs.sh`
Expected: all gates PASS (comment-only changes should not trip any hardcoded-value or structural gate).

Run: `php artisan test`
Expected: PASS (no behavioral change in this task).

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "docs: repoint doc-comment MFA precedent citations to still-existing classes

Mechanical sweep — no behavioral change. Every comment that cited a
deleted MFA class purely as a naming-pattern precedent now cites a real,
still-existing one instead."
```

---

### Task 6: Update governance and security documents

**Files:**
- Modify: `AGENTS.md`
- Modify: `docs/adr/0024-use-session-auth-and-mfa.md`
- Modify: `docs/adr/0035-beta-launch-accepted-risks.md`
- Modify: `docs/security/authentication-and-mfa.md`
- Modify: `docs/testing/release-gates.md`

**Interfaces:**
- Consumes: nothing.
- Produces: nothing — documentation-only task, the final one in this plan.

- [ ] **Step 1: Update `AGENTS.md` line 45**

Change:
```
- Use same-origin session auth for MVP and mandatory TOTP MFA for privileged roles.
```
to:
```
- Use same-origin session auth for MVP, with password-based recent re-authentication (`App\Http\Middleware\RequireRecentAuthentication`) required for financial, gate, bank-detail, certificate, plot-override, and bulk-export actions. TOTP MFA was built, then removed entirely — see `docs/adr/0024-use-session-auth-and-mfa.md`'s superseding note.
```

- [ ] **Step 2: Add a superseding note to ADR-0024**

Append a new section to `docs/adr/0024-use-session-auth-and-mfa.md` (after its existing `## Consequences` section):

```markdown

## Superseded (22 Aug 2026)

The "mandatory TOTP MFA for privileged roles" half of this decision is reversed. MFA was built in full (enrolment, TOTP/HOTP, recovery codes, login-time enforcement, self-service settings page — `app/Platform/IdentityAccess/Mfa/**`, removed in `docs/superpowers/plans/2026-08-22-mfa-removal-and-password-reauth.md`), but never enforced: `docs/adr/0035-beta-launch-accepted-risks.md` item 10 records the user's 19 Aug 2026 decision not to enrol any beta admin account. Building enrolment discovered a live bug independent of that decision — the money-route re-authentication challenge page (`MfaChallenge`) hard-required a confirmed MFA enrolment to function at all, so it crashed for every non-enrolled admin the instant `RequireRecentAuthentication`'s freshness window (15 minutes) lapsed during ordinary use.

Rather than build enrollment enforcement to fix the crash, the user decided (22 Aug 2026) to remove MFA entirely. Same-origin session authentication stands; recent re-authentication for sensitive actions now uses a password-only challenge (`App\Filament\Admin\Pages\PasswordReauthentication`) — the exact fallback this ADR's own `ReauthenticationService` doc block had already anticipated needing whenever an actor is not MFA-enrolled, now the only path since no actor ever will be.
```

- [ ] **Step 3: Correct ADR-0035 item 10**

Replace the existing item 10 section in `docs/adr/0035-beta-launch-accepted-risks.md` (currently framed as "No MFA enforcement on beta admin accounts... Reversal: cheap and fully backward-compatible — MFA enrolment is a self-service, per-account action") with:

```markdown
### 10. MFA removed entirely (supersedes the original self-service framing below)

Per the user's explicit 22 Aug 2026 decision, the MFA feature built for Lane D4 was removed entirely, not merely left unenforced — see `docs/adr/0024-use-session-auth-and-mfa.md`'s superseding note and `docs/superpowers/plans/2026-08-22-mfa-removal-and-password-reauth.md`. Discovered during that work: the money-route re-authentication challenge page hard-required a confirmed MFA enrolment, and none existed (per this item's original 19 Aug 2026 framing below), so it crashed for every admin the moment `RequireRecentAuthentication`'s 15-minute freshness window lapsed — a live, independent bug, not a consequence of the "no MFA enforcement" choice itself. Recent re-authentication for sensitive actions now uses a password-only challenge page instead.

**Original 19 Aug 2026 framing, kept for the historical record:** per the user's explicit decision, beta admin accounts would not be enrolled in MFA. This required no code change at the time: `App\Http\Middleware\EnforceMfaChallenge` only ever challenged an actor whose `MfaEnrolment` was already confirmed — an actor who never enrolled was never touched by it. The plan's Lane D4 recommended enrolling MFA on every beta admin account as a hardening step for money-route access; that recommendation was explicitly declined here, not overridden by any config flag.

**Mitigation:** password-based recent re-authentication (`RequireRecentAuthentication` + `PasswordReauthentication`), which every admin can already satisfy — no enrolment step of any kind.

**Reversal:** would require building MFA again from scratch — the module was deleted, not disabled.
```

- [ ] **Step 4: Rewrite the MFA-dependent sections of `docs/security/authentication-and-mfa.md`**

Read the file in full first. Rewrite every section that describes MFA as the step-up/challenge mechanism (enrolment flow, TOTP/recovery-code verification, the login-time challenge) to describe the password-only re-authentication page instead. Keep sections that remain accurate regardless of mechanism unchanged (the re-authentication freshness window design rationale, the six sensitive-action classes list, the rate-limiting rationale — update only the class name reference from `MfaRateLimiter` to `ReauthenticationRateLimiter` there). Add one sentence near the top noting MFA was built and then removed, pointing at `docs/adr/0024-use-session-auth-and-mfa.md`'s superseding note for the full history, so a reader lands on the "why" immediately rather than partway through.

- [ ] **Step 5: Correct the release-gates.md line**

Change, in `docs/testing/release-gates.md`:
```
- [ ] Privileged MFA, session revocation, and recent re-authentication pass.
```
to:
```
- [ ] Session revocation and password-based recent re-authentication pass.
```

(This box remains unchecked — this task only corrects its wording to match reality; providing real evidence for it is separate, future work, not part of this plan.)

- [ ] **Step 6: Run `ci/verify-docs.sh`**

Run: `bash ci/verify-docs.sh`
Expected: all gates PASS.

- [ ] **Step 7: Commit**

```bash
git add AGENTS.md docs/adr/0024-use-session-auth-and-mfa.md \
  docs/adr/0035-beta-launch-accepted-risks.md \
  docs/security/authentication-and-mfa.md \
  docs/testing/release-gates.md
git commit -m "docs: correct governance/security docs after MFA removal

AGENTS.md and ADR-0024 no longer state MFA is mandatory (a real
contradiction with the code otherwise); ADR-0035 item 10 now describes
an actual removal, not a reversible self-service absence;
authentication-and-mfa.md describes the real password-reauth mechanism;
release-gates.md's checklist wording matches reality."
```

---

## Verification

| Check | Command | Expected |
|---|---|---|
| Full test suite | `php artisan test` | PASS, zero references to any deleted MFA class remaining |
| Docs gates | `bash ci/verify-docs.sh` | All gates PASS |
| No stale MFA references in app code | `grep -rn "Mfa" app/ --include='*.php' \| grep -v "^app/Platform/IdentityAccess/Reauthentication"` | No output naming a deleted class (references to the surviving `ReauthenticationRateLimiter`'s own history in commit messages don't count) |
| Real CI run on this branch's own PR | (push, check GitHub Actions) | Green |

Real-Postgres verification for the two-connection-sensitive pieces of this plan is not needed — nothing here touches concurrent transactions; standard `RefreshDatabase` Feature tests are sufficient evidence throughout.

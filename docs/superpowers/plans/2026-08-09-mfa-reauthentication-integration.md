# MFA + Reauthentication Integration — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give `app/Platform/IdentityAccess/Mfa/**` and `RequireRecentAuthentication` their first real HTTP callers — voluntary, always-available TOTP MFA for any `/admin` user, enforced at login once enrolled, with `RequireRecentAuthentication` gating the one action that reduces account security (disabling MFA).

**Architecture:** Built natively inside Filament's existing `/admin` panel. Two new Filament pages (`MfaSettings`, `MfaChallenge`) plus one new middleware (`EnforceMfaChallenge`) appended to `AdminPanelProvider`'s existing chain for the login-time challenge. **One refinement beyond the approved design doc**: "Disable MFA" is implemented as a real `POST` route + controller (`DisableMfaController`), not a bare Filament page Action. Reason: `RequireRecentAuthentication` is built as classic Laravel route middleware — its own doc block's usage example is `Route::post(...)->middleware(RequireRecentAuthentication::class.'...')` — but a Filament Action is a Livewire AJAX method call that never passes through the route middleware stack the same way. Routing "Disable" through a real POST endpoint is what lets the existing, unmodified `RequireRecentAuthentication` class actually gate it, rather than reimplementing its freshness-check logic a second time inline. The `MfaSettings` page's "Disable MFA" button is a link/redirect to this route, not a Filament Action.

**Tech Stack:** Laravel 13, PHP 8.5, Filament 5.7.3 (per `composer.lock`), Pest via PHPUnit. **Verification note, matching this repo's own established convention** (see `AdminPanelProvider`'s and `AdminPanelHttpAccessTest`'s class-level doc blocks): this repo's own `vendor/` is empty and `composer install` cannot run on this host. A real installed copy of `filament/filament` v5.7.3 — the exact pinned version — exists on this host at `/home/ubuntu/platform-galang-dana-app/vendor/filament/filament` (a sibling project). Every Filament API usage below (`Filament\Pages\Page`, route registration via `Concerns\HasRoutes`, slug→route-name derivation) was traced against that real installed source, not guessed from documentation alone. Where this plan cannot fully resolve the exact registered route name without running the panel (see Task 4), it says so explicitly and gives a verification command — this is a stated verification method, not a placeholder.

## Global Constraints

- No FeatureGate, no role model, no new sensitive-action controller beyond what this plan itself builds — `docs/superpowers/specs/2026-08-09-mfa-reauthentication-integration-design.md`'s Non-goals.
- Every new behavior gets a real regression test (`AGENTS.md` §Testing). This host cannot run PHP — no `vendor/`, and `CLAUDE.md` §Scope note forbids `composer install` here. Every task below still follows RED→GREEN discipline in the code (write the test, know what it should assert and why), but "run it, confirm it fails/passes" happens via a CI push, not locally. Say so explicitly in each commit, per `AGENTS.md` §Infrastructure-agent execution — never claim PASS for an unexecuted check.
- `docs/design/design-system.md`: any new Blade view (the two Filament pages' templates) uses `resources/css/tokens.css`-driven values only — `ci/verify-docs.sh` gates 2/3/11/12 apply. Run `bash ci/verify-docs.sh` after every task that touches a Blade file.
- Every new class matches this module's established conventions: `declare(strict_types=1)`, `final class`, constructor-free stateless middleware (matching `AssignCorrelationId`/`RequireRecentAuthentication`'s own "resolve fresh via `app()`, no constructor-cached state" discipline), audit writes via `App\Platform\Audit\Audit::record()`/`Audit::wrap()` for every state change, `AuditSource::Panel` for every audit/reauthentication call in this plan (the only real caller context that exists).
- Never construct `ActorContext` ad hoc — resolve it via `app(ActorContext::class)`, matching `RequireRecentAuthentication`'s own pattern.
- `MfaEnrolment` rows are written ONLY through `MfaEnrolmentService`/`MfaRecoveryService` — never `->save()`-d directly from a controller or Filament page (`MfaEnrolment`'s own class doc block).

---

## Current shipped state (context for every task below)

- `MfaEnrolmentService`: `startEnrolment(int $userId): MfaEnrolment`, `otpauthUriFor(MfaEnrolment $enrolment, string $accountLabel, string $issuer = 'Makam.co.id'): string`, `confirm(MfaEnrolment $enrolment, string $submittedCode, int $actorRef, string $actorRole, AuditSource $source): MfaEnrolmentConfirmation`, `revoke(MfaEnrolment $enrolment, int $actorRef, string $actorRole, string $reason, AuditSource $source): MfaEnrolment`.
- `MfaChallengeService::challenge(MfaEnrolment $enrolment, string $submittedCode, int $actorRef, string $actorRole, AuditSource $source, string $ip = '0.0.0.0'): MfaChallengeResult` (`->valid`, `->rateLimited`).
- `MfaRecoveryService::redeem(MfaEnrolment $enrolment, string $submittedCode, int $actorRef, string $actorRole, AuditSource $source, string $ip = '0.0.0.0'): MfaRecoveryResult` (`->valid`, `->rateLimited`, `->noCodesRemaining`). **No `regenerate()` method exists yet — Task 1 adds it.**
- `MfaEnrolment` model: `isPending()`/`isConfirmed()`/`isRevoked()`, `recoveryCodes(): HasMany<MfaRecoveryCode>`, keyed by `user_id` → `App\Models\User`.
- `ActorContext` (resolved via `app(ActorContext::class)`): `->identityReference` (nullable, `users.id` for this adapter), `->isAuthenticated(): bool`, `->mfaState` — one of `ActorContext::MFA_STATE_NOT_APPLICABLE|NOT_ENROLLED|ENROLMENT_PENDING|ENROLLED`, already wired to real `MfaEnrolment` data via `LocalUsersTableIdentityAccessAdapter::resolveMfaState()`. `->lastAuthenticatedAt` (nullable `CarbonImmutable`).
- `RequireRecentAuthentication::handle($request, $next, string $reason, string $challengeRouteName)` — existing, unattached anywhere. Fail-closed (null `lastAuthenticatedAt` = always stale). On stale, stores `session()->put('url.intended', $request->fullUrl())` then `redirect()->route($challengeRouteName)`.
- `ReauthenticationService::challenge(int|string|null $actorRef, string $actorRole, string $reason, AuditSource $source, string $ip = '0.0.0.0'): ReauthenticationChallengeResult` (called by the middleware itself, not by this plan's code) and `satisfy(int|string|null $actorRef, string $actorRole, string $reason, AuditSource $source, string $ip = '0.0.0.0'): ReauthenticationEvent` (called by a future challenge controller once re-proof succeeds — Task 5 is that controller).
- `AdminPanelProvider::panel()`: `->pages([Pages\Dashboard::class])`, `->middleware([...9 classes ending in DispatchServingFilamentEvent::class])`, `->authMiddleware([Authenticate::class])`. No `Filament/Admin/Pages/` directory populated yet.
- `AuditSource::Panel` is the only real case used anywhere in this repo today.
- Filament 5.7.3 (verified against the sibling install): custom pages extend `Filament\Pages\Page`, self-register a route via the `Concerns\HasRoutes` trait — `getRoutePath()` returns `'/'.static::getSlug($panel)`, `getRelativeRouteName()` returns the slug with `/` replaced by `.`. A page is registered into a panel via `->pages([YourPage::class])`, matching how `Pages\Dashboard::class` is already registered.

---

## Task 1: `MfaRecoveryService::regenerate()`

**Files:**
- Modify: `app/Platform/IdentityAccess/Mfa/MfaRecoveryService.php`
- Test: `tests/Feature/IdentityAccess/Mfa/MfaRecoveryServiceTest.php` (existing file — add to it)

**Interfaces:**
- Produces: `MfaRecoveryService::regenerate(MfaEnrolment $enrolment, int $actorRef, string $actorRole, AuditSource $source): array` returning `list<string>` (new plaintext codes, same one-time-display contract as `MfaEnrolmentService::confirm()`'s `$recoveryCodes`).
- Consumes: `MfaEnrolment::recoveryCodes()`, `MfaRecoveryCode::markUsed()`, `MfaAuditActions` (add a new case — see Step 3), `Audit::record()`.

- [ ] **Step 1: Write the failing test**

```php
public function test_regenerate_invalidates_old_unused_codes_and_returns_ten_new_ones(): void
{
    $enrolment = MfaEnrolment::factory()->confirmed()->create();
    $oldCodes = MfaRecoveryCode::query()->where('mfa_enrolment_id', $enrolment->id)->get();
    $this->assertCount(10, $oldCodes);

    $newCodes = app(MfaRecoveryService::class)->regenerate(
        enrolment: $enrolment,
        actorRef: $enrolment->user_id,
        actorRole: 'authenticated_actor',
        source: AuditSource::Panel,
    );

    $this->assertCount(10, $newCodes);

    // Every old code is now unusable, even though it was never redeemed.
    foreach ($oldCodes as $old) {
        $this->assertNotNull($old->fresh()->used_at);
    }

    // The new codes are real, unused, and distinct from the old plaintext values.
    $freshCodes = $enrolment->recoveryCodes()->whereNull('used_at')->get();
    $this->assertCount(10, $freshCodes);
    foreach ($newCodes as $plaintext) {
        $this->assertTrue(
            $freshCodes->contains(fn (MfaRecoveryCode $c): bool => Hash::check($plaintext, $c->code_hash))
        );
    }
}

public function test_regenerate_writes_one_audit_event(): void
{
    $enrolment = MfaEnrolment::factory()->confirmed()->create();

    app(MfaRecoveryService::class)->regenerate(
        enrolment: $enrolment,
        actorRef: $enrolment->user_id,
        actorRole: 'authenticated_actor',
        source: AuditSource::Panel,
    );

    $this->assertDatabaseHas('audit_events', [
        'action' => MfaAuditActions::RECOVERY_CODES_REGENERATED,
        'outcome' => AuditOutcome::Allowed->value,
    ]);
}
```

(If `MfaEnrolment` has no `factory()` today, check `tests/Feature/IdentityAccess/Mfa/MfaEnrolmentServiceTest.php` for how existing tests construct a confirmed enrolment with recovery codes — likely via `MfaEnrolmentService::startEnrolment()` + `confirm()` directly rather than a factory. Match that existing pattern instead of inventing a factory if one doesn't exist; adjust the test above accordingly, keeping the same assertions.)

- [ ] **Step 2: Run test to verify it fails**

Cannot run locally (no `vendor/`). Expected failure reason if it could run: `Call to undefined method MfaRecoveryService::regenerate()`. State this in the commit message rather than an actual run output.

- [ ] **Step 3: Add the new audit action + implement `regenerate()`**

In `app/Platform/IdentityAccess/Mfa/MfaAuditActions.php`, add one new case following the file's existing convention (read the file first — it's a closed list of string constants like `ENROLMENT_CONFIRMED`, `CHALLENGE_SUCCEEDED`, etc.):

```php
public const string RECOVERY_CODES_REGENERATED = 'mfa.recovery_codes_regenerated';
```

In `MfaRecoveryService.php`, add (mirroring `MfaEnrolmentService::confirm()`'s recovery-code-generation block and `redeem()`'s transaction shape — do not duplicate the private `generateRecoveryCode()`-style charset logic; it currently lives in `MfaEnrolmentService` as a private static method, so either make it a small shared helper both services call, or duplicate the two `private const` charset/length values and the one generation loop with a comment pointing at the sibling copy in `MfaEnrolmentService` — prefer NOT extracting a new shared class for two ~15-line private methods; note the duplication explicitly in a comment instead, consistent with this module's existing style of flagging trade-offs rather than silently making them):

```php
/**
 * Invalidates every currently-unused recovery code for `$enrolment` and
 * generates a fresh batch of RECOVERY_CODE_COUNT — the standard
 * "regenerate" semantics: old codes stop working the moment new ones
 * exist, mirroring MfaEnrolmentService::confirm()'s one-time plaintext
 * display contract. Old codes are marked used (not deleted) — same
 * soft-invalidate-don't-delete convention this module already uses for
 * MfaEnrolment::REVOKED.
 */
public function regenerate(
    MfaEnrolment $enrolment,
    int $actorRef,
    string $actorRole,
    AuditSource $source,
): array {
    return DB::transaction(function () use ($enrolment, $actorRef, $actorRole, $source): array {
        $enrolment->recoveryCodes()->whereNull('used_at')->get()->each(
            fn (MfaRecoveryCode $code) => $code->markUsed()
        );

        $plaintextCodes = [];

        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $code = self::generateRecoveryCode();
            $plaintextCodes[] = $code;

            MfaRecoveryCode::create([
                'mfa_enrolment_id' => $enrolment->id,
                'code_hash' => Hash::make($code),
            ]);
        }

        Audit::record(
            action: MfaAuditActions::RECOVERY_CODES_REGENERATED,
            subject: new AuditSubject(type: 'mfa_enrolment', id: $enrolment->id),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorRef,
            actorRole: $actorRole,
            source: $source,
        );

        return $plaintextCodes;
    });
}
```

Add the same `RECOVERY_CODE_COUNT`/`RECOVERY_CODE_CHARSET`/`RECOVERY_CODE_LENGTH` private consts and `generateRecoveryCode()` private static method as `MfaEnrolmentService` has (copy exactly — same charset avoiding `0`/`O`/`1`/`I`, same 10-count, same `XXXXX-XXXXX` format), with a one-line comment noting the duplication and why it wasn't extracted.

- [ ] **Step 4: Run test to verify it passes**

Cannot run locally — verify via CI push (Task 8).

- [ ] **Step 5: Commit**

```bash
git add app/Platform/IdentityAccess/Mfa/MfaRecoveryService.php app/Platform/IdentityAccess/Mfa/MfaAuditActions.php tests/Feature/IdentityAccess/Mfa/MfaRecoveryServiceTest.php
git commit -m "Add MfaRecoveryService::regenerate() — invalidate old codes, issue a fresh batch"
```

## Task 2: `EnforceMfaChallenge` middleware

**Files:**
- Create: `app/Http/Middleware/EnforceMfaChallenge.php`
- Test: `tests/Feature/IdentityAccess/Mfa/EnforceMfaChallengeMiddlewareTest.php` (new — mirror `tests/Feature/IdentityAccess/Reauthentication/RequireRecentAuthenticationMiddlewareTest.php`'s ad-hoc-fixture-route pattern; read that file first for the exact convention before writing this one)

**Interfaces:**
- Consumes: `ActorContext` (`app(ActorContext::class)`, `->mfaState`, `->identityReference`), session (`session()->get('mfa_challenge_satisfied_at')` / `session()->put(...)`).
- Produces: nothing new consumed elsewhere in this plan directly, but this is the class Task 3 registers into `AdminPanelProvider`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace Tests\Feature\IdentityAccess\Mfa;

use App\Http\Middleware\EnforceMfaChallenge;
use App\Models\User;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Ad-hoc fixture route, same pattern RequireRecentAuthenticationMiddlewareTest
 * already established — this middleware is not attached to any real route in
 * this test file's own registration; Task 3 attaches it to AdminPanelProvider
 * for real.
 */
final class EnforceMfaChallengeMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['web', EnforceMfaChallenge::class])
            ->get('/__test/mfa-guarded', fn () => 'ok');

        Route::get('/__test/mfa-challenge-fixture', fn () => 'challenge-page')
            ->name('filament.admin.pages.mfa-challenge');
    }

    public function test_a_user_with_no_enrolment_passes_through_untouched(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get('/__test/mfa-guarded')
            ->assertOk()
            ->assertSee('ok');
    }

    public function test_an_enrolled_user_without_a_session_challenge_is_redirected(): void
    {
        $user = User::factory()->create();
        $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
        $this->confirmEnrolment($enrolment, $user);

        $this->actingAs($user)
            ->get('/__test/mfa-guarded')
            ->assertRedirect(route('filament.admin.pages.mfa-challenge'));
    }

    public function test_a_satisfied_session_flag_lets_an_enrolled_user_through(): void
    {
        $user = User::factory()->create();
        $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
        $this->confirmEnrolment($enrolment, $user);

        session()->put('mfa_challenge_satisfied_at', now()->toIso8601String());

        $this->actingAs($user)
            ->get('/__test/mfa-guarded')
            ->assertOk();
    }

    public function test_a_guest_is_not_redirected_by_this_middleware(): void
    {
        // No auth at all — Filament's own Authenticate middleware is what
        // would normally intercept a guest first; this middleware must not
        // itself assume an authenticated user.
        $this->get('/__test/mfa-guarded')->assertOk();
    }

    private function confirmEnrolment($enrolment, User $user): void
    {
        // Use the module's own real TOTP generation, matching how
        // MfaEnrolmentServiceTest already proves confirm() — read that file
        // for the exact helper/pattern (likely a Totp instance built from
        // the enrolment's decrypted secret) rather than guessing a code.
    }
}
```

(The `confirmEnrolment()` helper is deliberately left as a pointer to the existing pattern rather than invented here — `MfaEnrolmentServiceTest::test_confirm_...` already solves "generate a real valid TOTP code for a pending enrolment's secret." Copy that exact mechanism.)

- [ ] **Step 2: Run test to verify it fails**

Cannot run locally. Expected: `Class "App\Http\Middleware\EnforceMfaChallenge" not found`.

- [ ] **Step 3: Implement the middleware**

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Platform\IdentityAccess\ActorContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Self-service MFA's login-time enforcement — see
 * docs/superpowers/specs/2026-08-09-mfa-reauthentication-integration-design.md
 * Goal #2. Unconditional per-user (no FeatureGate): an authenticated actor
 * with a confirmed MfaEnrolment (ActorContext::MFA_STATE_ENROLLED) who has
 * not completed a challenge this session is redirected to the challenge
 * page. A non-enrolled or unauthenticated actor passes through untouched —
 * this middleware never blocks anyone who hasn't opted in to MFA.
 *
 * Distinct from RequireRecentAuthentication: that class gates one specific
 * sensitive ACTION behind a freshness window; this one gates panel ACCESS
 * itself behind "have you proven your second factor this session at all."
 * They compose independently and never call each other.
 */
final class EnforceMfaChallenge
{
    private const string SESSION_KEY = 'mfa_challenge_satisfied_at';

    public function handle(Request $request, Closure $next): Response
    {
        $actorContext = app(ActorContext::class);

        if (! $actorContext->isAuthenticated()) {
            return $next($request);
        }

        if ($actorContext->mfaState !== ActorContext::MFA_STATE_ENROLLED) {
            return $next($request);
        }

        if ($request->session()->has(self::SESSION_KEY)) {
            return $next($request);
        }

        $request->session()->put('url.intended', $request->fullUrl());

        return redirect()->route('filament.admin.pages.mfa-challenge');
    }
}
```

**Verification flag, stated rather than guessed**: the route name `'filament.admin.pages.mfa-challenge'` is derived from Filament 5.7.3's `HasRoutes::getRelativeRouteName()` (slug with `/`→`.`) plus the panel's own route-name prefixing, traced against the real installed package at `/home/ubuntu/platform-galang-dana-app/vendor/filament/filament` — but the panel-level prefix (`filament.{panelId}.` vs `filament.{panelId}.pages.`) was not fully confirmed in this plan's own research. Task 5 (the `MfaChallenge` page itself) MUST confirm the real registered name before this middleware's test can pass — run `php artisan route:list --name=mfa-challenge` in CI (or add a one-line assertion in Task 5's own test that `route('filament.admin.pages.mfa-challenge')` doesn't throw) and correct this string in both this file and the test above if it differs. Do not guess past this — confirm it for real.

- [ ] **Step 4: Run test to verify it passes**

Cannot run locally — verify via CI push (Task 8), after Task 5 has confirmed the real route name.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Middleware/EnforceMfaChallenge.php tests/Feature/IdentityAccess/Mfa/EnforceMfaChallengeMiddlewareTest.php
git commit -m "Add EnforceMfaChallenge middleware — login-time enforcement for enrolled users"
```

## Task 3: Register `EnforceMfaChallenge` in `AdminPanelProvider`

**Files:**
- Modify: `app/Providers/Filament/AdminPanelProvider.php`
- Test: `tests/Feature/IdentityAccess/AdminPanelHttpAccessTest.php` (existing — add one test; read the whole file first, it already documents the real verified redirect/route behavior for `/admin`)

**Interfaces:**
- Consumes: `EnforceMfaChallenge::class` (Task 2).

- [ ] **Step 1: Write the failing test**

Add to `AdminPanelHttpAccessTest.php`, following its existing style exactly (it already has real HTTP assertions against `/admin`):

```php
public function test_an_enrolled_admin_hitting_the_dashboard_is_redirected_to_the_mfa_challenge(): void
{
    $user = User::factory()->create();
    $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
    // ... confirm via the same real-TOTP pattern as Task 2's test ...

    $this->actingAs($user)
        ->get('/admin')
        ->assertRedirect(route('filament.admin.pages.mfa-challenge'));
}

public function test_a_non_enrolled_admin_still_reaches_the_dashboard(): void
{
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/admin')
        ->assertOk();
}
```

- [ ] **Step 2: Run test to verify it fails**

Cannot run locally. Expected: the enrolled-admin test fails because nothing redirects yet (middleware not registered).

- [ ] **Step 3: Register the middleware**

In `AdminPanelProvider.php`, add `EnforceMfaChallenge::class` to the existing `->middleware([...])` array — after `AuthenticateSession::class` (needs an authenticated session to check `ActorContext` meaningfully) and before `DispatchServingFilamentEvent::class`. Add the `use App\Http\Middleware\EnforceMfaChallenge;` import alongside the existing `use App\Http\Middleware\AssignCorrelationId;` line.

- [ ] **Step 4: Run test to verify it passes**

Cannot run locally — verify via CI push (Task 8).

- [ ] **Step 5: Commit**

```bash
git add app/Providers/Filament/AdminPanelProvider.php tests/Feature/IdentityAccess/AdminPanelHttpAccessTest.php
git commit -m "Register EnforceMfaChallenge in AdminPanelProvider's middleware chain"
```

## Task 4: `MfaChallenge` Filament page

**Files:**
- Create: `app/Filament/Admin/Pages/MfaChallenge.php`
- Create: `resources/views/filament/admin/pages/mfa-challenge.blade.php`
- Test: `tests/Feature/IdentityAccess/Mfa/MfaChallengePageTest.php` (new)

**Interfaces:**
- Consumes: `MfaChallengeService::challenge()`, `MfaRecoveryService::redeem()`, `ActorContext`.
- Produces: on success, sets `session(['mfa_challenge_satisfied_at' => now()->toIso8601String()])` (the exact key `EnforceMfaChallenge` reads — Task 2's `SESSION_KEY` constant; if that constant is `private`, either widen it to `public` so this page can reference it directly instead of restating the string, or restate the literal string here with a comment pointing at the constant's definition — prefer widening the constant to `public const` and referencing `EnforceMfaChallenge::SESSION_KEY` from this page, avoiding a second hardcoded copy of the key name), then `redirect()->intended(route('filament.admin.dashboard'))` — reusing the exact `url.intended` mechanism `RequireRecentAuthentication` and `EnforceMfaChallenge` already populate.

- [ ] **Step 1: Write the failing test**

```php
public function test_submitting_a_valid_totp_code_satisfies_the_session_and_redirects(): void
{
    $user = User::factory()->create();
    $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
    // confirm via real TOTP, same pattern as Task 2 — get $enrolment fresh + confirmed

    $code = $this->realTotpCodeFor($enrolment); // same helper pattern as MfaChallengeServiceTest

    Livewire::actingAs($user)
        ->test(MfaChallenge::class)
        ->set('code', $code)
        ->call('submit')
        ->assertRedirect();

    $this->assertTrue(session()->has('mfa_challenge_satisfied_at'));
}

public function test_a_wrong_code_does_not_satisfy_the_session(): void
{
    $user = User::factory()->create();
    $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
    // confirm ...

    Livewire::actingAs($user)
        ->test(MfaChallenge::class)
        ->set('code', '000000')
        ->call('submit')
        ->assertHasErrors();

    $this->assertFalse(session()->has('mfa_challenge_satisfied_at'));
}

public function test_a_recovery_code_also_satisfies_the_session(): void
{
    // Enrol, confirm, capture the returned plaintext recovery codes from
    // MfaEnrolmentService::confirm()'s MfaEnrolmentConfirmation, submit one
    // via the page's recovery-code fallback tab/toggle, assert the same
    // session flag gets set.
}
```

- [ ] **Step 2: Run test to verify it fails**

Cannot run locally. Expected: `Class "App\Filament\Admin\Pages\MfaChallenge" not found`.

- [ ] **Step 3: Implement the page**

```php
<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Mfa\MfaChallengeService;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus;
use App\Platform\IdentityAccess\Mfa\MfaRecoveryService;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Http\Middleware\EnforceMfaChallenge;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Auth;

/**
 * The one challenge surface both EnforceMfaChallenge (login-time) and
 * DisableMfaController (Task 7, via RequireRecentAuthentication) redirect
 * to. Reads the CONFIRMED MfaEnrolment for the current authenticated user
 * directly — never trusts a route parameter for which enrolment to check
 * against, since this page only ever concerns "prove you are this session's
 * own logged-in user."
 */
final class MfaChallenge extends Page
{
    protected static ?string $slug = 'mfa-challenge';

    protected static bool $shouldRegisterNavigation = false;

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

        $result = strlen($this->code) > 6
            ? app(MfaRecoveryService::class)->redeem(
                enrolment: $enrolment,
                submittedCode: $this->code,
                actorRef: $actorContext->identityReference,
                actorRole: 'authenticated_actor',
                source: AuditSource::Panel,
            )
            : app(MfaChallengeService::class)->challenge(
                enrolment: $enrolment,
                submittedCode: $this->code,
                actorRef: $actorContext->identityReference,
                actorRole: 'authenticated_actor',
                source: AuditSource::Panel,
            );

        if (! $result->valid) {
            $this->addError('code', 'Kode tidak valid. Silakan coba lagi.');

            return;
        }

        session()->put(EnforceMfaChallenge::SESSION_KEY, now()->toIso8601String());

        $this->redirect(session()->pull('url.intended', route('filament.admin.dashboard')));
    }
}
```

**Verification flag**: `strlen($this->code) > 6` as the TOTP-vs-recovery-code discriminator is a real, simple distinction (6-digit TOTP vs. the `XXXXX-XXXXX` 11-character recovery format), but confirm this against `MfaVerificationMethod`'s actual values and consider an explicit UI toggle (two tabs: "Authenticator code" / "Recovery code") instead of sniffing length, if the design review in Task 6's whole-plan re-review flags the sniffing approach as fragile. Either is acceptable; length-sniffing is simpler and avoids an extra UI state, chosen here for that reason.

Create the Blade view referencing only `resources/css/tokens.css`-driven classes (mirror an existing Filament-adjacent view's token usage if one exists, or the public site's `<x-mk.*>` primitive conventions translated to Filament's own form/field components — Filament pages typically use Filament's own `Forms`/`Schemas` components, not the public `<x-mk.*>` set, so check `docs/design/design-system.md` §8.3 for whether it specifies anything Filament-specific before inventing new markup).

- [ ] **Step 4: Register the page in `AdminPanelProvider`**

Add `Pages\MfaChallenge::class` — actually `\App\Filament\Admin\Pages\MfaChallenge::class` — to the panel's `->pages([...])` array alongside `Pages\Dashboard::class`, with its own `use App\Filament\Admin\Pages\MfaChallenge;` import (name it distinctly from Filament's own `Pages` facade-style import already in that file — e.g. `use App\Filament\Admin\Pages\MfaChallenge as MfaChallengePage;` if there's any collision risk, though there shouldn't be since the existing `use Filament\Pages;` import is a namespace import used as `Pages\Dashboard::class`, not a class import).

- [ ] **Step 5: Confirm the real route name**

Run (in CI, since this needs a real Filament boot): `php artisan route:list --name=mfa-challenge`. Confirm it matches `filament.admin.pages.mfa-challenge` (used in Tasks 2 and 3). If it differs, fix the string in `EnforceMfaChallenge.php`, `EnforceMfaChallengeMiddlewareTest.php`, and `AdminPanelHttpAccessTest.php`'s new test — in the same commit as this task, not deferred.

- [ ] **Step 6: Run tests to verify they pass**

Cannot run locally — verify via CI push (Task 8).

- [ ] **Step 7: Commit**

```bash
git add app/Filament/Admin/Pages/MfaChallenge.php resources/views/filament/admin/pages/mfa-challenge.blade.php app/Providers/Filament/AdminPanelProvider.php tests/Feature/IdentityAccess/Mfa/MfaChallengePageTest.php
git commit -m "Add MfaChallenge Filament page — the shared TOTP/recovery challenge surface"
```

## Task 5: `MfaSettings` Filament page — enroll, view status, regenerate

**Files:**
- Create: `app/Filament/Admin/Pages/MfaSettings.php`
- Create: `resources/views/filament/admin/pages/mfa-settings.blade.php`
- Test: `tests/Feature/IdentityAccess/Mfa/MfaSettingsPageTest.php` (new)

**Interfaces:**
- Consumes: `MfaEnrolmentService::startEnrolment()`/`otpauthUriFor()`/`confirm()`, `MfaRecoveryService::regenerate()` (Task 1), `ActorContext`.
- Produces: nothing consumed elsewhere in this plan — the "Disable" button in this page is a plain link to Task 7's route, not a method this page implements itself.

- [ ] **Step 1: Write the failing test**

```php
public function test_a_non_enrolled_user_sees_the_enroll_flow(): void
{
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test(MfaSettings::class)
        ->assertSee('otpauth://') // or whatever the QR-bearing markup surfaces — adjust to actual rendered content
        ->assertSet('enrolmentStatus', MfaEnrolmentStatus::PENDING);
}

public function test_confirming_the_first_code_shows_recovery_codes_once(): void
{
    $user = User::factory()->create();

    $component = Livewire::actingAs($user)->test(MfaSettings::class);
    // Pull the pending enrolment's secret the component just created, compute
    // a real TOTP code (same helper pattern as prior tasks), submit it:
    $component->set('confirmationCode', $realCode)
        ->call('confirmEnrolment')
        ->assertSet('enrolmentStatus', MfaEnrolmentStatus::CONFIRMED);

    // Recovery codes were shown in this response...
    $this->assertNotEmpty($component->get('displayedRecoveryCodes'));
}

public function test_an_enrolled_user_can_regenerate_recovery_codes_without_a_challenge(): void
{
    $user = User::factory()->create();
    $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
    // confirm ...

    Livewire::actingAs($user)
        ->test(MfaSettings::class)
        ->call('regenerateRecoveryCodes')
        ->assertHasNoErrors();

    // No RequireRecentAuthentication redirect involved — this call succeeds
    // directly, proving regenerate really is ungated per the approved design.
}

public function test_the_disable_button_links_to_the_gated_route_not_a_local_action(): void
{
    $user = User::factory()->create();
    $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
    // confirm ...

    Livewire::actingAs($user)
        ->test(MfaSettings::class)
        ->assertSeeHtml(route('admin.mfa.disable')); // Task 7's route
}
```

- [ ] **Step 2: Run test to verify it fails**

Cannot run locally. Expected: `Class "App\Filament\Admin\Pages\MfaSettings" not found`.

- [ ] **Step 3: Implement the page**

```php
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

final class MfaSettings extends Page
{
    protected static ?string $slug = 'mfa-settings';

    public ?MfaEnrolment $enrolment = null;

    public string $enrolmentStatus = '';

    public string $confirmationCode = '';

    /** @var list<string> */
    public array $displayedRecoveryCodes = [];

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
    }

    public function regenerateRecoveryCodes(): void
    {
        $actorContext = app(ActorContext::class);

        $this->displayedRecoveryCodes = app(MfaRecoveryService::class)->regenerate(
            enrolment: $this->enrolment,
            actorRef: $actorContext->identityReference,
            actorRole: 'authenticated_actor',
            source: AuditSource::Panel,
        );
    }
}
```

**Note on `mount()` auto-starting a pending enrolment**: visiting this page with no enrolment at all immediately calls `startEnrolment()`, creating a real pending row and secret. This is deliberate — it matches `MfaEnrolmentService::startEnrolment()`'s own documented "always supersedes, safe to call repeatedly" semantics, and means the QR code is ready to scan the instant the page loads rather than requiring a separate "start enrolling" click. A user who navigates away without confirming simply leaves a `PENDING` row that the next visit's `startEnrolment()` call supersedes — no cleanup needed, per that service's own doc block.

Blade view: display `otpauthUriFor()`'s URI as a QR code (check whether a QR-rendering package is already a dependency — `composer.json` — before adding one; if none exists, render the `otpauth://` URI as plain text plus a copy-to-clipboard affordance rather than pulling in a new dependency for this plan, and flag that as a deliberate scope-minimizing choice in the commit message), the confirmation code input, and — once `$displayedRecoveryCodes` is non-empty — the one-time recovery-code display with a clear "save these now, they will not be shown again" warning matching this module's own documented one-time-display contract.

- [ ] **Step 4: Register the page in `AdminPanelProvider`**

Add alongside Task 4's page in the same `->pages([...])` array.

- [ ] **Step 5: Run tests to verify they pass**

Cannot run locally — verify via CI push (Task 8).

- [ ] **Step 6: Commit**

```bash
git add app/Filament/Admin/Pages/MfaSettings.php resources/views/filament/admin/pages/mfa-settings.blade.php app/Providers/Filament/AdminPanelProvider.php tests/Feature/IdentityAccess/Mfa/MfaSettingsPageTest.php
git commit -m "Add MfaSettings Filament page — self-service enroll, status, regenerate"
```

## Task 6: `DisableMfaController` + route, gated by `RequireRecentAuthentication`

**Files:**
- Create: `app/Http/Controllers/Admin/DisableMfaController.php`
- Modify: `routes/web.php` (add the `/admin/mfa/disable` route — Filament's panel routes are auto-registered separately via `->pages()`, but this is a plain non-Filament controller route, matching `RequireRecentAuthentication`'s own doc block's literal `Route::post(...)` example, so it belongs in `routes/web.php` like any other named route, guarded additionally by `auth`/session context since it sits outside Filament's own panel middleware group — see Step 3's exact middleware list)
- Test: `tests/Feature/IdentityAccess/Mfa/DisableMfaControllerTest.php` (new)

**Interfaces:**
- Consumes: `RequireRecentAuthentication::class` (existing), `MfaEnrolmentService::revoke()`, `ReauthenticationService::satisfy()` — called from `MfaChallenge`'s `submit()` when reached via this gated path (see Step 3's note on how the challenge page knows which "mode" it's in).

- [ ] **Step 1: Write the failing test**

```php
public function test_disable_without_a_fresh_authentication_redirects_to_the_challenge(): void
{
    $user = User::factory()->create();
    $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
    // confirm ...
    // Do NOT create an actor_sessions row with a recent lastAuthenticatedAt —
    // RequireRecentAuthentication fails closed on a null timestamp.

    $this->actingAs($user)
        ->post(route('admin.mfa.disable'))
        ->assertRedirect(route('filament.admin.pages.mfa-challenge'));

    $this->assertSame(MfaEnrolmentStatus::CONFIRMED, $enrolment->fresh()->status);
}

public function test_disable_with_a_fresh_authentication_actually_revokes(): void
{
    $user = User::factory()->create();
    $enrolment = app(MfaEnrolmentService::class)->startEnrolment($user->id);
    // confirm ...
    ActorSession::factory()->for($user)->create(['authenticated_at' => now()]); // or however
    // this repo's real fixture for a "fresh" ActorContext->lastAuthenticatedAt
    // is built elsewhere — check RequireRecentAuthenticationMiddlewareTest's
    // own "fresh" test case and copy its exact setup.

    $this->actingAs($user)
        ->post(route('admin.mfa.disable'))
        ->assertRedirect();

    $this->assertSame(MfaEnrolmentStatus::REVOKED, $enrolment->fresh()->status);
}
```

- [ ] **Step 2: Run test to verify it fails**

Cannot run locally. Expected: `route [admin.mfa.disable] not defined` / class not found.

- [ ] **Step 3: Implement the route + controller**

In `routes/web.php`, following the file's existing style (real named routes, grouped, with a comment block matching its documented convention):

```php
Route::post('/admin/mfa/disable', DisableMfaController::class)
    ->middleware(['web', 'auth', RequireRecentAuthentication::class.':mfa_disable,filament.admin.pages.mfa-challenge'])
    ->name('admin.mfa.disable');
```

(Confirm the exact auth-guard middleware alias this repo's `bootstrap/app.php`/`config/auth.php` uses for the session guard — likely bare `'auth'` since `AdminPanelProvider`'s own `Authenticate::class` wraps the same underlying guard; verify against `config/auth.php`'s `defaults.guard` before assuming.)

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentService;
use App\Platform\IdentityAccess\Mfa\MfaEnrolmentStatus;
use App\Platform\IdentityAccess\Mfa\Models\MfaEnrolment;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Reached only after RequireRecentAuthentication's freshness check passes
 * (route middleware, see routes/web.php) — by the time this method runs,
 * the actor has already proved a fresh authentication. Calls
 * ReauthenticationService::satisfy() to close out that challenge's audit
 * trail (RequireRecentAuthentication itself only ever calls ::challenge(),
 * never ::satisfy() — see that service's own class doc block for why this
 * is a future controller's job), then performs the actual revoke.
 */
final class DisableMfaController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $user = Auth::user();
        $actorContext = app(ActorContext::class);

        app(ReauthenticationService::class)->satisfy(
            actorRef: $actorContext->identityReference,
            actorRole: 'authenticated_actor',
            reason: 'mfa_disable',
            source: AuditSource::Panel,
        );

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
```

**Design note on `satisfy()` placement**: called at the START of the controller action, immediately after the middleware has already confirmed freshness — this is correct per `ReauthenticationService`'s own doc block ("a future controller's ... successful check ... should ... call THIS class's `satisfy()`"), since reaching this controller method at all already means `RequireRecentAuthentication` let the request through (either it was already fresh, or the user completed `MfaChallenge` and was redirected back via `url.intended`).

- [ ] **Step 4: Run tests to verify they pass**

Cannot run locally — verify via CI push (Task 8).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/Admin/DisableMfaController.php routes/web.php tests/Feature/IdentityAccess/Mfa/DisableMfaControllerTest.php
git commit -m "Add DisableMfaController — RequireRecentAuthentication's first real attachment"
```

## Task 7: End-to-end integration test

**Files:**
- Test: `tests/Feature/IdentityAccess/Mfa/MfaEndToEndFlowTest.php` (new)

**Interfaces:**
- Consumes: everything from Tasks 1-6, exercised together as a real user journey rather than unit-by-unit.

- [ ] **Step 1: Write the test — the full journey in one file**

```php
public function test_full_journey_enroll_login_challenge_disable(): void
{
    $user = User::factory()->create();

    // 1. Not enrolled — visiting the dashboard is untouched.
    $this->actingAs($user)->get('/admin')->assertOk();

    // 2. Visit MfaSettings — a pending enrolment is auto-started.
    $settings = Livewire::actingAs($user)->test(MfaSettings::class);
    $pendingSecret = $settings->get('enrolment')->secret;

    // 3. Confirm with a real TOTP code.
    $code = $this->realTotpCodeForSecret($pendingSecret);
    $settings->set('confirmationCode', $code)->call('confirmEnrolment');
    $this->assertNotEmpty($settings->get('displayedRecoveryCodes'));

    // 4. Now enrolled — the dashboard redirects to the challenge.
    $this->actingAs($user)->get('/admin')->assertRedirect(route('filament.admin.pages.mfa-challenge'));

    // 5. Complete the challenge with a fresh code.
    $challengeCode = $this->realTotpCodeForSecret($pendingSecret); // new time-step
    Livewire::actingAs($user)->test(MfaChallenge::class)->set('code', $challengeCode)->call('submit');
    $this->assertTrue(session()->has('mfa_challenge_satisfied_at'));

    // 6. Now the dashboard is reachable.
    $this->actingAs($user)->get('/admin')->assertOk();

    // 7. Disable without a fresh reauth — redirected to challenge, NOT disabled.
    $this->actingAs($user)->post(route('admin.mfa.disable'))
        ->assertRedirect(route('filament.admin.pages.mfa-challenge'));

    // 8. Complete the reauth challenge, then disable succeeds.
    // (build fresh ActorContext->lastAuthenticatedAt per Task 6's own test pattern)
    $this->actingAs($user)->post(route('admin.mfa.disable'))->assertRedirect();

    $this->assertSame(
        MfaEnrolmentStatus::REVOKED,
        MfaEnrolment::query()->where('user_id', $user->id)->latest('id')->first()->status
    );

    // 9. Post-disable, the dashboard no longer challenges.
    $this->actingAs($user)->get('/admin')->assertOk();
}
```

- [ ] **Step 2: Run to verify it fails initially / passes once Tasks 1-6 are in**

Cannot run locally — this test only becomes meaningful once Tasks 1-6 are committed; run via CI push (Task 8) as the final confirmation the whole flow works together, not just each piece in isolation.

- [ ] **Step 3: Commit**

```bash
git add tests/Feature/IdentityAccess/Mfa/MfaEndToEndFlowTest.php
git commit -m "Add end-to-end test: enroll -> login challenge -> disable (reauth-gated)"
```

## Task 8: Finish the branch

- [ ] **Step 1: Run `bash ci/verify-docs.sh`**

Must pass all 12 gates (the two new Blade views are the only new surface this gate checks beyond what already passes).

- [ ] **Step 2: Push and let CI run the real PHP test suite**

This plan's entire RED→GREEN cycle has been static (no `vendor/` on this host) — CI (PostgreSQL 18) is the first real execution of every test in Tasks 1-7. Read the CI output for real, do not assume green.

- [ ] **Step 3: If CI fails, fix and re-push — do not claim done on an unexecuted assumption**

Given the number of Filament-API verification flags in this plan (Tasks 2, 4, 6), expect at least one real failure on the first CI run — likely the route-name string. Treat that as this plan working as intended (a flagged uncertainty resolving for real), not as a plan defect.

- [ ] **Step 4: Use `superpowers:finishing-a-development-branch`**

Base branch: `docs/design-system-and-planning`. Follow that skill exactly.

---

## Self-review

**Spec coverage:** Every Goal in the approved design doc maps to a task — Goal 1 (self-service enroll) → Tasks 4-5; Goal 2 (login challenge) → Tasks 2-3; Goal 3 (disable gated by real re-auth via MfaChallengeService) → Tasks 6-7; Goal 4 (regenerate ungated) → Task 1, exercised in Task 5. Every Non-goal (role model, sensitive-action wiring, FeatureGate, operator/vendor panels) has no task touching it.

**Placeholder scan:** Three explicitly-flagged verification points (Task 2 Step 3, Task 4 Step 5, Task 6 Step 3's auth-guard alias) are stated as "confirm this against X, here's how" — not vague TBDs, and each names the exact command or file to check. This matches the codebase's own established convention (`AdminPanelProvider`'s "VERIFICATION STATUS" doc blocks) for working without an installed Filament copy, rather than the plan skill's forbidden "add appropriate handling"-style placeholder.

**Type consistency:** `MfaChallengeResult`/`MfaRecoveryResult` (`->valid`, `->rateLimited`, `->noCodesRemaining`), `MfaEnrolmentConfirmation` (`->valid`, `->enrolment`, `->recoveryCodes`), and every service method signature used across Tasks 1-7 are copied verbatim from the real source files read during this plan's research (not assumed) — cross-checked that `MfaChallenge` (Task 4) and `MfaSettings` (Task 5) call the exact same method signatures Tasks 1-3's own research established.

**Scope check:** Single focused unit, matching the pilot retrofit's one-branch-one-PR pattern. Eight tasks is larger than the pilot's fix-wave findings but this is new capability, not a fix wave — comparable in size to the original booking-wizard plan (12 tasks).

---

## Verification

- [ ] `MfaRecoveryService::regenerate()` exists, invalidates old codes, returns 10 new ones, writes one audit event (Task 1).
- [ ] `EnforceMfaChallenge` redirects an enrolled-but-unchallenged session, passes through everyone else (Task 2), and is real-attached in `AdminPanelProvider` (Task 3).
- [ ] `MfaChallenge` page accepts both TOTP and recovery codes and sets the session flag on success (Task 4).
- [ ] `MfaSettings` page lets any `/admin` user enroll, view status, and regenerate codes with no re-auth gate (Task 5).
- [ ] `DisableMfaController` is reachable only after `RequireRecentAuthentication` passes, and actually revokes on success (Task 6) — this is `RequireRecentAuthentication`'s first real attachment anywhere in the repo.
- [ ] The end-to-end test (Task 7) proves the full journey works as one flow, not just as isolated units.
- [ ] CI green on PostgreSQL 18 (Task 8) — the only real execution of any test in this plan.
- [ ] `bash ci/verify-docs.sh` passes on every commit that touches a Blade file.

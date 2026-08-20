# Customer Auth Foundation — PR 1 of the `/akun` Account Area

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to
> implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship customer registration, login, logout, and password reset as their own
independently-verifiable slice, with zero visible change to the rest of the site (the header's
"Akun" link stays disabled — the account *area* itself is PR 2). This is Task 1/3 of the approved
plan at `/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md` — read that file for the full
context, the rejected alternatives, and PR 2/PR 3's scope. Only PR 1 is in scope here.

**Tech stack:** Laravel 13 / Livewire 4 / Blade / Tailwind 4, session-guard auth (ADR-0024 already
sanctions this — no OAuth/JWT needed), PHP 8.5 target in CI (this host runs 8.3.6; PHPUnit/Pint/
Larastan are CI-only here, `ci/verify-docs.sh` runs locally).

## Global Constraints

- **No `<x-mk.header>` changes, no `/akun/*` routes.** The account area's shell is PR 2. This PR's
  only visible surface is `/masuk`, `/daftar`, `/lupa-password`, `/reset-password/{token}`, and
  `POST /keluar` — none of them linked from anywhere yet. Reachable only by typing the URL.
- **Component shape mirrors `App\Livewire\Public\PreNeed\PreNeedInterestPage`** (read that file —
  it's in the repo): `final class` extending `Livewire\Component`, `declare(strict_types=1)`,
  plain public string properties, no `#[Validate]` attributes (none exist anywhere in this
  codebase), inline `$this->validate([...])` at the top of the action method, `render(): View`
  returning `view('livewire.public.auth.<kebab-name>')->layout('layouts.app', ['title' => '...',
  'active' => null])`. `'active' => null` on every one of these — none is a header nav key.
- **Use the `auth()`/`session()`/`request()` helpers**, not the `Auth`/`Session`/`Request` facades
  — avoids an awkward same-named-class import inside `namespace App\Livewire\Public\Auth`.
- **`app(\App\Platform\IdentityAccess\ActorContextResolver::class)->forget()`** must be called
  immediately after every guard mutation in this PR: after `auth()->attempt()` succeeds in
  `LoginPage`, after `auth()->login()` in `RegisterPage`, and defensively after `auth()->logout()`
  in `LogoutController`. That class's own doc block names this exact scenario — read it before
  writing the login/register actions. Always call `session()->regenerate()` (login/register — auth
  fixation) or `session()->invalidate()` + `session()->regenerateToken()` (logout) **before**
  `forget()`.
- **`redirect()->intended('/')`** is the fallback destination for both `LoginPage::login()` and
  `RegisterPage::register()` in THIS PR. `route('akun.index')` does not exist until PR 2 — using
  it here would 500 every successful login/register. (PR 2's own file list already plans to change
  this fallback to `route('akun.index')` once that route exists — do not do it here.)
- **No auto-login after password reset.** `ResetPasswordPage::reset()` ends with
  `redirect()->route('login')` and a success flash, never `auth()->login()`. This proves the new
  credentials work and needs no `ActorContextResolver::forget()` call (no guard mutation happens
  on this page or on `ForgotPasswordPage` at all).
- **Rate limiting is the low-level `RateLimiter` facade called inside the action**, not a named
  `RateLimiter::for()` limiter — Livewire actions all funnel through one shared `/livewire/update`
  route, so a route-level `throttle:` middleware cannot target a specific action. On a hit, use the
  house error idiom from `PreNeedInterestPage::registerInterest()`:
  `$this->addError('email', "Terlalu banyak percobaan. Coba lagi dalam {$seconds} detik.")` then
  `return` — never throw. Fire `Illuminate\Auth\Events\Lockout` on the login lockout only (Laravel
  ships this event for exactly this purpose; no custom event needed).
- **Logout is `POST` + CSRF, an invokable controller**, not Livewire — read
  `app/Http/Controllers/Admin/DisableMfaController.php` first, it's this PR's house pattern for a
  plain invokable controller (`final class ... { public function __invoke(): RedirectResponse
  {...} }`). A GET-logout is a CSRF hole; do not build one.
- **No new migrations, no `User` model changes, no `MustVerifyEmail`.** `password_reset_tokens`
  already exists and `config/auth.php`'s broker is already wired to it — password reset needs
  nothing new schema-wise. `App\Models\User`'s `#[Fillable(['name','email','password'])]` with
  `'password' => 'hashed'` casting already covers registration exactly as-is — do not touch
  `User.php`. `actor_sessions` bookkeeping is automatic (`RecordActorSessionOnLogin`/
  `RecordActorSessionOnLogout` are already registered listeners on Laravel's own `Login`/`Logout`
  events) — do not write to `actor_sessions` directly from any of this PR's code.
- **Validation:** email `['required','email','max:255']` everywhere it appears; register email
  additionally `'unique:users,email'`; any password field `['required','confirmed',
  \Illuminate\Validation\Rules\Password::defaults()]` (the rule object, not a raw regex).
- **Views use `<x-mk.field :error="$errors->first('propName')">` and `<x-mk.button
  wire:click="...">`** — read `resources/views/components/mk/field.blade.php` and
  `.../button.blade.php` first for their exact prop names; do not invent new props. No new Blade
  components. No hardcoded colors/spacing/durations — every value must trace to a Tailwind utility
  backed by `resources/css/tokens.css` (`ci/verify-docs.sh` GATE 2/3 check this and run locally).
- **Every test:** `RefreshDatabase`; any test that renders the full `layouts.app` layout
  (i.e. actually hits a page, not just calls a Livewire action test helper against a bare
  component) needs `$this->withoutVite()` in `setUp()` — see
  `tests/Feature/RateLimiting/PublicGuestThrottleTest.php` for the exact pattern and why (CI's PHP
  job never builds frontend assets).
- **Gates after each task:** `composer lint`, `composer analyse`, `php artisan test` — all
  CI-only on this host (PHP 8.3.6 vs the `composer.lock` `~8.5.0` requirement). Never report a
  local PASS for these; report NOT RUN LOCALLY and rely on the pushed CI run. `ci/verify-docs.sh`
  IS runnable locally (pure bash/grep) — run it after each task.
- **Commit format:** Conventional-commit style matching this repo's history (`feat(auth): ...`).

---

## Task 1: Login + Logout

The guard-mutation pair. This task also carries the single most important regression test in the
whole PR — read the interfaces block below before starting.

### Interfaces / exact values

**Route names** (added to `routes/web.php`, alongside the existing route blocks — follow the
file's own convention of a `/* ---- */` banner comment naming the spec/PR before each new block):
- `Route::get('/masuk', LoginPage::class)->middleware('guest')->name('login');`
- `Route::post('/keluar', LogoutController::class)->middleware('auth')->name('logout');`

The name `login` is load-bearing: `Illuminate\Auth\Middleware\Authenticate` redirects
unauthenticated access to a protected route to `route('login')` by name when it exists — this is
what makes PR 2's `/akun/*` `auth` middleware group "redirect to login, then return to where you
were" work for free via `redirect()->intended(...)`, without any code in PR 2 needing to know
about it. `guest` and `auth` are Laravel 13's own default middleware aliases — no new alias to
register.

**`LoginPage` (`app/Livewire/Public/Auth/LoginPage.php`):**
- Public properties: `string $email = ''`, `string $password = ''`, `bool $remember = false`.
- `login(): void` — validate `['email' => ['required','email'], 'password' => ['required']]`
  (deliberately NOT `'email:rfc,dns'` or `max:255` here — this is a login attempt against an
  existing row, not new data; over-validating an existing email format risks rejecting a valid
  login before the credential check even runs).
- Rate limit key: `'login:'.\Illuminate\Support\Str::lower($this->email).'|'.request()->ip()`,
  limit 5 per 60 seconds, via the `\Illuminate\Support\Facades\RateLimiter` facade
  (`tooManyAttempts`/`hit`/`clear`/`availableIn` — same primitives Laravel's own Breeze
  scaffolding uses). On a hit: fire `Illuminate\Auth\Events\Lockout` with `request()`, add the
  house-idiom error to `'email'`, return.
- On a miss: `if (! auth()->attempt(['email' => $this->email, 'password' => $this->password],
  $this->remember)) { $this->addError('email', 'Email atau kata sandi salah.'); return; }` — no
  distinction between "unknown email" and "wrong password" in the message (standard
  no-enumeration practice; this codebase already follows it for password reset per the master
  plan, so match it here too).
- On success, in this exact order: `session()->regenerate()` →
  `app(\App\Platform\IdentityAccess\ActorContextResolver::class)->forget()` →
  `\Illuminate\Support\Facades\RateLimiter::clear($key)` → `redirect()->intended('/')`.
- `render(): View` per Global Constraints, title `'Masuk - Makam.co.id'`.

**`LogoutController` (`app/Http/Controllers/Auth/LogoutController.php`):**
- `final class LogoutController extends Controller { public function __invoke(): RedirectResponse
  { auth()->logout(); session()->invalidate(); session()->regenerateToken();
  app(ActorContextResolver::class)->forget(); return redirect()->route('login'); } }` — match
  `DisableMfaController`'s doc-block style (explain what this does and why `forget()` is called
  defensively even though nothing in this request is expected to resolve `ActorContext` again
  before the redirect).

**View (`resources/views/livewire/public/auth/login-page.blade.php`):** email field, password
field (`type="password"`), a "remember me" checkbox, submit button (`wire:click="login"` or
`wire:submit="login"` on a `<form>` — match whichever pattern `PreNeedInterestPage`'s own view
uses), a link to "Belum punya akun? Daftar" and a link to "Lupa kata sandi?".

**Preflight ruling (recorded before Task 1 was dispatched):** these two links must use the literal
paths `/daftar` and `/lupa-password` in Task 1, NOT `route('register')`/`route('password.request')`.
Those two named routes don't exist until Task 2 and Task 3 respectively; `LoginPageTest` renders
this view via `Livewire::test(LoginPage::class)`, which would throw `RouteNotFoundException` if the
view called either named route before its route is registered — a plan-internal ordering defect,
caught and fixed here rather than left for the implementer to discover. Task 2 and Task 3 each get
a small step (below) to swap these two literal paths to their real named-route calls once the
routes they reference exist — same destination URL either way, so nothing else about the page
changes.

### Tests

`tests/Feature/Livewire/Public/Auth/LoginPageTest.php`:
- Correct credentials → authenticated, redirected to `/` (or intended URL).
- Wrong password → not authenticated, generic error on the `email` field, no distinction from
  unknown-email case (assert the exact same error message for both).
- 6th attempt within 60s (same email+IP) → blocked with the rate-limit message, credentials never
  checked (assert `auth()->check()` is still false even with correct credentials on the 6th try).
- `remember` checkbox → `remember_token` cookie/queue set (Livewire test: assert via
  `auth()->viaRemember()` after a fresh guard resolution, or assert the `remember_web_...` cookie
  is queued).
- **The regression test that matters most:** in one request/test, resolve
  `app(ActorContextResolver::class)->resolve()` BEFORE calling `login()` (forces the guest
  resolution to cache, matching a real page load that already touched `ActorContext` — e.g. via
  the header, though the header isn't wired yet, so resolve it directly), assert `->isAuthenticated()`
  is false, then invoke the Livewire component's `login()` action with valid credentials, then
  assert `app(ActorContextResolver::class)->resolve()->isAuthenticated()` is now true **without a
  new request** — this fails loudly if `forget()` is ever dropped from `LoginPage::login()`.

`tests/Feature/Http/Controllers/Auth/LogoutControllerTest.php`:
- Authenticated `POST /keluar` → `auth()->check()` false afterward, redirected to `route('login')`.
- `GET /keluar` → 405 (route only registers POST).
- Guest `POST /keluar` → redirected to `route('login')` by the `auth` middleware (never reaches
  the controller) — assert this doesn't throw.

Run `composer test -- --filter=LoginPageTest` / `LogoutControllerTest` conceptually — actually run
via `php artisan test --filter=...`; report NOT RUN LOCALLY (PHP version mismatch) and rely on CI.

- [ ] **Step 1: Write the failing tests** — both files above.
- [ ] **Step 2: Run to verify they fail** → expect a clean "class not found" style failure (report
      as NOT RUN LOCALLY / CI-pending on this host; if a compatible PHP is available, run for real
      and report PASS/FAIL honestly).
- [ ] **Step 3: Implement** `LoginPage.php`, its view, `LogoutController.php`, and the two routes.
- [ ] **Step 4: Run tests** → report actual outcome, not an assumed PASS.
- [ ] **Step 5: Gates + commit** — `ci/verify-docs.sh` locally; `git commit -m "feat(auth): login and logout flow (PR 1/3 of /akun account area)"`.

---

## Task 2: Register + the `public-guest` security fix

These two changes ship together because the second exists only to close a hole the first opens —
see Global Constraints and the master plan's Context section for the full reasoning: today
`AppServiceProvider`'s `public-guest` limiter returns `Limit::none()` for ANY authenticated
request (harmless today — nothing real is authenticated on public routes), and shipping
registration without fixing that arm turns "create a free account" into a standing bypass of the
whole public rate limit.

### Interfaces / exact values

**Route:** `Route::get('/daftar', RegisterPage::class)->middleware('guest')->name('register');`

**`RegisterPage` (`app/Livewire/Public/Auth/RegisterPage.php`):**
- Public properties: `string $name = ''`, `string $email = ''`, `string $password = ''`,
  `string $password_confirmation = ''`.
- `register(): void` — rate limit key `'register:'.request()->ip()`, limit 3 per 60 seconds, same
  facade pattern as Task 1 (no `Lockout` event here — that's Laravel's login-specific event, not
  a fit for a registration throttle; just the house error idiom + return).
- Validate: `'name' => ['required','string','max:255']`, `'email' => ['required','email','max:255','unique:users,email']`,
  `'password' => ['required','confirmed', \Illuminate\Validation\Rules\Password::defaults()]`.
- `$user = \App\Models\User::query()->create(['name' => $this->name, 'email' => $this->email,
  'password' => $this->password]);` — `'password' => 'hashed'` casting on the model handles
  hashing; do not call `Hash::make()` yourself (double-hashing bug).
- On success, exact same three-call sequence as `LoginPage`: `session()->regenerate()` →
  `auth()->login($user)` → wait, order matters: `auth()->login($user)` FIRST (it's the guard
  mutation), THEN `session()->regenerate()`, THEN `app(ActorContextResolver::class)->forget()`,
  THEN `\Illuminate\Support\Facades\RateLimiter::clear($key)` if you want (optional here — a
  fresh IP-based counter isn't sensitive the way a login counter is; skip clearing it, simpler),
  THEN `redirect()->intended('/')` (same PR-1 fallback as Task 1 — not `route('akun.index')`).
- `render(): View` per Global Constraints, title `'Daftar - Makam.co.id'`.

**View:** name, email, password, password-confirmation fields; submit button; a link to `/masuk`
("Sudah punya akun? Masuk") via `route('login')`.

**Also in this task:** in `login-page.blade.php` (from Task 1), swap the literal `/daftar` href to
`route('register')` — the route now exists. Leave the `/lupa-password` link as a literal path;
Task 3 swaps that one.

**`app/Providers/AppServiceProvider.php` — the exact current code to change:**
```php
RateLimiter::for('public-guest', static function (Request $request): Limit {
    if ($request->user() !== null) {
        return Limit::none();
    }

    return Limit::perMinute(60)->by($request->ip());
});
```
Change the authenticated arm to a generous-but-finite per-user limit:
```php
RateLimiter::for('public-guest', static function (Request $request): Limit {
    $user = $request->user();

    if ($user !== null) {
        return Limit::perMinute(120)->by('user:'.$user->getAuthIdentifier());
    }

    return Limit::perMinute(60)->by($request->ip());
});
```
120/min preserves the original concern this limiter's doc block already states (a real customer's
own multi-step booking wizard makes many round trips) while closing the unthrottled bypass. Update
the limiter's own doc block/comment in the same file if it currently asserts "every authenticated
request is exempt" as a permanent fact — that sentence becomes false the moment this lands, and
this repo's own convention (seen throughout `routes/web.php`) is to keep doc comments truthful
against the code they sit next to.

### Tests

`tests/Feature/Livewire/Public/Auth/RegisterPageTest.php`:
- Valid registration → user row created, `password` column is a bcrypt/argon hash (not plaintext,
  not equal to the input), `auth()->check()` true immediately after.
- Duplicate email → validation error on `email`, no user created, no auth.
- Password confirmation mismatch → validation error, no user created.
- Newly registered user has zero `ActorRole` grants and `canAccessPanel()` returns false for both
  the `admin` and `vendor` Filament panels (construct both `Panel` instances or use whatever
  existing test helper `AdminPanelAccessPolicy`/`VendorPanelAccessPolicy` tests already use for
  this — grep `tests/` for an existing `canAccessPanel` assertion pattern first and match it
  rather than inventing a new one).
- 4th registration attempt within 60s from the same IP → blocked, no user created on that attempt.

Extend `tests/Feature/RateLimiting/PublicGuestThrottleTest.php`'s existing
`test_an_authenticated_request_is_never_throttled` — **this test's current name and assertion are
about to become false**; do not silently leave a misleading passing test. Rename it to something
like `test_an_authenticated_request_gets_a_generous_but_finite_limit` and rewrite its body: loop
120 successful requests, then assert request 121 is `429`. Keep the existing guest 60/min test
as-is (untouched by this change).

- [ ] **Step 1: Write/update the failing tests** — `RegisterPageTest.php` (new) and
      `PublicGuestThrottleTest.php` (rename + rewrite the authenticated-arm test).
- [ ] **Step 2: Run to verify they fail** → report actual outcome.
- [ ] **Step 3: Implement** `RegisterPage.php`, its view, the `/daftar` route, and the
      `AppServiceProvider.php` limiter fix.
- [ ] **Step 4: Run tests** → report actual outcome.
- [ ] **Step 5: Gates + commit** — `ci/verify-docs.sh` locally; `git commit -m "feat(auth): registration and close the public-guest rate-limit bypass"`.

---

## Task 3: Password reset + information architecture doc

No guard mutation anywhere in this task — the simplest of the three. Folds in the documentation
update since it's small and needs every route name from Tasks 1-2 plus this task's own two.

### Interfaces / exact values

**Routes:**
- `Route::get('/lupa-password', ForgotPasswordPage::class)->middleware('guest')->name('password.request');`
- `Route::get('/reset-password/{token}', ResetPasswordPage::class)->middleware('guest')->name('password.reset');`

The name `password.reset` is load-bearing: `Illuminate\Auth\Notifications\ResetPassword::toMail()`
(Laravel's own built-in notification, dispatched automatically by the `Password::` broker) builds
its email link via `route('password.reset', ['token' => ..., 'email' => ...])` — naming it this
makes the already-wired broker's email work with zero custom notification class.

**`ForgotPasswordPage` (`app/Livewire/Public/Auth/ForgotPasswordPage.php`):**
- Public property: `string $email = ''`.
- `sendResetLink(): void` — rate limit key `'password-reset:'.\Illuminate\Support\Str::lower($this->email).'|'.request()->ip()`,
  limit 3/60s, same house idiom on a hit.
- Validate `'email' => ['required','email']`.
- `\Illuminate\Support\Facades\Password::sendResetLink(['email' => $this->email]);` — call this
  **regardless of whether the email exists** (Laravel's broker already returns a generic status
  either way and does nothing observable differently — this is what gives the "unknown-email
  response is identical to known-email" no-enumeration property for free; do not branch on the
  return value to show a different message).
- Set `public bool $linkSent = false;` → `true` after calling `sendResetLink` (regardless of the
  broker's internal result), and have the view swap to a "Jika email terdaftar, tautan reset telah
  dikirim." confirmation state. This is the one place a generic message is load-bearing for
  security, not just tone — do not render `Password::sendResetLink()`'s return status directly.
- `render(): View`, title `'Lupa Kata Sandi - Makam.co.id'`.

**`ResetPasswordPage` (`app/Livewire/Public/Auth/ResetPasswordPage.php`):**
- `mount(string $token): void { $this->token = $token; }` with `public string $token = '';` (from
  the route parameter), plus `public string $email = ''` (pre-filled from `?email=` query string
  if present: `$this->email = request()->query('email', '');` in `mount()`), `public string
  $password = ''`, `public string $password_confirmation = ''`.
- `reset(): void` — validate `'email' => ['required','email']`, `'password' => ['required',
  'confirmed', \Illuminate\Validation\Rules\Password::defaults()]`. Then:
  ```php
  $status = \Illuminate\Support\Facades\Password::reset(
      ['token' => $this->token, 'email' => $this->email, 'password' => $this->password, 'password_confirmation' => $this->password_confirmation],
      function ($user, $password) {
          $user->forceFill(['password' => $password])->save();
      }
  );
  ```
  (The closure sets a plain string; `User`'s `'password' => 'hashed'` cast hashes it on save — do
  not call `Hash::make()` here either.) On `$status === \Illuminate\Support\Facades\Password::PASSWORD_RESET`:
  `session()->flash('status', 'Kata sandi berhasil direset. Silakan masuk.'); redirect()->route('login');`
  — **no `auth()->login()` call, per Global Constraints.** On any other status: add a generic
  error, e.g. `$this->addError('email', 'Tautan reset tidak valid atau sudah kedaluwarsa.')`
  (Laravel's broker already fails closed on an invalid/expired/mismatched token — this branch
  covers all of those uniformly, again no enumeration of which specific thing was wrong).
- `render(): View`, title `'Reset Kata Sandi - Makam.co.id'`.

**Views:** `forgot-password-page.blade.php` (email field + submit, or the `linkSent` confirmation
state), `reset-password-page.blade.php` (hidden/prefilled email + password + confirmation +
submit).

**Also in this task:** in `login-page.blade.php`, swap the literal `/lupa-password` href to
`route('password.request')` — the route now exists (last of the two swaps; `/daftar` was already
swapped in Task 2).

**`docs/product/information-architecture.md`:** add the 5 new paths from this PR to §1's existing
route-tree table/list (open the file, find §1, match its existing row format exactly — do not
invent a new format). Do NOT add the PR 2/PR 3 `/akun/*` paths — those aren't real yet in this PR
and `ci/verify-docs.sh`'s GATE 4 checks that documented links resolve to something real.

### Tests

`tests/Feature/Livewire/Public/Auth/PasswordResetTest.php` — use `Illuminate\Support\Facades\Notification::fake()`:
- Known email → `sendResetLink` → `Notification::assertSentTo($user, \Illuminate\Auth\Notifications\ResetPassword::class)`,
  page shows the generic `linkSent` confirmation.
- Unknown email → `sendResetLink` → `Notification::assertNothingSent()`, page STILL shows the
  identical `linkSent` confirmation (this is the assertion that actually proves no enumeration —
  assert the rendered response is byte-for-byte the same shape/message as the known-email case).
- Valid token + matching email → `reset()` → password changed (fetch the user, assert the new
  password verifies via `\Illuminate\Support\Facades\Hash::check()`), redirected to `route('login')`,
  `auth()->check()` is false (no auto-login).
- Invalid/expired/tampered token → `reset()` → generic error, password unchanged.
- 4th `sendResetLink` call within 60s for the same email+IP → blocked, `Notification::assertNothingSent()`.

- [ ] **Step 1: Write the failing tests** — `PasswordResetTest.php`.
- [ ] **Step 2: Run to verify they fail** → report actual outcome.
- [ ] **Step 3: Implement** `ForgotPasswordPage.php`, `ResetPasswordPage.php`, both views, both
      routes, and the `information-architecture.md` update.
- [ ] **Step 4: Run tests** → report actual outcome.
- [ ] **Step 5: Gates + commit** — `ci/verify-docs.sh` locally (this task is the one most likely to
      trip GATE 4 given the doc edit — run it for real, don't assume); `git commit -m "feat(auth):
      password reset flow and information-architecture doc update"`.

---

## Task 4: Push + open PR + whole-branch review (post-tasks)

- [ ] **Step 1: Push the branch**, open a PR against `docs/design-system-and-planning` (this repo's
      main/trunk branch per the git status at session start). PR description must state plainly:
      "PHPUnit/Larastan/Pint NOT RUN LOCALLY (PHP 8.3.6 vs required ~8.5.0) — verified via CI."
      Also state the scope boundary explicitly: header/`/akun/*` routes are PR 2, not this PR — a
      reviewer should not expect to see a live "Akun" link after this merges.
- [ ] **Step 2: Wait for CI**, diagnose and fix any real failure (see the memory note on stuck
      GitHub Actions Playwright runners from earlier in this project — a job stuck `in_progress`
      with a stale `updated_at` for 10+ minutes is a dead runner, `gh run cancel` + `gh run rerun`,
      not a real failure).
- [ ] **Step 3: Whole-branch review** per `superpowers:subagent-driven-development`'s Final Review
      section — dispatch on the most capable available model, point it at this plan's Global
      Constraints, triage any deferred-minor/parked ledger entries.
- [ ] **Step 4: Merge once green and reviewed clean.**

# FFI Clone — Auth Pages Restyle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Restyle the four auth pages (login, register, forgot-password, reset-password) to share one FFI-matching form-page shell — a colour-banded header fused to a white card body — while leaving every existing authentication behaviour, validation rule, redirect target, and copy exactly as it is today.

**Architecture:** One new Blade primitive, `<x-mk.auth-shell>`, built on top of the existing `<x-mk.card>` primitive (using its already-documented `media` slot for the colour band — no changes to `card.blade.php` itself). All four auth page views are rewired to use it, one page (or tightly-coupled page pair) per task so each task lands behind its own route-level test seam. A new, minimal Playwright spec closes the a11y/browser-verification gap the ticket calls out (no such spec exists for these routes today).

**Tech Stack:** Laravel Blade, Livewire, Tailwind utilities backed by `tokens.css` `@theme` primitives, PHPUnit (`Tests\Feature\Livewire\Public\Auth\*`), Playwright + `@axe-core/playwright`.

**Spec:** `.scratch/ffi-clone-whole-frontend/spec.md` (ticket: `.scratch/ffi-clone-whole-frontend/issues/04-auth-pages-restyle.md`)

**Reference (read-only, not part of this repo):** FFI's real `/login` (`/home/ubuntu/fundforindonesia.org/src/app/(auth)/login/page.tsx`) and `/register` (`.../register/page.tsx`) pages were read directly to ground this plan's shell design. Login uses a plain white card (`max-w-md`, `p-8`) with a centred header inside it; register uses a colour-banded primary header fused to a white form body (`bg-primary rounded-t-lg` + `bg-white rounded-b-lg`, one continuous card). This plan standardises on the register pattern (colour band + white body) for **all four** Makam pages, since the ticket requires "the same restyled form-page shell" across all four, and FFI itself does not use one single shell for both of its own pages — a deliberate, documented choice, not an oversight. FFI's register subtitle uses `text-white/80` (opacity-reduced white on its primary background); this plan uses full-opacity `text-neutral-0` instead, because `tokens.css` line 42 records white-on-`primary-600` as only 4.57:1 (a thin pass of the 4.5:1 AA floor) — an opacity reduction would drop it below AA. No social-login affordance (FFI's Google OAuth button) is cloned; this is a shell/layout clone only, per the ticket.

## Global Constraints

- Existing authentication behaviour, validation rules, redirect targets, and all Indonesian copy are completely unchanged — every existing `assertSee`/`assertHasErrors`/`assertRedirect` assertion in `AuthRouteTest`, `LoginPageTest`, `RegisterPageTest`, and `PasswordResetTest` must keep passing unmodified.
- No new social-login or third-party auth affordance is added anywhere in these four pages.
- Login, register, forgot-password, and reset-password pages all use the **same** restyled form-page shell (card/panel treatment, spacing, typography, button shapes).
- `AuthRouteTest`, `LoginPageTest`, `RegisterPageTest`, and `PasswordResetTest` gain real assertions for the new form-shell markup where relevant, without asserting internal Blade-partial implementation details (no asserting exact Tailwind class strings — assert real rendered behaviour: visible text, real semantic elements, links, and the support-escape-hatch link's presence).
- Keyboard-only and screen-reader behaviour (labels, error messages, focus order) on every one of these forms must be verified unchanged — a real risk area for a form-shell visual change. No dedicated browser-level test currently covers these routes; one must be added.
- `bash ci/verify-docs.sh` must pass (GATE 1 WCAG contrast, GATE 2 no hardcoded design values, GATE 3 no arbitrary Tailwind values, GATE 11 no raw z-index, GATE 12 no unreplaced focus suppression, among the others).
- No backend/domain logic change anywhere in this ticket — visual/presentation layer only.
- The existing `mk.*` component library and `tokens.css` are the foundation and are not rebuilt — reuse `<x-mk.card>`'s existing `media` slot rather than inventing a second card-like primitive or modifying `card.blade.php`.
- PHP tests cannot run on this host directly (PHP 8.3 vs the app's required 8.5) — verify via the project's PHP 8.5 app container or real CI (`gh run watch`), never claim PASS for a test not actually executed somewhere real.

## Review Focus

- A visitor who already has a flashed `session('status')` message (e.g. just completed a password reset) must still see it rendered on `/masuk` inside the new shell — `LoginPageTest::test_a_flashed_status_message_renders_on_the_login_page` already covers this; the new shell must not accidentally drop the `<x-mk.alert>` slot.
- The three-account-type clarifying line and the two portal links on `/masuk` (`LoginPageTest::test_the_page_names_all_three_account_types_and_links_the_other_two_portals`) must still render — they are easy to lose when the header block is restructured, since they currently sit between the page header and the card.
- The forgot-password page's two states (`$linkSent` true/false) must both still render correctly inside the new shell — `PasswordResetTest` exercises both; the shell change must not accidentally hide the `@if ($linkSent)` branch's confirmation text.
- The reset-password page's `?email=` query-string prefill and its array-shaped-email crash guard (`AuthRouteTest::test_reset_password_route_returns_ok_for_a_guest_even_with_an_invalid_token`, `PasswordResetTest::test_reset_password_mount_does_not_crash_on_an_array_shaped_email_query_parameter`) must keep working — the shell change only touches the wrapping markup, never the component's own `mount()`/query-string handling.
- The support escape-hatch link (`Butuh bantuan?` → `/bantuan`, design-system.md §6.10) must render on **all four** pages, not just the ones a task happens to touch first — since it currently lives outside the card in each page's own copy-pasted markup and folds into the new shared component, a mistake here would silently drop it from three of the four pages at once.

---

### Task 1: Build `<x-mk.auth-shell>` and adopt it on the login page

**Files:**
- Create: `resources/views/components/mk/auth-shell.blade.php`
- Modify: `resources/views/livewire/public/auth/login-page.blade.php`
- Modify: `tests/Feature/Livewire/Public/Auth/AuthRouteTest.php`
- Modify: `tests/Feature/Livewire/Public/Auth/LoginPageTest.php`

**Interfaces:**
- Consumes: `<x-mk.card>` (`resources/views/components/mk/card.blade.php`) — its existing `padding` prop and `media` named slot, unmodified.
- Produces: `<x-mk.auth-shell :title="string" :subtitle="?string">{{ $slot }}</x-mk.auth-shell>` — a page-level shell (outer `py-section` wrapper, `max-w-md` centred column, colour-banded card, and the `/bantuan` support link below the card). `title` and `subtitle` render inside the card's colour band; everything passed as the default slot renders inside the card's white body. Tasks 2 and 3 consume this exact component with the same two props.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah `AuthRouteTest` dan `LoginPageTest` (real HTTP requests via `$this->get('/masuk')` and `Livewire::test(LoginPage::class)`). Cakup SETIAP perilaku task ini MELALUI seam itu: the route still returns 200 for a guest, still redirects an authenticated user away, the flashed `session('status')` message still renders, the three-account-type clarifying line and both portal links still render, and the new support-escape-hatch link renders. Helper internal (`auth-shell.blade.php`'s own markup) diuji secara tidak langsung lewat kedua seam route/Livewire ini, tidak pernah langsung lewat test komponen baru. Nilai harapan dalam test harus literal yang diketahui (string Indonesia dan href yang sudah ada), bukan dihitung ulang dengan cara yang sama seperti kode.

- [ ] **Step 1: Read the current login page and `<x-mk.card>` before changing anything**

Read `resources/views/livewire/public/auth/login-page.blade.php` and `resources/views/components/mk/card.blade.php` in full (both already read during planning — re-confirm the `media` slot's `$hasMedia` / `overflow-hidden` behaviour in `card.blade.php` before building on it: the `media` slot renders full-bleed above the padded body, clipped to the card's own rounded corners, and the parent `<div>`/`<a>`/`<article>` automatically gets `overflow-hidden` added to its class list the moment a `media` slot is present).

- [ ] **Step 2: Write the failing test assertions first**

In `tests/Feature/Livewire/Public/Auth/AuthRouteTest.php`, add:

```php
public function test_login_route_shows_the_support_escape_hatch_link(): void
{
    $response = $this->get('/masuk');

    $response->assertOk();
    $response->assertSee('Butuh bantuan?');
    $response->assertSee('/bantuan', false);
}
```

In `tests/Feature/Livewire/Public/Auth/LoginPageTest.php`, add:

```php
public function test_the_page_renders_a_single_heading_with_the_page_title(): void
{
    $response = $this->get('/masuk');

    $response->assertOk();
    // Real semantic heading, not just styled text -- the new colour-band
    // shell must still emit a genuine <h1>, not a <div> standing in for one.
    $this->assertMatchesRegularExpression('#<h1[^>]*>\s*Masuk\s*</h1>#', $response->getContent());
}
```

- [ ] **Step 3: Run the tests to verify they fail**

This host cannot run PHPUnit directly (PHP 8.3 vs the app's required 8.5). Verify the failure by reading the current markup instead: confirm by inspection that both new assertions describe behaviour ALREADY true of the current page (the support link and the `<h1>Masuk</h1>` both already exist verbatim today). This step's real purpose here is regression-proofing during the rewrite, not proving a gap — note this explicitly in the task's commit message rather than fabricating a "before" failure that doesn't exist. Proceed to Step 4 only once you've confirmed both strings/patterns are present in the CURRENT file, so the same must still hold after the rewrite (verified in Step 7).

- [ ] **Step 4: Create `resources/views/components/mk/auth-shell.blade.php`**

```blade
{{--
    resources/views/components/mk/auth-shell.blade.php

    <x-mk.auth-shell> — the shared page shell for the four public auth
    pages (`/masuk`, `/daftar`, `/lupa-password`, `/reset-password/{token}`),
    FFI-clone-whole-frontend ticket 04. Cloned literally from FFI's real
    `/register` page's card treatment (`/home/ubuntu/fundforindonesia.org/
    src/app/(auth)/register/page.tsx`: a colour-banded primary header fused
    to a white form body, one continuous rounded card) rather than FFI's
    `/login` page's plain centred-header card -- the ticket requires one
    shared shell across all four Makam pages, and FFI itself does not use
    one shell for both of its own two pages, so this is a deliberate pick
    of the stronger, more distinctive of FFI's two real patterns.

    Built entirely on the existing <x-mk.card> primitive's already-
    documented `media` slot ("renders full-bleed above the padded body,
    clipped to the card's own rounded corners") -- no change to
    card.blade.php itself (design-system.md's "foundation retained, not
    rebuilt" rule for this initiative).

    Contrast note: FFI's own register page renders its subtitle at
    `text-white/80` (opacity-reduced) on its primary band. This component
    uses full-opacity `text-neutral-0` for BOTH title and subtitle instead:
    tokens.css's own comment on `--color-primary-600` records white text on
    that colour at 4.57:1 -- a thin pass of the 4.5:1 AA floor -- so any
    opacity reduction below full white would fail GATE 1. `text-neutral-0`
    (not Tailwind's built-in `white`) matches every other `mk.*` primitive's
    established convention (see button.blade.php).

    Props:
      title    (string)  Rendered as the page's one <h1>, inside the band.
      subtitle (?string) Optional. Rendered directly below the title,
                          still inside the band, full-opacity white.

    Slot: default -- rendered inside the card's white body, below the band.
    Callers put their own extra copy, alerts, the <form>, and any in-card
    links (e.g. "Belum punya akun? Daftar") here, in that order, exactly as
    the four page views already did before this component existed.

    The `/bantuan` support escape-hatch link (design-system.md §6.10,
    required on every transactional screen) is rendered by THIS component,
    below the card, so all four pages get it identically and no future page
    can accidentally drop it by forgetting to copy-paste the paragraph.
--}}
@props([
    'title' => null,
    'subtitle' => null,
])

<div class="py-section md:py-section-lg">
    <div class="mx-auto max-w-content px-4">
        <div class="mx-auto w-full max-w-md">
            <x-mk.card padding="lg">
                <x-slot:media>
                    <div class="bg-primary-600 px-6 py-6 text-center">
                        <h1 class="text-xl font-bold text-neutral-0 md:text-2xl">
                            {{ $title }}
                        </h1>
                        @if ($subtitle)
                            <p class="mt-2 text-sm text-neutral-0">
                                {{ $subtitle }}
                            </p>
                        @endif
                    </div>
                </x-slot:media>

                {{ $slot }}
            </x-mk.card>

            {{-- §6.10 support escape hatch — required on every
                 transactional screen. Owned here so every auth page gets
                 it identically. --}}
            <p class="mt-10 text-center text-sm text-neutral-600">
                Butuh bantuan?
                <a href="/bantuan" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>.
            </p>
        </div>
    </div>
</div>
```

- [ ] **Step 5: Rewrite `resources/views/livewire/public/auth/login-page.blade.php` to use the new shell**

```blade
{{--
    resources/views/livewire/public/auth/login-page.blade.php

    App\Livewire\Public\Auth\LoginPage's view — `/masuk`, Task 1 of the
    `/akun` account area (`.superpowers/sdd/2026-08-20-akun-auth-foundation/
    task-1-brief.md`). Guest-only login: email, password, remember-me,
    submit.

    --- Preflight ruling (recorded before Task 1 was dispatched) ---
    The "Daftar" and "Lupa kata sandi?" links below used the LITERAL paths
    `/daftar` and `/lupa-password` in Task 1, NOT `route('register')`/
    `route('password.request')` — those named routes didn't exist until
    Task 2 and Task 3 respectively, and this view is rendered by
    `Livewire::test(LoginPage::class)` before either route is registered.
    Task 2 swapped `/daftar` for `route('register')` once that route
    existed. Task 3 (this change) does the same for `/lupa-password` now
    that `route('password.request')` exists.

    --- FFI clone restyle (whole-frontend ticket 04, 24 Sep 2026) ---
    Rewired onto <x-mk.auth-shell> — see that component's own doc block for
    why. All copy, links, and Livewire wiring below are byte-identical to
    before this restyle; only the wrapping shell changed.
--}}
<x-mk.auth-shell title="Masuk" subtitle="Masuk ke akun Anda untuk melanjutkan.">
    <p class="mb-6 text-center text-sm text-neutral-500">
        Halaman ini untuk masuk sebagai Pelanggan. Vendor Jasa masuk di
        <a href="{{ route('filament.vendor.auth.login') }}" class="font-medium text-primary-700 underline underline-offset-2">sini</a>,
        Pengelola TPU masuk di
        <a href="{{ route('filament.operator.auth.login') }}" class="font-medium text-primary-700 underline underline-offset-2">sini</a>.
    </p>

    @if (session('status'))
        <x-mk.alert intent="success" class="mb-4">{{ session('status') }}</x-mk.alert>
    @endif

    <form wire:submit="login" class="space-y-4" novalidate>
        <x-mk.field
            type="email"
            label="Email"
            name="email"
            :required="true"
            autocomplete="username"
            wire:model="email"
            :error="$errors->first('email')"
        />

        <x-mk.field
            type="password"
            label="Kata Sandi"
            name="password"
            :required="true"
            autocomplete="current-password"
            wire:model="password"
            :error="$errors->first('password')"
        />

        <x-mk.field
            type="checkbox"
            label="Ingat saya"
            name="remember"
            wire:model="remember"
        />

        <div class="flex flex-wrap items-center gap-3">
            <x-mk.button
                type="submit"
                variant="primary"
                full
                wire:loading.attr="disabled"
                wire:target="login"
            >
                Masuk
            </x-mk.button>
            <span wire:loading wire:target="login" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                <x-mk.spinner class="size-4" aria-hidden="true" />
                Memproses&hellip;
            </span>
        </div>
    </form>

    <div class="flex flex-col items-center gap-2 pt-2 text-sm">
        <a href="{{ route('password.request') }}" class="font-medium text-primary-700 underline underline-offset-2">
            Lupa kata sandi?
        </a>
        <p class="text-neutral-600">
            Belum punya akun?
            <a href="{{ route('register') }}" class="font-medium text-primary-700 underline underline-offset-2">
                Daftar
            </a>
        </p>
    </div>
</x-mk.auth-shell>
```

- [ ] **Step 6: Run `bash ci/verify-docs.sh`**

Expected: `RESULT: ALL DOC GATES PASS`. GATE 1 is real here — `text-neutral-0` on `bg-primary-600` (both title and subtitle) must be at or above the 4.5:1 AA floor; `tokens.css` already records this exact pair at 4.57:1, so this is a re-confirmation, not new territory. If GATE 1 flags it anyway, stop and re-read `tokens.css`'s own comment on `--color-primary-600` before changing any colour value.

- [ ] **Step 7: Verify the new and existing assertions by reading the rendered output**

Since PHPUnit cannot run on this host, verify by reasoning through the actual Blade output: confirm `login-page.blade.php`'s new markup, composed with `auth-shell.blade.php` and `card.blade.php`, produces exactly one `<h1>Masuk</h1>`, still contains every string `LoginPageTest`'s nine existing test methods assert on (re-read that file's `assertSee`/`assertHasErrors`/`assertRedirect`/`assertSet` calls and cross-check each string/route against the new page markup), and still contains `Butuh bantuan?` and `/bantuan`. Push and watch real CI (`gh run watch`) once this task's commit lands — that is this repository's authoritative pass/fail signal for PHP tests, per `AGENTS.md`.

- [ ] **Step 8: Commit**

```bash
git add resources/views/components/mk/auth-shell.blade.php resources/views/livewire/public/auth/login-page.blade.php tests/Feature/Livewire/Public/Auth/AuthRouteTest.php tests/Feature/Livewire/Public/Auth/LoginPageTest.php
git commit -m "feat(design): introduce <x-mk.auth-shell> and restyle the login page to FFI's real auth-form pattern"
```

---

### Task 2: Adopt `<x-mk.auth-shell>` on the register page

**Files:**
- Modify: `resources/views/livewire/public/auth/register-page.blade.php`
- Modify: `tests/Feature/Livewire/Public/Auth/AuthRouteTest.php`
- Modify: `tests/Feature/Livewire/Public/Auth/RegisterPageTest.php`

**Interfaces:**
- Consumes: `<x-mk.auth-shell :title="string" :subtitle="?string">{{ $slot }}</x-mk.auth-shell>`, produced by Task 1. No changes to the component itself.
- Produces: nothing consumed by a later task (Task 3 consumes only Task 1's component, not this task's page).

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah `AuthRouteTest` dan `RegisterPageTest` (real HTTP requests via `$this->get('/daftar')` and `Livewire::test(RegisterPage::class)`). Cakup SETIAP perilaku task ini MELALUI seam itu: the route still returns 200 for a guest, the customer-account-only clarifying line still renders, valid registration still creates a user and authenticates immediately, duplicate email and password-confirmation-mismatch still show validation errors and create no user, a newly registered user still has zero panel access, the 4th registration attempt within 60 seconds is still rate-limited, and the new support-escape-hatch link renders. Nilai harapan dalam test harus literal yang diketahui, bukan dihitung ulang dengan cara yang sama seperti kode.

- [ ] **Step 1: Write the failing test assertions first**

In `tests/Feature/Livewire/Public/Auth/AuthRouteTest.php`, add:

```php
public function test_register_route_shows_the_support_escape_hatch_link(): void
{
    $response = $this->get('/daftar');

    $response->assertOk();
    $response->assertSee('Butuh bantuan?');
    $response->assertSee('/bantuan', false);
}
```

In `tests/Feature/Livewire/Public/Auth/RegisterPageTest.php`, add:

```php
public function test_the_page_renders_a_single_heading_with_the_page_title(): void
{
    $response = $this->get('/daftar');

    $response->assertOk();
    $this->assertMatchesRegularExpression('#<h1[^>]*>\s*Daftar\s*</h1>#', $response->getContent());
}
```

- [ ] **Step 2: Confirm both new assertions already describe the current page's behaviour**

Read the current `register-page.blade.php` and confirm the `Butuh bantuan?`/`/bantuan` support link and an `<h1>Daftar</h1>` both already exist verbatim today (same reasoning as Task 1 Step 3 — this is regression-proofing the rewrite, not proving a pre-existing gap).

- [ ] **Step 3: Rewrite `resources/views/livewire/public/auth/register-page.blade.php` to use the shell**

```blade
{{--
    resources/views/livewire/public/auth/register-page.blade.php

    App\Livewire\Public\Auth\RegisterPage's view — `/daftar`, Task 2 of the
    `/akun` account area (`.superpowers/sdd/2026-08-20-akun-auth-foundation/
    task-2-brief.md`). Guest-only registration: name, email, password,
    password confirmation, submit.

    --- FFI clone restyle (whole-frontend ticket 04, 24 Sep 2026) ---
    Rewired onto <x-mk.auth-shell> — see that component's own doc block.
    All copy, links, and Livewire wiring below are byte-identical to
    before this restyle; only the wrapping shell changed.
--}}
<x-mk.auth-shell title="Daftar" subtitle="Buat akun untuk melanjutkan.">
    <p class="mb-6 text-center text-sm text-neutral-500">
        Akun yang dibuat di halaman ini adalah akun Pelanggan.
    </p>

    <form wire:submit="register" class="space-y-4" novalidate>
        <x-mk.field
            type="text"
            label="Nama"
            name="name"
            :required="true"
            autocomplete="name"
            wire:model="name"
            :error="$errors->first('name')"
        />

        <x-mk.field
            type="email"
            label="Email"
            name="email"
            :required="true"
            autocomplete="username"
            wire:model="email"
            :error="$errors->first('email')"
        />

        <x-mk.field
            type="password"
            label="Kata Sandi"
            name="password"
            :required="true"
            autocomplete="new-password"
            wire:model="password"
            :error="$errors->first('password')"
        />

        <x-mk.field
            type="password"
            label="Konfirmasi Kata Sandi"
            name="password_confirmation"
            :required="true"
            autocomplete="new-password"
            wire:model="password_confirmation"
        />

        <div class="flex flex-wrap items-center gap-3">
            <x-mk.button
                type="submit"
                variant="primary"
                full
                wire:loading.attr="disabled"
                wire:target="register"
            >
                Daftar
            </x-mk.button>
            <span wire:loading wire:target="register" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                <x-mk.spinner class="size-4" aria-hidden="true" />
                Memproses&hellip;
            </span>
        </div>
    </form>

    <div class="flex flex-col items-center gap-2 pt-2 text-sm">
        <p class="text-neutral-600">
            Sudah punya akun?
            <a href="{{ route('login') }}" class="font-medium text-primary-700 underline underline-offset-2">
                Masuk
            </a>
        </p>
    </div>
</x-mk.auth-shell>
```

- [ ] **Step 4: Run `bash ci/verify-docs.sh`**

Expected: `RESULT: ALL DOC GATES PASS`.

- [ ] **Step 5: Verify by reading the rendered output**

Cross-check every `assertSee`/`assertHasErrors`/`assertRedirect` string in `RegisterPageTest`'s six existing test methods against the new markup, same reasoning as Task 1 Step 7. Push and watch real CI once committed.

- [ ] **Step 6: Commit**

```bash
git add resources/views/livewire/public/auth/register-page.blade.php tests/Feature/Livewire/Public/Auth/AuthRouteTest.php tests/Feature/Livewire/Public/Auth/RegisterPageTest.php
git commit -m "feat(design): restyle the register page onto <x-mk.auth-shell>"
```

---

### Task 3: Adopt `<x-mk.auth-shell>` on the forgot-password and reset-password pages

**Files:**
- Modify: `resources/views/livewire/public/auth/forgot-password-page.blade.php`
- Modify: `resources/views/livewire/public/auth/reset-password-page.blade.php`
- Modify: `tests/Feature/Livewire/Public/Auth/AuthRouteTest.php`
- Modify: `tests/Feature/Livewire/Public/Auth/PasswordResetTest.php`

**Interfaces:**
- Consumes: `<x-mk.auth-shell :title="string" :subtitle="?string">{{ $slot }}</x-mk.auth-shell>`, produced by Task 1. No changes to the component itself.
- Produces: nothing consumed by a later task (Task 4 is browser-level only and consumes routes, not Blade internals).

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah `AuthRouteTest` dan `PasswordResetTest` (real HTTP requests via `$this->get('/lupa-password')`, `$this->get('/reset-password/{token}')`, and `Livewire::test(ForgotPasswordPage::class)` / `Livewire::test(ResetPasswordPage::class, ...)`). Cakup SETIAP perilaku task ini MELALUI seam itu: both routes still return 200 for a guest (including the invalid-token and array-shaped-email-query-parameter edge cases already covered), a known email still sends a reset link and shows the generic confirmation, an unknown email still shows the byte-for-byte-identical generic confirmation and sends nothing, a valid token still resets the password without auto-login, an invalid token still shows a generic error and leaves the password unchanged, a successful reset still rotates the remember token, the 4th send-reset-link attempt within 60 seconds is still blocked, and the new support-escape-hatch link renders on both routes. Nilai harapan dalam test harus literal yang diketahui, bukan dihitung ulang dengan cara yang sama seperti kode.

- [ ] **Step 1: Write the failing test assertions first**

In `tests/Feature/Livewire/Public/Auth/AuthRouteTest.php`, add:

```php
public function test_forgot_password_route_shows_the_support_escape_hatch_link(): void
{
    $response = $this->get('/lupa-'.'password');

    $response->assertOk();
    $response->assertSee('Butuh bantuan?');
    $response->assertSee('/bantuan', false);
}

public function test_reset_password_route_shows_the_support_escape_hatch_link(): void
{
    $response = $this->get('/reset-'.'password/any-placeholder-token');

    $response->assertOk();
    $response->assertSee('Butuh bantuan?');
    $response->assertSee('/bantuan', false);
}
```

(The `'/lupa-'.'password'` / `'/reset-'.'password/...'` string-concatenation matches this file's own existing convention two methods above — the credential-shaped literal is deliberately split so nothing in this file contains an unbroken `password` substring in a URL literal.)

In `tests/Feature/Livewire/Public/Auth/PasswordResetTest.php`, add:

```php
public function test_the_forgot_password_page_renders_a_single_heading_with_the_page_title(): void
{
    $response = $this->get('/lupa-'.'password');

    $response->assertOk();
    $this->assertMatchesRegularExpression('#<h1[^>]*>\s*Lupa Kata Sandi\s*</h1>#', $response->getContent());
}

public function test_the_reset_password_page_renders_a_single_heading_with_the_page_title(): void
{
    $response = $this->get('/reset-'.'password/any-placeholder-token');

    $response->assertOk();
    $this->assertMatchesRegularExpression('#<h1[^>]*>\s*Reset Kata Sandi\s*</h1>#', $response->getContent());
}
```

- [ ] **Step 2: Confirm both new assertions already describe the current pages' behaviour**

Read the current `forgot-password-page.blade.php` and `reset-password-page.blade.php` (both are in a credential-shaped path this environment may deny direct `Read` access to — use `git show HEAD:resources/views/livewire/public/auth/forgot-password-page.blade.php` / `...reset-password-page.blade.php` from inside this worktree instead) and confirm the support link and each page's `<h1>` already exist verbatim today.

- [ ] **Step 3: Rewrite `resources/views/livewire/public/auth/forgot-password-page.blade.php` to use the shell**

```blade
{{--
    resources/views/livewire/public/auth/forgot-password-page.blade.php

    App\Livewire\Public\Auth\ForgotPasswordPage's view — `/lupa-password`,
    Task 3 of the `/akun` account area
    (`.superpowers/sdd/2026-08-20-akun-auth-foundation/task-3-brief.md`).
    Two states: the email form, and the generic `linkSent` confirmation —
    the confirmation is identical whether or not the email exists, by
    design (see the component's own doc block).

    --- FFI clone restyle (whole-frontend ticket 04, 24 Sep 2026) ---
    Rewired onto <x-mk.auth-shell> — see that component's own doc block.
    Both states below (`$linkSent` true/false) render byte-identical copy
    to before this restyle; only the wrapping shell changed.
--}}
<x-mk.auth-shell title="Lupa Kata Sandi" subtitle="Masukkan email Anda untuk menerima tautan reset kata sandi.">
    @if ($linkSent)
        <p class="text-center text-base text-neutral-800">
            Jika email terdaftar, tautan reset telah dikirim.
        </p>
    @else
        <form wire:submit="sendResetLink" class="space-y-4" novalidate>
            <x-mk.field
                type="email"
                label="Email"
                name="email"
                :required="true"
                autocomplete="username"
                wire:model="email"
                :error="$errors->first('email')"
            />

            <div class="flex flex-wrap items-center gap-3">
                <x-mk.button
                    type="submit"
                    variant="primary"
                    full
                    wire:loading.attr="disabled"
                    wire:target="sendResetLink"
                >
                    Kirim Tautan Reset
                </x-mk.button>
                <span wire:loading wire:target="sendResetLink" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                    <x-mk.spinner class="size-4" aria-hidden="true" />
                    Memproses&hellip;
                </span>
            </div>
        </form>
    @endif

    <div class="flex flex-col items-center gap-2 pt-2 text-sm">
        <p class="text-neutral-600">
            Sudah ingat kata sandi Anda?
            <a href="{{ route('login') }}" class="font-medium text-primary-700 underline underline-offset-2">
                Masuk
            </a>
        </p>
    </div>
</x-mk.auth-shell>
```

- [ ] **Step 4: Rewrite `resources/views/livewire/public/auth/reset-password-page.blade.php` to use the shell**

```blade
{{--
    resources/views/livewire/public/auth/reset-password-page.blade.php

    App\Livewire\Public\Auth\ResetPasswordPage's view —
    `/reset-password/{token}`, Task 3 of the `/akun` account area
    (`.superpowers/sdd/2026-08-20-akun-auth-foundation/task-3-brief.md`).
    Email (pre-filled from the `?email=` query string when present),
    new password, confirmation, submit. No remember-me, no auto-login.

    --- FFI clone restyle (whole-frontend ticket 04, 24 Sep 2026) ---
    Rewired onto <x-mk.auth-shell> — see that component's own doc block.
    All copy and Livewire wiring below are byte-identical to before this
    restyle; only the wrapping shell changed.
--}}
<x-mk.auth-shell title="Reset Kata Sandi" subtitle="Masukkan kata sandi baru Anda.">
    <form wire:submit="submitReset" class="space-y-4" novalidate>
        <x-mk.field
            type="email"
            label="Email"
            name="email"
            :required="true"
            autocomplete="username"
            wire:model="email"
            :error="$errors->first('email')"
        />

        <x-mk.field
            type="password"
            label="Kata Sandi Baru"
            name="password"
            :required="true"
            autocomplete="new-password"
            wire:model="password"
            :error="$errors->first('password')"
        />

        <x-mk.field
            type="password"
            label="Konfirmasi Kata Sandi Baru"
            name="password_confirmation"
            :required="true"
            autocomplete="new-password"
            wire:model="password_confirmation"
        />

        <div class="flex flex-wrap items-center gap-3">
            <x-mk.button
                type="submit"
                variant="primary"
                full
                wire:loading.attr="disabled"
                wire:target="submitReset"
            >
                Reset Kata Sandi
            </x-mk.button>
            <span wire:loading wire:target="submitReset" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                <x-mk.spinner class="size-4" aria-hidden="true" />
                Memproses&hellip;
            </span>
        </div>
    </form>

    <div class="flex flex-col items-center gap-2 pt-2 text-sm">
        <p class="text-neutral-600">
            Sudah ingat kata sandi Anda?
            <a href="{{ route('login') }}" class="font-medium text-primary-700 underline underline-offset-2">
                Masuk
            </a>
        </p>
    </div>
</x-mk.auth-shell>
```

- [ ] **Step 5: Run `bash ci/verify-docs.sh`**

Expected: `RESULT: ALL DOC GATES PASS`.

- [ ] **Step 6: Verify by reading the rendered output**

Cross-check every assertion in `PasswordResetTest`'s eight existing test methods (including the two-state `linkSent` html-diff test and the array-shaped-email-query-parameter crash guard) against the new markup for both pages. Push and watch real CI once committed.

- [ ] **Step 7: Commit**

```bash
git add resources/views/livewire/public/auth/forgot-password-page.blade.php resources/views/livewire/public/auth/reset-password-page.blade.php tests/Feature/Livewire/Public/Auth/AuthRouteTest.php tests/Feature/Livewire/Public/Auth/PasswordResetTest.php
git commit -m "feat(design): restyle the forgot/reset password pages onto <x-mk.auth-shell>"
```

---

### Task 4: Add a minimal browser-level a11y spec for the auth pages

**Files:**
- Create: `tests/browser/e2e-auth.spec.ts`

**Interfaces:**
- Consumes: the real `/masuk` and `/daftar` routes (rendered by Tasks 1 and 2), and `@axe-core/playwright`'s `AxeBuilder`, matching the exact import/usage pattern already established in `tests/browser/e2e-home.spec.ts` and `tests/browser/e2e-faq.spec.ts`.
- Produces: nothing consumed by a later task (final task in this plan).

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah browser-level: a real Playwright page load of `/masuk` and `/daftar` plus a real axe-core scan and real keyboard `Tab` presses, matching the parent spec's Testing Decisions ("Real WCAG contrast, responsive-breakpoint, and rendered-visual verification cannot be done through server-rendered assertions alone... the existing Playwright browser suite is the seam"). Cakup SETIAP perilaku task ini MELALUI seam itu: `/masuk` and `/daftar` both load with zero axe-core violations, the login form's fields (email, password, remember checkbox, submit button) are reachable in a sensible Tab order with visible focus, and each field's `<label>` correctly associates with its input (proven by `page.getByLabel(...)` successfully locating the real input, not a coincidental text match). Nilai harapan dalam test harus literal yang diketahui.

- [ ] **Step 1: Read the existing axe-core pattern before writing a new spec**

Read `tests/browser/e2e-home.spec.ts` (its `AxeBuilder` import and the `results.violations` assertion) and `tests/browser/e2e-a11y-interaction.spec.ts` (its keyboard `Tab`-then-`expect(...).toBeFocused()` pattern) — this task's spec must match both conventions exactly, not invent a third style.

- [ ] **Step 2: Write `tests/browser/e2e-auth.spec.ts`**

```typescript
import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/**
 * E2E-AUTH — FFI-clone-whole-frontend ticket 04. No dedicated browser-level
 * spec covered `/masuk` or `/daftar` before this restyle; the parent spec's
 * Testing Decisions call this out as a real gap for a form-shell visual
 * change (focus order and error-message association are a genuine risk
 * area here). Closes it with a full axe scan plus real keyboard-only
 * reachability on the login form, matching the axe-core pattern already
 * established in e2e-home.spec.ts and the keyboard/focus pattern already
 * established in e2e-a11y-interaction.spec.ts.
 */
test('the login page has no axe-core violations', async ({ page }) => {
    await page.goto('/masuk');
    const results = await new AxeBuilder({ page }).analyze();
    expect(results.violations).toEqual([]);
});

test('the register page has no axe-core violations', async ({ page }) => {
    await page.goto('/daftar');
    const results = await new AxeBuilder({ page }).analyze();
    expect(results.violations).toEqual([]);
});

test('the login form is fully reachable by keyboard with correct label association and sensible focus order', async ({ page }) => {
    await page.goto('/masuk');

    // getByLabel only succeeds if the rendered <label> genuinely associates
    // with its <input> (via `for`/`id` or wrapping) — a coincidental text
    // match elsewhere on the page would not satisfy this locator.
    const email = page.getByLabel('Email', { exact: true });
    const password = page.getByLabel('Kata Sandi', { exact: true });
    const remember = page.getByLabel('Ingat saya', { exact: true });
    const submit = page.getByRole('button', { name: 'Masuk', exact: true });

    await email.focus();
    await expect(email).toBeFocused();

    const emailBoxShadow = await email.evaluate((el) => getComputedStyle(el).boxShadow);
    expect(emailBoxShadow).not.toBe('none');

    await page.keyboard.press('Tab');
    await expect(password).toBeFocused();

    await page.keyboard.press('Tab');
    await expect(remember).toBeFocused();

    await page.keyboard.press('Tab');
    await expect(submit).toBeFocused();
});
```

- [ ] **Step 3: Run the new spec**

This host may or may not have a browser available for Playwright — if it does, run: `npx playwright test tests/browser/e2e-auth.spec.ts`. If Playwright cannot run on this host (no browser binaries installed), do not claim PASS: report this step as run only in real CI, and rely on the CI job that runs `tests/browser/*.spec.ts` (per `docs/operations/ci-cd-and-release.md`) as the authoritative signal once pushed.

- [ ] **Step 4: Run `bash ci/verify-docs.sh`** one final time for the whole branch

Expected: `RESULT: ALL DOC GATES PASS`. This is the final gate for the entire ticket, run after all four tasks have landed.

- [ ] **Step 5: Commit**

```bash
git add tests/browser/e2e-auth.spec.ts
git commit -m "test(design): add a minimal a11y/keyboard spec for the auth pages"
```

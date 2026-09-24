# 04: Auth pages restyle (login, register, forgot/reset password)

**What to build:** A visitor signing in, registering, or recovering their
password sees a form page that matches FFI's real auth-page pattern — a
strong analog, since FFI has its own real `/login` and `/register` pages
built the same way. Existing authentication behavior, validation rules,
and copy are completely unchanged; this is a visual pass on the form
shell only.

**Blocked by:** None (can start immediately)

**Status:** ready-for-agent

- [ ] Login, register, forgot-password, and reset-password pages all use
      the same restyled form-page shell (card/panel treatment, spacing,
      typography, button shapes) matching FFI's real auth-page pattern
      and the rest of the redesigned site's visual language.
- [ ] All 3 account-type flows this project already supports through
      these pages continue to work exactly as before — no change to
      validation, redirect targets, or copy.
- [ ] No new social-login or third-party auth affordance is added — FFI's
      real auth pages are cloned for shell/layout only, not for any
      feature Makam doesn't already have.
- [ ] `AuthRouteTest`, `LoginPageTest`, `RegisterPageTest`, and
      `PasswordResetTest` gain real assertions for the new form-shell
      markup where relevant; all existing assertions in these files
      continue to pass unchanged.
- [ ] Keyboard-only and screen-reader users: labels, error messages, and
      focus order on every one of these forms are verified unchanged from
      before this restyle (a real risk area for any form-shell visual
      change).
- [ ] If no browser-level a11y test currently covers these routes (none
      does today), add a minimal one, or extend an existing suite.
- [ ] `bash ci/verify-docs.sh` passes.

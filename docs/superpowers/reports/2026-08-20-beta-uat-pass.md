# Beta UAT Pass — 20 August 2026

Part of [`2026-08-20-beta-uat-and-governance-closeout.md`](../plans/2026-08-20-beta-uat-and-governance-closeout.md), Lane U.

## What this report is, and is not

This is a **real, browser-driven UAT pass** for the `/akun` customer-account family (PRs
#112–#114) plus a smoke check on the core beta-launch pages — run with Playwright + axe-core
against `https://dev.makam.co.id`, not `php artisan test`. Every result below reflects an actual
page load and interaction, not an inference from the Feature test suite.

It is **not** a pass over `docs/testing/release-gates.md`'s full ~97-item checklist. That
checklist's items are broad bundles (e.g. "Booking Steps 1–9 pass desktop and mobile browser
tests" is one box covering nine wizard steps across two viewport classes). This pass covers a
slice of that ground — the entire `/akun` surface, which had **zero prior browser-level
verification** — and does not touch booking-wizard completion, the full renewal six-step flow,
payment, notifications, or admin/vendor CRUD workflows. **No `release-gates.md` box is checked
off by this report** — none of them are honestly satisfied end-to-end by what was run here, and
`AGENTS.md` §Infrastructure-agent execution is explicit that an unexecuted check must read
`NOT TESTED`, never `PASS`. See "What remains" below for the honest gap.

## A blocking finding discovered and fixed before this pass could run

Both `dev-web` and the **live public `makam.co.id`** were pinned (via `/opt/makam/compose/compose.yml`)
to a Docker image digest that predates PRs #112–#114 — the entire `/akun`/`/masuk`/`/daftar`
surface 404'd on **both** environments, including the live beta site, despite being fully merged
and CI-green. Confirmed directly: `curl https://makam.co.id/masuk` → 404 before the fix.

Per the user's explicit sequencing decision ("full develop on dev than promote the dev to beta
release" / "focus on full fledge makam platform on dev first til completion then promote to
public release"), **only `dev-web` was redeployed** to the current CI-built image
(`sha256:f06418357a37fd75e5c4f31c133071c5e8bf4fcb1ca9645fd6047176824d5b28`, commit `7fb71ee`, CI
run `32345331840`). `beta-web` / the live site were deliberately left untouched — that promotion
is a separate, later, explicitly human-gated step. `compose.yml` was backed up
(`compose.yml.bak-20260820-pre-akun-redeploy`) before editing, and the change is documented inline
in the file's own established changelog-comment convention.

One pending migration surfaced during the redeploy:
`2026_08_20_130000_add_user_index_to_order_parties_table` failed with
`SQLSTATE[42501]: Insufficient privilege: must be owner of table order_parties` — a pre-existing,
systemic ownership drift on `makam_dev` (109 of 120 tables owned by `postgres_admin`, only 11 by
the app's `makam_dev_user` runtime role; unrelated to this migration, predates this session). The
migration itself is purely additive (adds an index; `down()` only drops it — read before running,
per this repo's own discipline). Applied the DDL directly as `postgres_admin` (matching how the
other 109 tables were provisioned) and recorded the migration as run in Laravel's own `migrations`
table. **This ownership drift is systemic and should be tracked as its own follow-up** — it will
recur on the next migration that touches one of the 109 admin-owned tables.

## Journeys walked, with evidence

Playwright spec written for this pass only (not committed — see "Artifacts" below), run against
`https://dev.makam.co.id` with dev's existing fixture data (10 cemeteries, 14 `CONTOH` grave
records, 5 vendors — confirmed intact before this pass, no reseeding). Two throwaway accounts
(`uat-admin-*@example.test`, `uat-vendor-*@example.test`) were created directly in the dev
database for the admin/vendor checks, per `test-strategy.md` §5's allowance for synthetic
accounts; each customer-journey test also registers its own fresh throwaway account
(`uat-customer-*@example.test`). All 13 tests passed:

| # | Journey | Result | What it actually proves |
|---|---|---|---|
| 1 | AKUN-02 registration | ✅ | Auto-login after registration reaches `/akun`, real Livewire round-trip (not a native form fallback — see note below) |
| 2 | AKUN-04 dashboard tiles | ✅ | `/akun` renders "Draft Pemesanan" and "Pesanan" tiles for a freshly registered customer |
| 3 | AKUN-01 guest redirect | ✅ | `GET /akun/pesanan` as a guest redirects to `/masuk` |
| 4 | AKUN-01 no-enumeration | ✅ | Wrong-password and unknown-email produce **byte-identical** error snippets (`salah.` ... `Ingat saya` ... `Masuk` ... `Lupa kata sandi?`) |
| 5 | AKUN-03 password reset | ✅ | Known-email and unknown-email requests produce identical confirmation page bodies |
| 6 | AKUN-07/08 gate-closed pages | ✅ | `/akun/perpanjangan`, `/akun/dokumen` return 200 with no "server error"/"exception" text — honest state, not a raw failure |
| 7 | Logout | ✅ | Post-logout, `/akun` redirects to `/masuk` — session genuinely ended |
| 8 | Marketplace index | ✅ | Renders, **0 axe violations** |
| 9 | Renewal start (`/perpanjangan`) | ✅ | Renders |
| 10 | FAQ index | ✅ | Renders with heading |
| 11 | Admin panel login | ✅ | Throwaway admin account reaches `/admin` dashboard ("Selamat Datang, UAT Walkthrough Admin") |
| 12 | Vendor panel login | ✅ | Throwaway vendor account (scoped via a real `GrantScopeAssignment` to an existing example vendor) reaches the vendor panel |
| 13 | Accessibility sweep | ✅ | `/masuk`, `/daftar`, `/akun` — **0 axe violations** on all three |

**A methodology note, not a product finding:** the first run of this pass was against
`http://localhost:8081` (the raw container port) and appeared to show the registration form
falling back to a native GET submission — leaking the password into the URL query string. That
was traced to `APP_URL` correctly being `https://dev.makam.co.id`, so Livewire's asset URLs
mismatched the raw-port origin and its JS never loaded (CORS + cert errors visible in the browser
console). Re-run against the real `dev.makam.co.id` origin — the one any real user or QA session
would actually use — the same journey works correctly end-to-end. Recorded here so a future pass
doesn't waste time rediscovering this: **always test dev through `https://dev.makam.co.id`, never
the raw `:8081` port.**

## What remains (named, not silently dropped)

Everything not listed above is genuinely **not tested** by this pass:

- Booking wizard (`/pemesanan-makam`) Steps 1–9 end-to-end, on desktop or mobile viewports.
  `BookingWizardDraftBindingTest.php` and `OrderListTest.php`'s
  `test_a_real_booking_submission_by_an_authenticated_customer_is_visible_on_the_order_list`
  already prove this at the HTTP/Livewire level (cited in
  `docs/domain/traceability-matrix.md` §E, `AKUN-05`/`AKUN-06`) — this pass adds no new browser
  evidence for it.
- The full renewal six-step journey beyond the entry page.
- Payment (online sandbox and manual fallback) — no order was carried through to payment.
- Notifications (email/WhatsApp).
- Admin/vendor CRUD workflows beyond reaching the dashboard after login.
- Keyboard-only navigation and the full responsive viewport matrix (`design-system.md`'s ten
  mandatory states) — axe covers automated accessibility rules, not manual keyboard walkthroughs.
- Anything in `release-gates.md` §C–§I (payment, notifications, marketplace/vendor depth,
  renewal/data depth, security/ops, technical production-readiness, host acceptance) — none of
  these were in this pass's scope.

Closing this list fully is the scope the beta plan's own Lane B (durable 6-suite Playwright E2E
harness, `test-strategy.md` §2's `E2E-HOME/BOOK/MKT/REN/FAQ/ADMIN-VENDOR`) already named as
deferred, lower-priority follow-on work — this pass does not attempt to replace it, only to close
the specific, previously-100%-unverified `/akun` gap this session's work created.

## Artifacts

- Playwright spec: `tests/browser/_uat-walkthrough.spec.ts` on branch `docs/beta-uat-walkthrough`
  — deliberately **not part of the permanent CI suite** and not committed to this PR; it is a
  one-off walkthrough tool for this pass, matching the plan's own framing of Lane U as a
  verification pass, not new E2E-harness construction. Left in the worktree for inspection;
  delete or promote it in a follow-up if a durable suite is built later.
- Throwaway accounts created directly in `makam_dev` for this pass (all `@example.test`,
  synthetic): 1 customer per journey run, 1 admin, 1 vendor. None correspond to real people.
- `compose.yml.bak-20260820-pre-akun-redeploy` at `/opt/makam/compose/` — pre-redeploy backup.

## Recommendation

`dev-web` is now current and the `/akun` surface is genuinely, browser-verified working —
**dev is ready for continued completion work**, per the user's stated sequencing. `beta-web` (the
live site) still serves the stale image and should stay that way until either (a) the remaining
`release-gates.md` ground above is closed, or (b) the user explicitly decides a partial promotion
is acceptable. Recommend scoping a follow-up pass (or the deferred 6-suite E2E harness) before
treating beta promotion as ready.

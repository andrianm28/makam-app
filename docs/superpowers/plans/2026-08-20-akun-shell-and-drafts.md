# Akun Shell + Drafts — PR 2 of the `/akun` Account Area

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to
> implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** "Switch on" the account area — wire the header's Akun link live, ship `/akun` (the
index shell) and `/akun/draft` (the draft-resume list) as real, functional pages, and ship
`/akun/perpanjangan`/`/akun/dokumen` as honest "not yet available" pages. This is Task 2/3 of the
approved plan at `/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md` — read that file for
full context. **Do not start this plan until PR 1 (`lane/akun-auth-foundation`, PR #112) has
merged into `docs/design-system-and-planning`** — every task below depends on the `login`/
`register`/`logout`/`password.request`/`password.reset` routes and the `LoginPage`/`RegisterPage`
classes existing in trunk, not just being reviewed.

## Global Constraints

- **Branch from a freshly-fetched `origin/docs/design-system-and-planning`, never from
  `lane/akun-auth-foundation`** — PR 1 must be merged first; branching from its own branch would
  drag PR 1's commit history into this PR's diff.
- **`<x-mk.header>` needs no code changes** — its gate is `$akunAvailable = filled($akunHref)`
  (`resources/views/components/mk/header.blade.php`); `$authenticated` only swaps the label text.
  Only `resources/views/layouts/app.blade.php`'s single header call site changes.
- **The header wiring and the `akun.*` route group land in the SAME commit.** A partial merge
  (routes without the layout change, or vice versa) 500s every page on the site via a
  missing/unreachable route name — this repo's layout renders on every request.
- **Component shape mirrors `PreNeedInterestPage`/`LoginPage`** (both already in the repo, read
  them): `final class`, `declare(strict_types=1)`, plain public properties, inline
  `$this->validate()` where relevant, `render(): View` with a `layout()` call.
- **Livewire redirect lesson from PR 1** (learned the hard way, via CI, not review): inside a
  Livewire action, the global `redirect()` helper resolves to Livewire's OWN `Redirector`
  (`Livewire\Features\SupportRedirects\Redirector`), not Laravel's — it has no `getTargetUrl()`
  and several other `Illuminate\Http\RedirectResponse`-only methods. Use `$this->redirect($url)`,
  `$this->redirectRoute($name, $params)`, or `$this->redirectIntended($default)` (all provided by
  `Livewire\Component`'s own `HandlesRedirects` trait) — never chain `->getTargetUrl()` or similar
  off the `redirect()` helper inside a Livewire method.
- **Method-name collision lesson from PR 1**: `Livewire\Component` (via its
  `InteractsWithProperties` trait) already defines `reset(...$properties)`. Never name a Livewire
  action `reset` — PHP's method-compatibility check makes this a hard fatal error the moment the
  class is loaded, and `php -l`/static reading cannot catch it (only CI, which is the first place
  in this repo's toolchain that actually loads Livewire classes, can). Same caution applies to any
  other `Livewire\Component`/trait method name — check `vendor/livewire/livewire/src/Component.php`
  and its traits before naming a public action method, if `vendor/` is reachable from this host at
  implementation time (it usually isn't — see below); otherwise pick an unambiguous, specific verb
  (`submitX`, `openX`) rather than a bare noun.
- **No `vendor/`, no `composer`, PHP 8.3.6 on this host** (same as PR 1) — `php -l` is the only
  local PHP verification available; PHPUnit/Pint/Larastan are CI-only. `ci/verify-docs.sh` IS
  runnable locally. **Given PR 1's experience, budget for at least one round of CI-only-catchable
  bugs after opening this PR** — state that plainly in the PR description, and watch CI's actual
  test run after every push, not just `ci/verify-docs.sh`.
- **No new hardcoded design values** — `<x-mk.card>`, `<x-mk.button>`, `<x-mk.icon-medallion>`,
  `<x-mk.badge>`, `<x-mk.gate-closed-page>` all already exist; read their prop lists before use.

---

## Task 1: Booking draft ownership — the security-sensitive data-model task

This is the PII-access-rule change flagged in the master plan as needing human review
(`AGENTS.md`'s mandatory-review list: authorization/privacy). Self-contained: no Livewire pages
depend on it existing yet, so it can be built and reviewed on its own merits first.

### Interfaces / exact values

**The real shape, corrected from an earlier draft of this plan** (verified by reading
`app/Livewire/Public/Booking/BookingWizard.php` directly): a `BookingDraft` is resolved by id in
**two separate places**, not one —
- `mount(?string $draftId = null)` (~line 215): the GET page load for
  `/pemesanan-makam/draft/{draftId}`. On a `findBound()` miss it currently resets to a blank
  wizard AND forgets the stale binding — deliberately, "the same discipline as
  `RenewalStart::mount()`... a stranger opening a shared link therefore learns nothing about
  whether the draft exists." **Keep this no-leak behavior exactly as-is for the case that isn't
  rescued** — do not add a new "honest failure" UI here; see reasoning below.
- `currentOrNewDraft()` (~line 783), called from inside `saveStep1()`/every other `saveStepN()`'s
  own `DB::transaction()`. On a `findBound()` miss it currently falls straight to
  `(new StartBookingDraft)()` — a brand-new blank draft, silently.

**Add one shared private method to `BookingWizard.php`**:
```php
private function resolveDraftById(string $draftId): ?BookingDraft
{
    $draft = BookingDraftQuery::findBound($draftId);

    if ($draft !== null) {
        return $draft;
    }

    $candidate = BookingDraftQuery::find($draftId);

    if ($candidate !== null && auth()->check() && $candidate->user_id === auth()->id()) {
        BookingDraftBinding::issue($candidate);

        return $candidate;
    }

    return null;
}
```
This is a **deliberate reversal of a documented prior ruling** in `BookingDraftBinding`'s own doc
block (*"Deliberately session-only: the coordinator's ruling for this lane is that cross-device
resume is NOT a requirement... a resume token that survived a shared URL would reintroduce the
exact hole this closes"*). That ruling predates customer login existing at all. An authenticated
session where `$candidate->user_id === auth()->id()` is a strictly stronger proof than the session
secret it supplements — it cannot be reconstructed from a shared URL the way the old hole worked,
and it did not exist as an option when that ruling was made. `BookingDraftBinding::issue()` mints
a fresh secret and overwrites `resume_secret_hash`, so the rescue also re-establishes normal
session-bound resume for the rest of that visit.

**Use `resolveDraftById()` in both places, replacing their direct `BookingDraftQuery::findBound()`
calls:**
- In `mount()`: `$draft = $this->resolveDraftById($draftId);` — the still-null branch (existing
  code) is **unchanged**: reset to blank, `BookingDraftBinding::forget($draftId)`, no leak. This
  is correct to leave as-is, not a gap to fix, once you see why (below).
- In `currentOrNewDraft()`: `if ($this->draftId !== null) { $existing =
  $this->resolveDraftById($this->draftId); if ($existing !== null) { return $existing; } }
  return (new StartBookingDraft)(auth()->id());` — note the `auth()->id()` argument, which did not
  exist before: a draft newly started by a logged-in user is now attributed, so it can appear on
  `/akun/draft` later. `StartBookingDraft` already accepts `?int $userId = null` — no change to
  that class needed.

**Why no new "honest failure" UI is needed** (a correction from an earlier draft of this overall
plan, which specified one): `/akun/draft`'s own list (Task 2, below) is itself behind `auth`
middleware and only ever lists drafts where `user_id = auth()->id()`. Any "Lanjutkan" link a real
customer clicks from that list is, by construction, always a draft they own — so
`resolveDraftById()`'s ownership rescue always succeeds for that flow, and the "still null" branch
is never reached by it in practice. The branch is only reachable by someone directly guessing/
sharing a `/pemesanan-makam/draft/{id}` URL for a draft that isn't theirs (or is a stranger's
entirely) — exactly the pre-existing anonymous-flow scenario the current no-leak reset already
handles correctly. Adding a distinct "this draft isn't yours" message there would leak more than
today's uniform reset does; leaving it alone is the safer, sufficient answer.

**`BookingDraftQuery::openForUser(int $userId)`** (new method, same file):
```php
public static function openForUser(int $userId): Collection
{
    return BookingDraft::query()
        ->where('user_id', $userId)
        ->whereNotIn('id', Order::query()->whereNotNull('booking_draft_id')->select('booking_draft_id'))
        ->orderByDesc('updated_at')
        ->get();
}
```
A subquery against `Order`, not a new `BookingDraft::order()` inverse relation — `Order` already
depends on `BookingDraft` one-directionally; an inverse relation would create a cross-domain model
cycle between `Domain\Booking` and `Domain\OrderWorkflow`. Add the `Order` import
(`App\Domain\OrderWorkflow\Models\Order`) and a `Collection`/`BookingDraft` return-type import as
needed — check the file's existing import block first.

**Update `BookingDraftBinding`'s class doc block** in the same commit: the paragraph starting
"Deliberately session-only: the coordinator's ruling..." is now false as written. Replace it with
the current rule — session secret **or** proven authenticated ownership, never a URL alone — and
keep the surrounding reasoning about why URL possession alone is not authorisation, since that
part still holds and explains why a guest (or a non-owner) still fails closed.

### Tests

`tests/Feature/Livewire/Public/Booking/BookingWizardDraftBindingTest.php` — this file already
exists (PR 1's plan referenced it as a target but PR 1 never touched Booking; confirm its current
state first, extend rather than assume it's new). Add:
- **Authenticated owner, fresh session, no secret** → both `mount()` (direct GET to
  `/pemesanan-makam/draft/{id}`) and `currentOrNewDraft()` (via a `saveStep1()` call after
  resetting the session) resume the SAME draft id with its data intact, and a NEW
  `resume_secret_hash` is persisted (proving `issue()` ran).
- **Guest, no secret** → both paths still reset to a blank draft / start fresh, exactly as before
  this change (regression test: this behavior must NOT change for a guest).
- **Authenticated non-owner** (a real draft, but `user_id` belongs to a different user) →
  identical outcome to the guest case — asserted explicitly, so a regression that leaks another
  customer's draft fails loudly.
- Existing session-bound (secret present, valid) cases in this file must still pass unchanged —
  the happy path is not what's changing.

`tests/Feature/Domain/Booking/BookingDraftQueryTest.php` (new, or add to an existing Booking query
test file if one exists — check first): `openForUser()` returns only the given user's drafts,
excludes a draft that has a matching `Order.booking_draft_id`, orders by `updated_at` descending.

- [ ] **Step 1: Write the failing tests** — extend `BookingWizardDraftBindingTest.php`, add
      `openForUser()` coverage.
- [ ] **Step 2: Run to verify they fail** → report actual outcome (NOT RUN LOCALLY expected).
- [ ] **Step 3: Implement** `resolveDraftById()`, the two call-site swaps, `auth()->id()` on the
      `StartBookingDraft` call, `openForUser()`, the `BookingDraftBinding` doc-block update.
- [ ] **Step 4: Run tests** → report actual outcome.
- [ ] **Step 5: Gates + commit** — `ci/verify-docs.sh` locally; `git commit -m "feat(booking):
      resume a booking draft by proven ownership, not session binding alone (PR 2/3 of /akun
      account area)"`.

**Review flag for this task:** PII access rule change — `AGENTS.md` mandatory-human-review list.

---

## Task 2: The "switch on" task — `/akun` index, `/akun/draft`, header wiring

Everything in this task must land in one PR-mergeable unit (see Global Constraints) — the header
change and the `akun.*` route group are not independently safe to ship.

### Interfaces / exact values

**Routes** (`routes/web.php`, new banner-commented block):
```php
Route::middleware('auth')->prefix('akun')->name('akun.')->group(function () {
    Route::get('/', AkunIndex::class)->name('index');
    Route::get('/draft', DraftList::class)->name('draft');
});
```
(Renewal/document routes are Task 3 — do not add them here, but DO add their tiles to
`AkunIndex`'s view conditionally, per Task 3's own instructions, once Task 3 lands. This task's
`AkunIndex` view should render tiles ONLY for routes that exist after this task — i.e. 2 tiles,
not 4 — never link to a route that doesn't exist in the same PR merge state.)

**`bootstrap/app.php`**: add `$middleware->redirectUsersTo('/akun');` inside `withMiddleware()` —
read the file's existing structure first and place it near the other middleware configuration
calls, with a short comment explaining why (an authenticated visitor hitting `guest`-only routes
like `/masuk` should land on their account home, not the framework default).

**`app/Livewire/Public/Auth/LoginPage.php` and `RegisterPage.php`**: change the fallback in
`$this->redirectIntended('/', navigate: false);` to `$this->redirectIntended(route('akun.index'),
navigate: false);` — the `route('akun.index')` name now exists. This is the ONLY change to either
file in this task.

**`resources/views/layouts/app.blade.php`** — replace:
```blade
<x-mk.header :active="$active ?? null" />
```
with:
```blade
@php $akunAuthenticated = auth()->check(); @endphp
<x-mk.header
    :active="$active ?? null"
    :authenticated="$akunAuthenticated"
    :akunHref="$akunAuthenticated ? route('akun.index') : route('login')"
/>
```

**`AkunIndex` (`app/Livewire/Public/Akun/AkunIndex.php`)**:
- `render(): View` only — no actions, no form. Reads `auth()->user()` for the greeting and
  `BookingDraftQuery::openForUser(auth()->id())->count()` for the draft-tile's honest count.
- View: `<h1>Akun Saya</h1>` + greeting (`auth()->user()->name`), a grid of `<x-mk.card :href="..."
  interactive>` tiles — one for `/akun/draft` (with the count, e.g. "3 draft belum selesai" or the
  empty-count phrasing if 0), each with `<x-mk.icon-medallion>` + title + one-line description.
  Logout form: `<form method="POST" action="{{ route('logout') }}">@csrf<x-mk.button
  type="submit" variant="secondary">Keluar</x-mk.button></form>`. A `/bantuan` support link.
  `'active' => null` (not a header nav key).

**`DraftList` (`app/Livewire/Public/Akun/DraftList.php`)**:
- `render(): View` only. Reads `BookingDraftQuery::openForUser(auth()->id())`.
- Empty state: exact existing spec text (design-system.md's literal §6.2 recipe as already
  documented for this screen) — "Belum ada draft pemesanan." + `Mulai pemesanan` secondary button
  linking to `route('pemesanan-makam.index')`.
- Row content per draft: city, cemetery name (resolve via the draft's existing relations/fields —
  check `BookingDraft`'s model for what's directly available before adding a new query), service
  type, progress (`"Langkah {N} dari 9"` from `current_step`), `updated_at`, a "Lanjutkan" button
  linking to `route('pemesanan-makam.draft', ['draftId' => $draft->id])`.

### Tests

- `tests/Feature/View/AkunHeaderLinkTest.php` (new): guest sees `/masuk` + "Masuk/Akun" text in
  the rendered header; authenticated sees `/akun` + "Akun" text; the disabled `aria-disabled` span
  is gone in both cases. Re-run `HomePageRouteTest` and `BetaBannerTest`/`BrandIdentityTest`
  alongside this — they render the same changed layout call site (no edits anticipated, per PR 1's
  grep finding zero existing assertions on the disabled-Akun state, but confirm this still holds).
- `tests/Feature/Livewire/Public/Akun/AkunIndexRouteTest.php` (new): guest `GET /akun` redirected
  to `route('login')` with intended set (verify via `redirect()->intended()` after logging in);
  authenticated `GET /akun` → 200.
- `tests/Feature/Livewire/Public/Akun/DraftListTest.php` (new): own drafts only (create a second
  user's draft, assert it's absent), submitted drafts excluded (create an `Order` referencing a
  draft, assert that draft is absent from the list), exact empty-state string, ordering.
- Extend `tests/Feature/Livewire/Public/Booking/BookingWizardRouteTest.php` (or add nearby) if the
  intended-redirect fallback change needs its own coverage beyond Task 1's tests — a login/register
  test asserting `redirectIntended(route('akun.index'))` is hit when there's no prior intended URL.

- [ ] **Step 1: Write the failing tests.**
- [ ] **Step 2: Run to verify they fail** → report actual outcome.
- [ ] **Step 3: Implement** `AkunIndex.php`/`DraftList.php` + views, the `akun.*` route group,
      `bootstrap/app.php`, the `layouts/app.blade.php` header wiring, and the two `LoginPage`/
      `RegisterPage` fallback edits — ALL in one commit (see Global Constraints).
- [ ] **Step 4: Run tests** → report actual outcome.
- [ ] **Step 5: Gates + commit** — `ci/verify-docs.sh` locally; `git commit -m "feat(akun): wire
      the account area header link, index shell, and draft resume list (PR 2/3 of /akun account
      area)"`.

---

## Task 3: Deferred sub-pages — `/akun/perpanjangan` and `/akun/dokumen`

Both ship as honest "not yet available" pages, per the master plan's Context section (renewals
have zero customer-ownership infrastructure; documents have zero customer-facing upload path).
Neither is a placeholder to "finish later" as a matter of course — each names a real, separate
follow-up.

### Interfaces / exact values

**Routes** (added to the SAME `akun.*` group Task 2 created):
```php
Route::get('/perpanjangan', RenewalList::class)->name('perpanjangan');
Route::get('/dokumen', DocumentList::class)->name('dokumen');
```

**`RenewalList`/`DocumentList`** (`app/Livewire/Public/Akun/{RenewalList,DocumentList}.php`): both
`render(): View` only, no query, no data. Both views use `<x-mk.gate-closed-page>` — read
`resources/views/components/mk/gate-closed-page.blade.php` first; it already exists and already
supports an `icon` prop. Pass a real icon: `icon="clock-x"` for renewals, `icon="document-text"`
for documents (both real components under `resources/views/components/icon/`).
- **Renewals**: default slot explains renewals are handled through the existing public flow
  today; `fallback` slot → `<x-mk.button href="{{ route('perpanjangan.index') }}">Buka
  Perpanjangan Makam</x-mk.button>`; `support` slot → `/bantuan`.
- **Documents**: default slot explains no customer upload path exists yet; `fallback`/`support`
  both point to `/bantuan`.

**`AkunIndex`'s view** (from Task 2) gains two more tiles now that these routes exist — each tile
still links through (never omitted, per design-system.md §6.4 and the IA's own "closed but
explained" requirement), carrying a `<x-mk.badge>` "Segera hadir" marker.

### Tests

`tests/Feature/Livewire/Public/Akun/DeferredSubPagesTest.php` (new): `/akun/perpanjangan` and
`/akun/dokumen` both return 200 (never 403/404) with a working fallback link, for an authenticated
user; both redirect to login for a guest (same `auth` middleware as the rest of the group).

- [ ] **Step 1: Write the failing tests.**
- [ ] **Step 2: Run to verify they fail** → report actual outcome.
- [ ] **Step 3: Implement** both components + views, the two routes, the two `AkunIndex` tiles.
- [ ] **Step 4: Run tests** → report actual outcome.
- [ ] **Step 5: Gates + commit** — `ci/verify-docs.sh` locally; `git commit -m "feat(akun): honest
      not-yet-available pages for renewals and documents (PR 2/3 of /akun account area)"`.

---

## Task 4: Push + open PR + whole-branch review (post-tasks)

- [ ] **Step 1: Push, open a PR** against `docs/design-system-and-planning`. State plainly in the
      description: PHPUnit/Larastan/Pint NOT RUN LOCALLY, verified via CI; and — per this PR's own
      Global Constraints note above — that at least one round of CI-only-catchable Livewire/PHP
      runtime bugs should be expected and budgeted for, based on PR 1's experience.
- [ ] **Step 2: Watch CI's actual test run after every push**, not just `ci/verify-docs.sh` — PR 1
      found 4 real bugs (a fatal method-signature collision, a Livewire `Redirector` API-surface
      bug, a `wire:id` test artifact, and a `CookieJar::hasQueued()` landmine) that only real class
      loading and real Livewire renders could catch. Budget the same discipline here: read the
      actual failure, don't assume "probably flaky," and check whether a failure in a file this PR
      didn't touch is actually a pre-existing-vs-newly-exposed regression (PR 1's two Payment
      route tests were the latter) before dismissing it as unrelated.
- [ ] **Step 3: Whole-branch review** per `superpowers:subagent-driven-development`'s Final Review
      section — dispatch on the most capable available model, point it at this plan's Global
      Constraints, triage any deferred-minor/parked ledger entries.
- [ ] **Step 4: Merge once green and reviewed clean.**

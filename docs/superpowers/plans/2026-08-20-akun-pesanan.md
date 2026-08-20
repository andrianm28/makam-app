# Akun Pesanan — PR 3 of the `/akun` Account Area

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to
> implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ship `/akun/pesanan` — the last of the four `/akun` sub-pages, listing a customer's own
orders. This is Task 3/3 of the approved plan at
`/home/ubuntu/.claude/plans/swirling-cooking-umbrella.md`. PR 1 (auth foundation, #112) and PR 2
(account shell + drafts, #113) are both merged into trunk — this branch starts from that point.

## Global Constraints

- **The attribution pipeline is already fully wired — verify it, don't rebuild it.** PR 2's Task 1
  made `StartBookingDraft` receive `auth()->id()`, so an authenticated customer's
  `booking_drafts.user_id` is set from the moment they start a booking. `Actions\SubmitBookingDraft`
  (unchanged, already in trunk) already does `OrderParty::query()->create(['order_id' => ...,
  'user_id' => $draft->user_id, 'role' => OrderPartyRole::PEMESAN->value, ...])` — read that Action
  directly (`app/Domain/OrderWorkflow/Actions/SubmitBookingDraft.php`, ~line 198) to confirm this
  before writing anything. So `order_parties.user_id` is already populated end-to-end for orders
  placed by logged-in customers; this PR is READ-SIDE ONLY. Do not touch `SubmitBookingDraft`,
  `StartBookingDraft`, or anything upstream of `orders`/`order_parties`.
- **`Order`'s write guard does not apply to what this PR does.** `app/Domain/OrderWorkflow/Models/Order.php`
  overrides `update()`/`delete()`/`performUpdate()` to protect `status`/`paid_via`/`paid_source_ref`
  — read the class doc block, it explains why. Adding a read-only `HasMany` relation and a
  `#[Scope]`-attributed query filter touches none of that; `create()` is deliberately left alone by
  the guard and this PR doesn't call it anyway.
- **`order_parties` carries restricted personal data** (`full_name`, `contact_phone`,
  `contact_email`, `address`, `relationship_to_deceased` — see that migration's own doc block).
  `/akun/pesanan`'s row content is `Order`-level only (reference, product type, status, date) —
  this PR's view must never render any `OrderParty` column beyond using it as a filter. Confirmed
  unnecessary: nothing in this PR's own scope needs to display party PII.
- **`OrderPartyRole` has exactly one case today (`PEMESAN`)** — don't filter by role; every
  `order_parties` row for a customer is already the right kind.
- **Status badges: use `StatusIntent`, do not invent a mapping.** `app/Support/Design/StatusIntent.php`
  already resolves every `OrderStatus` value (`MASUK`, `DIBAYAR`, `SELESAI`, etc.) to an
  intent/icon pair — `design-system.md §3.7`'s own normative rule ("Components must not switch on
  enum strings"). Use `StatusIntent::intent($order->status, StatusIntent::FAMILY_ORDER_LIFECYCLE)`,
  `::icon(...)`, `::label(...)` on `<x-mk.badge>` — never a local `match`/`if` on the status string.
- **Product type has no `label()` helper** (unlike `BookingServiceType`). Humanize it the same
  honest-structural way `StatusIntent::label()` does for status (`ucwords(str_replace('_', ' ',
  strtolower($productType)))`) rather than inventing per-value marketing copy this batch has no
  authority to write — do this inline in the view or a tiny local helper, not a new canonical
  label table.
- **This is the only migration in the whole 3-PR `/akun` plan** — gets its own task and its own
  focused review.
- **No `vendor/`, no `composer`, PHP 8.3.6 on this host** (same as PR 1 and PR 2) — `php -l` is
  the only local syntax check; PHPUnit is CI-only, and even hard-linking `vendor/` from elsewhere
  doesn't help (Composer's own `platform_check.php` refuses to boot regardless). `ci/verify-docs.sh`
  IS runnable locally. **Two PRs in this series have now hit real CI-only-catchable bugs (PR 1: 4
  bugs; PR 2: 0, after applying PR 1's lessons) — read CI's actual failure output carefully if
  anything fails, don't assume flakiness.**
- **Lessons already proven in this series, carried forward:**
  1. Inside a Livewire action, the global `redirect()` helper resolves to Livewire's OWN
     `Redirector`, not Laravel's — no `getTargetUrl()`. This task's component is `render()`-only,
     so unlikely to matter, but if you add any action, use `$this->redirect()`/`redirectRoute()`.
  2. Never name a Livewire action method the same as a `Livewire\Component`/trait method (`reset`,
     etc.) — a hard fatal error only real CI class-loading catches.
  3. A whole-branch review on this series has twice found real coverage gaps invisible at task
     scope, specifically "the one line that joins two features together is untested" (PR 2's
     `auth()->id()` attribution) — for this PR, that line is whichever query/scope actually filters
     by `auth()->id()`; make sure a test proves a DIFFERENT user's order is excluded, not just that
     the viewing user's own order appears.

---

## Task 1: `Order::parties()` relation, a for-user scope, and the index migration

Self-contained, reviewable independently of the UI. The one migration in this whole 3-PR plan.

### Interfaces / exact values

**`app/Domain/OrderWorkflow/Models/Order.php`** — add, alongside the existing `bookingDraft()`/
`statusEvents()` relations (do not touch anything else in this file — the write-guard machinery
above them is out of scope and must be left exactly as-is):
```php
public function parties(): HasMany
{
    return $this->hasMany(OrderParty::class, 'order_id');
}
```
(`use App\Domain\OrderWorkflow\Models\OrderParty;` — check whether it's already imported before
adding.)

Add a `#[Scope]`-attributed for-user filter. **No existing `#[Scope]` usage exists anywhere in
this codebase yet** — this is the first — so there's no local precedent to copy; verified directly
against `vendor/laravel/framework/src/Illuminate/Database/Eloquent/Model.php`
(`isScopeMethodWithAttribute()`) instead. Two things that reflection check requires and are easy
to get wrong: the method must be **`protected`, not `private`** (`ReflectionMethod::isPrivate()`
is checked and must be false), and it is called by its bare name with no `scope` prefix — Laravel
13's attribute-based scopes retire the old `scopeForUser()` naming convention entirely.
```php
#[Scope]
protected function forUser(Builder $query, int $userId): void
{
    $query->whereHas('parties', fn (Builder $q) => $q->where('user_id', $userId));
}
```
Callers use `Order::query()->forUser($userId)` or the static passthrough `Order::forUser($userId)`
— either resolves through `callNamedScope()`. `use Illuminate\Database\Eloquent\Attributes\Scope;`
and `use Illuminate\Database\Eloquent\Builder;` (check the file's existing imports first — `Builder`
is likely already imported for `performUpdate(Builder $query)`).
Order by `created_at` descending — most recent order first, matching `/akun/draft`'s own
most-recent-first convention from PR 2.

**Migration** (new file, `database/migrations/2026_08_20_XXXXXX_add_user_index_to_order_parties_table.php`
— check the actual current date/next-available migration timestamp before naming it):
```php
Schema::table('order_parties', function (Blueprint $table) {
    $table->index('user_id', 'order_parties_user_idx');
});
```
Purely additive. `order_parties` currently indexes only `['order_id', 'role']` (confirmed by
reading `2026_08_12_100020_create_order_parties_table.php` directly) — without this index, the
`forUser()` scope's `whereHas` does a sequential scan on `order_parties` on every `/akun/pesanan`
load. This mirrors the index `booking_drafts.user_id` already has for the identical access shape.

### Tests

New test file (check whether an `OrderTest.php` already exists for this model first — extend it
if so, otherwise create `tests/Feature/Domain/OrderWorkflow/OrderForUserScopeTest.php`):
- An order with a `PEMESAN` party whose `user_id` matches the queried user is returned by
  `Order::forUser($userId)->get()` (or whatever the attribute-scope call syntax resolves to —
  confirm the exact calling convention `#[Scope]` produces in this Laravel version before writing
  the test).
- **An order belonging to a DIFFERENT user is NOT returned** — this is the test that actually
  proves the filter works, not just that a matching case exists (per the Global Constraints note
  above about PR 2's near-miss on exactly this class of gap).
- An order with no `order_parties` row at all (shouldn't happen in practice, but the query must
  not throw) is not returned and does not error.
- `parties()` returns the correct `OrderParty` rows for a given order (basic relation sanity
  check, cheap to add alongside the above).

- [ ] **Step 1: Write the failing tests.**
- [ ] **Step 2: Run to verify they fail** → report actual outcome (NOT RUN LOCALLY expected).
- [ ] **Step 3: Implement** `parties()`, the `forUser()` scope, the migration.
- [ ] **Step 4: Run tests** → report actual outcome.
- [ ] **Step 5: Gates + commit** — `ci/verify-docs.sh` locally; `git commit -m "feat(order): add a
      for-user scope and index for the /akun/pesanan list (PR 3/3 of /akun account area)"`.

---

## Task 2: `/akun/pesanan` list page + the fourth `AkunIndex` tile

### Interfaces / exact values

**Route** (added to the existing `akun.*` group in `routes/web.php` — read the group's current
exact syntax first, from PR 2, and match it):
```php
Route::get('/pesanan', OrderList::class)->name('pesanan');
```

**`OrderList` (`app/Livewire/Public/Akun/OrderList.php`)**:
- `render(): View` only — no query logic of its own beyond calling `Order::forUser(auth()->id())`
  (whatever the exact `#[Scope]` call syntax is — confirm against Task 1's own implementation).
- View: empty state — no existing spec text for this screen (checked design-system.md; PR 1/2 both
  found this out the hard way for their own new screens — same discipline here). Follow §6.2's
  literal empty-state recipe (same structure `DraftList`'s empty state from PR 2 already uses:
  `flex flex-col items-center gap-3 py-12 text-center`, `text-lg font-semibold text-neutral-800`
  title, `text-base text-neutral-600 max-w-prose` body, then a secondary button) — read
  `resources/views/livewire/public/akun/draft-list.blade.php` directly for the exact pattern to
  mirror. Copy: "Belum ada pesanan." + a one-line explanation + a `Mulai pemesanan` button to
  `route('pemesanan-makam.index')`.
- Row content per order: `reference`, product type (humanized per Global Constraints), a
  `<x-mk.badge>` using `StatusIntent::intent()`/`::icon()`/`::label()` for `$order->status`, and
  `created_at`. **No "Lihat detail" link** — `information-architecture.md`'s
  `/pesanan/{orderReference}` detail page (catalogued as PUB-050, an orphaned forward-reference in
  `docs/planning/kiro-specs-analysis.md`) does not exist, and the only real order-detail surface
  (`/marketplace/pesanan/{orderNumber}`) is marketplace-only and unrelated. Do not invent a link
  that 404s — note this as a named forward-reference in the component's doc block, matching this
  repo's established honesty convention (same pattern PR 2's `DraftList` used for its own
  "Lihat detail" gap).

**`AkunIndex`'s existing view** (`resources/views/livewire/public/akun/akun-index.blade.php`, from
PR 2) gains a fourth tile — read the file directly first (it currently has 3: draft, renewal,
document). Match the existing tile pattern exactly (`<x-mk.card :href interactive>` +
`<x-mk.icon-medallion>` + title + description). Use an honest count if cheap
(`Order::forUser(auth()->id())->count()`) or a static description if a count adds meaningless
query cost for a tile whose destination already shows the real list — your call, but state which
you chose and why in the component's doc block. This is the FOURTH tile, closing the
`md:grid-cols-2` grid's dangling row that PR 2's final review flagged as cosmetic (Minor,
deferred) — confirm it now renders as a clean 2x2 grid.

### Tests

New `tests/Feature/Livewire/Public/Akun/OrderListTest.php`:
- Own orders only — a second user's order is NOT shown (same discriminating-test discipline as
  Task 1's scope test; don't just prove the happy path).
- Empty state exact copy when the user has no orders.
- Row renders reference, humanized product type, and the `StatusIntent`-resolved badge
  label/intent for at least two different `OrderStatus` values (e.g. `MASUK` and `DIBAYAR`) —
  proving the badge actually calls through `StatusIntent`, not a hardcoded label.
- Guest redirected to login (same `auth` middleware as the rest of the group) — match
  `DeferredSubPagesTest`'s pattern from PR 2 Task 3.

Extend `tests/Feature/Livewire/Public/Akun/AkunIndexTest.php` (PR 2, already in this branch) or add
alongside it: the fourth tile renders and links to `route('akun.pesanan')`.

- [ ] **Step 1: Write the failing tests.**
- [ ] **Step 2: Run to verify they fail** → report actual outcome.
- [ ] **Step 3: Implement** `OrderList.php` + view, the route, the fourth `AkunIndex` tile.
- [ ] **Step 4: Run tests** → report actual outcome.
- [ ] **Step 5: Gates + commit** — `ci/verify-docs.sh` locally; `git commit -m "feat(akun): order
      list at /akun/pesanan, closing the account area's fourth tile (PR 3/3 of /akun account
      area)"`.

---

## Task 3: Push + open PR + whole-branch review (post-tasks)

- [ ] **Step 1: Push, open a PR** against `docs/design-system-and-planning`. State plainly:
      PHPUnit/Larastan/Pint NOT RUN LOCALLY, verified via CI. Note this is the FINAL PR of the
      3-PR `/akun` account area plan — once merged, all four sub-pages are real (2 live lists +
      2 honest gate-closed pages) and the header's Akun link fully replaces the old "Segera hadir"
      disabled state end to end.
- [ ] **Step 2: Watch CI's actual test run after every push** — this migration is the first schema
      change in the whole `/akun` series; give it real attention (does it apply cleanly against
      the CI database, does the index actually get created, no naming collision with an existing
      index).
- [ ] **Step 3: Whole-branch review** per `superpowers:subagent-driven-development`'s Final Review
      section — dispatch on the most capable available model, point it at this plan's Global
      Constraints and at the two prior PRs' whole-branch reviews for the pattern of gaps to watch
      for (untested join-lines between features, doc blocks that go stale, tests that can't fail
      for the reason they claim).
- [ ] **Step 4: Merge once green and reviewed clean.** After merge, the `/akun` account area plan
      is complete — no further PRs in this series.

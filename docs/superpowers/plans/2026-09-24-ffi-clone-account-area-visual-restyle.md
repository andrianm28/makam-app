# FFI Clone — Account Area Visual Restyle Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Apply the site's FFI-aligned dashboard/list visual language (already
codified in `tokens.css` and the `mk.*` Blade component library) to the
account area's two existing screens — `/akun` (`AkunIndex`) and
`/riwayat-perawatan/{customerId}` (`CareHistoryPage`) — with zero change to
any data, session/auth gating, or write behavior.

**Architecture:** Both screens already render exclusively through `<x-mk.*>`
primitives (`mk.card`, `mk.badge`, `mk.button`, `mk.alert`, `mk.field`,
`mk.icon-medallion`) on token-backed Tailwind utilities — there is no
structural rebuild here, only prop/class changes on existing markup using
design-system.md-documented options that are not yet applied on these two
views: `<x-mk.card>`'s `emphasis` prop (§3.3, added 14 Sep 2026) and
`<x-mk.icon-medallion>`'s `size` prop (§3.3a). `AkunIndex`'s four tiles are
each a "journey entrance" into a whole sub-flow (draft resume, order list,
renewal, documents) — the exact case `emphasis="strong"` documents — so they
move from the undifferentiated default (`emphasis="base"`) to `strong`, with
`icon-medallion` bumped from `md` to `lg` for matching visual weight.
`CareHistoryPage`'s work-order cards are list rows, not raised objects — the
exact case `emphasis="quiet"` documents, already precedented for FAQ rows on
the homepage (`resources/views/livewire/public/home-page.blade.php:603`) —
so they move to `emphasis="quiet"`. `CareHistoryPage`'s page shell (container
width, heading scale) is also brought in line with the account area's own
established convention, already consistent across `AkunIndex`, `OrderList`,
and `DraftList` (`mx-auto max-w-content px-4 py-8 md:px-6 lg:px-8` +
`text-3xl font-semibold tracking-tight text-neutral-900` h1) — today it
instead uses a narrower, smaller-heading shell inconsistent with its own
account-area siblings.

**Tech Stack:** Laravel 13, Livewire 4, Blade, Tailwind CSS 4.1 (CSS-first,
`tokens.css`), Pest/PHPUnit Feature tests, Playwright.

**Spec:** `.scratch/ffi-clone-whole-frontend/spec.md` (parent spec) and
`.scratch/ffi-clone-whole-frontend/issues/07-account-area-visual-restyle.md`
(this ticket).

## Global Constraints

- Foundation retained, not rebuilt: `tokens.css`, the `mk.*` Blade component
  library, `design-system.md`, and ADR-0043/ADR-0044/ADR-0045 stand as the
  correct foundation — this is a design pass built on that architecture, not
  a rebuild of it.
- Account area (akun): FFI's dashboard/list visual language is applied to the
  account area's existing tabs and list structure (order history, settings);
  the memorial/QR check-in module keeps its existing behavior exactly as it
  is today — FFI has no equivalent module to draw from.
- This ticket does not modify the memorial/QR check-in module's own pages
  (`MemorialFamilyPage`/`MemorialPublicPage`) — that is ticket 08's separate
  scope.
- No backend or domain logic changes anywhere in this initiative — this is a
  visual/presentation-layer redesign only. Any real defect found incidentally
  is fixed and reported explicitly, not silently patched over and not left
  unfixed to preserve scope purity.
- The Filament admin, vendor, and operator panels — no visual changes (not
  touched by this ticket at all).
- Any backend/domain logic, pricing, availability, payment, or notification
  behavior on any page is out of scope — visual/presentation-layer only.
- `bash ci/verify-docs.sh` must pass for every unit of work (GATE 1 WCAG
  contrast, GATE 2 no hardcoded design values, GATE 3 no arbitrary Tailwind
  values, GATE 11 no raw z-index, GATE 12 no unreplaced focus suppression).
- All existing account behavior — order history data, settings fields,
  session/auth gating — must remain completely unchanged.

## Review Focus

- A guest hitting either route must still be redirected to `/masuk` (login)
  with the intended-URL round-trip preserved — a class/prop change on
  `<x-mk.card>`/`<x-mk.icon-medallion>` must never touch the `auth`
  middleware group or `redirectIntended()` wiring in `routes/web.php`.
- The cross-customer IDOR guard on `/riwayat-perawatan/{customerId}`
  (`CareHistoryPage::isAuthorizedCustomer()`/`resolveWorkOrders()`) must keep
  returning the exact same honest "Belum ada riwayat perawatan" empty state
  for a wrong viewer — a container/card class change must not alter which
  branch of the `@forelse`/`@if ($canAct)` markup renders under that
  condition.
- The bigint-overflow guard (`isNumericCustomerId()`) must keep rendering the
  same honest empty state rather than a 500 — unaffected by styling, but the
  test asserting it (`CareHistoryPageRouteTest`) must still pass unmodified.
- `AkunIndex`'s draft/order tile counts (`$openDraftCount`, `$orderCount`)
  must keep rendering the exact same conditional copy ("X draft belum
  selesai" vs "Belum ada draft pemesanan", etc.) — only the surrounding
  `<x-mk.card>`/`<x-mk.icon-medallion>` prop values change, never the `@if`
  branches or their text.
- `bash ci/verify-docs.sh` GATE 2/GATE 3 must still pass after the edits —
  every new/changed class must be an existing Tailwind utility backed by a
  Layer 1 token already in use elsewhere (`emphasis`/`size` props select
  between pre-existing component-internal class strings; no new arbitrary
  value or hex is introduced by this plan).

---

### Task 1: Restyle `AkunIndex`'s dashboard tile grid

**Files:**
- Modify: `resources/views/livewire/public/akun/akun-index.blade.php`
- Test: `tests/Feature/Livewire/Public/Akun/AkunIndexRouteTest.php`

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah `AkunIndexRouteTest`. Cakup SETIAP perilaku dan edge case task ini MELALUI seam itu, termasuk bentuk input yang tidak biasa, kondisi batas, dan jalur kegagalan. Helper internal diuji secara tidak langsung lewat seam, tidak pernah langsung, meskipun fungsi-fungsi itu diekspor. Nilai harapan dalam test harus literal yang diketahui, bukan dihitung ulang dengan cara yang sama seperti kode.

**Interfaces:**
- Consumes: `<x-mk.card>`'s `emphasis` prop (`resources/views/components/mk/card.blade.php` — `'quiet'|'base'|'strong'`, default `'base'`) and `<x-mk.icon-medallion>`'s `size` prop (`resources/views/components/mk/icon-medallion.blade.php` — `'md'|'lg'|'xl'`, default `'md'`). Both already exist; this task only supplies new values at existing call sites.
- Produces: nothing consumed by a later task in this plan — Task 2 (`CareHistoryPage`) is an independent file.

- [ ] **Step 1: Write the failing test**

  Add a new test method to `tests/Feature/Livewire/Public/Akun/AkunIndexRouteTest.php` (inside the `AkunIndexRouteTest` class, alongside the existing methods):

  ```php
    public function test_the_four_dashboard_tiles_use_the_journey_entrance_card_emphasis(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/akun');

        // emphasis="strong" (design-system.md §3.3: "a journey entrance")
        // renders shadow-md + border-primary-200 when no intent is set —
        // the tile cards carry no intent, so this class pair is the real,
        // rendered marker that the new emphasis value is actually applied,
        // not merely present in the source file.
        $response->assertSee('shadow-md', false);
        $response->assertSee('border-primary-200', false);

        // <x-mk.icon-medallion size="lg"> renders size-13 tile / size-6 mark
        // (icon-medallion.blade.php's $sizes/$iconSizes maps) — the default
        // ("md") renders size-11/size-5 instead, so this class pair proves
        // the size bump landed, not just that some icon-medallion rendered.
        $response->assertSee('size-13', false);
        $response->assertSee('size-6', false);
    }
  ```

  This method belongs directly under the existing `test_bottom_nav_renders_with_akun_active` method, before the closing `}` of the class.

- [ ] **Step 2: Run test to verify it fails**

  This host's PHP is 8.3 and this worktree ships without `vendor/`; PHP tests
  cannot run directly on this host (known host constraint — see Task 1
  Step 6 for how to run this for real, inside the PHP 8.5 app container).
  Read the current `akun-index.blade.php` source instead to confirm by
  inspection that today it renders `shadow-sm`/`border-neutral-200` (the
  `base` emphasis default) and `size-11`/`size-5` (the `md` icon-medallion
  default) — i.e. confirm the new assertions do not match current output
  before making the change.

- [ ] **Step 3: Add `emphasis="strong"` to all four tile cards**

  In `resources/views/livewire/public/akun/akun-index.blade.php`, change each of the four `<x-mk.card :href="route(...)" interactive>` opening tags to add `emphasis="strong"`:

  ```blade
        <x-mk.card :href="route('akun.draft')" interactive emphasis="strong">
  ```
  ```blade
        <x-mk.card :href="route('akun.pesanan')" interactive emphasis="strong">
  ```
  ```blade
        <x-mk.card :href="route('akun.perpanjangan')" interactive emphasis="strong">
  ```
  ```blade
        <x-mk.card :href="route('akun.dokumen')" interactive emphasis="strong">
  ```

  Directly above the tile grid's opening `<div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2">`, add a one-line Blade comment recording why:

  ```blade
    {{-- Each tile below is a journey entrance into a whole sub-flow (draft
         resume, order list, renewal, documents) — design-system.md §3.3's
         emphasis="strong" case exactly, matching the icon-medallion size
         bump directly below it. --}}
  ```

- [ ] **Step 4: Bump each tile's icon-medallion to `size="lg"`**

  In the same file, change each of the four `<x-mk.icon-medallion icon="..." tone="primary" />` tags to add `size="lg"`:

  ```blade
                <x-mk.icon-medallion icon="clock" tone="primary" size="lg" />
  ```
  ```blade
                <x-mk.icon-medallion icon="inbox" tone="primary" size="lg" />
  ```
  ```blade
                <x-mk.icon-medallion icon="clock-x" tone="primary" size="lg" />
  ```
  ```blade
                <x-mk.icon-medallion icon="document-text" tone="primary" size="lg" />
  ```

- [ ] **Step 5: Update the file's own top doc-comment**

  In the same file's leading `{{-- ... --}}` doc comment block, after the existing paragraph that starts "Four tiles — see the component's own doc block.", add:

  ```
    Tiles render with `emphasis="strong"` and `<x-mk.icon-medallion size="lg">`
    (FFI account-area visual restyle, `.scratch/ffi-clone-whole-frontend/
    issues/07-account-area-visual-restyle.md`) — each tile is a journey
    entrance into a whole sub-flow, the documented case for both props
    (design-system.md §3.3, §3.3a).
  ```

- [ ] **Step 6: Run the test inside the PHP 8.5 app container to verify it passes**

  This host's PHP is 8.3 and this worktree ships without `vendor/`; PHP tests
  cannot run directly here (known host constraint). Run instead inside the
  project's PHP 8.5 app container, e.g.:

  ```bash
  docker compose -f <path-to-this-repo's-real-compose-file> exec app \
    php artisan test --filter=AkunIndexRouteTest
  ```

  If no such container is reachable from this workspace, do not claim PASS —
  report NOT TESTED locally and rely on CI's PHP job for the pushed branch to
  confirm this test passes for real before merging.

  Expected once actually run: PASS (4 existing tests + the new one, 5 total).

- [ ] **Step 7: Run `ci/verify-docs.sh`**

  Run: `bash ci/verify-docs.sh`

  Expected: PASS — the new classes (`emphasis="strong"`, `size="lg"`) are
  component props resolving to pre-existing, already-asserted Tailwind
  utility strings inside `card.blade.php`/`icon-medallion.blade.php`, not new
  hardcoded values or arbitrary Tailwind values in this Blade file.

- [ ] **Step 8: Commit**

  ```bash
  git add resources/views/livewire/public/akun/akun-index.blade.php tests/Feature/Livewire/Public/Akun/AkunIndexRouteTest.php
  git commit -m "feat: restyle akun dashboard tiles to journey-entrance card emphasis"
  ```

---

### Task 2: Restyle `CareHistoryPage`'s shell and work-order list rows

**Files:**
- Modify: `resources/views/livewire/public/care-subscription/care-history-page.blade.php`
- Test: `tests/Feature/Livewire/Public/CareSubscription/CareHistoryPageRouteTest.php`

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah `CareHistoryPageRouteTest`. Cakup SETIAP perilaku dan edge case task ini MELALUI seam itu, termasuk bentuk input yang tidak biasa, kondisi batas, dan jalur kegagalan. Helper internal diuji secara tidak langsung lewat seam, tidak pernah langsung, meskipun fungsi-fungsi itu diekspor. Nilai harapan dalam test harus literal yang diketahui, bukan dihitung ulang dengan cara yang sama seperti kode.

**Interfaces:**
- Consumes: `<x-mk.card>`'s `emphasis` prop (same as Task 1 — `'quiet'` this time, `resources/views/components/mk/card.blade.php`). Independent of Task 1's file; no shared state.
- Produces: nothing consumed elsewhere in this plan.

- [ ] **Step 1: Write the failing tests**

  Add two new test methods to `tests/Feature/Livewire/Public/CareSubscription/CareHistoryPageRouteTest.php` (inside the `CareHistoryPageRouteTest` class):

  ```php
    public function test_the_page_shell_matches_the_rest_of_the_account_areas_established_convention(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->get('/riwayat-perawatan/'.$user->getAuthIdentifier());

        // Matches AkunIndex/OrderList/DraftList's own shared shell — see
        // resources/views/livewire/public/akun/{akun-index,order-list,
        // draft-list}.blade.php, all of which use this exact class string.
        $response->assertSee('mx-auto max-w-content px-4 py-8 md:px-6 lg:px-8', false);
        $response->assertSee('text-3xl font-semibold tracking-tight text-neutral-900', false);
    }
  ```

  ```php
    public function test_a_work_order_row_uses_the_list_row_card_emphasis(): void
    {
        $user = User::factory()->create();

        $carePlan = CarePlan::query()->create([
            'reference' => 'CP-'.Str::upper(Str::random(8)),
            'name' => 'Perawatan Bulanan Standar',
            'product_code' => 'GRAVE_CARE_MONTHLY',
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'checklist_template' => ['membersihkan makam'],
            'status' => 'active',
        ]);

        $subscription = Subscription::query()->create([
            'reference' => 'SUB-'.Str::upper(Str::random(8)),
            'grave_id' => (string) Str::uuid(),
            'care_plan_id' => $carePlan->getKey(),
            'customer_id' => $user->id,
            'status' => 'active',
            'frequency' => CarePlanFrequency::Monthly->value,
            'price_minor' => 150000,
            'currency' => 'IDR',
            'current_cycle_number' => 2,
            'started_at' => now()->subMonths(2),
        ]);

        $cycle = SubscriptionCycle::query()->create([
            'subscription_id' => $subscription->getKey(),
            'cycle_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'cycle_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'status' => 'COMPLETED',
        ]);

        WorkOrder::query()->create([
            'reference' => 'WO-'.Str::upper(Str::random(8)),
            'care_plan_id' => $carePlan->getKey(),
            'subscription_cycle_id' => $cycle->getKey(),
            'status' => 'completed',
        ]);

        $response = $this->actingAs($user)->get('/riwayat-perawatan/'.$user->getAuthIdentifier());

        // emphasis="quiet" (design-system.md §3.3: "a row, not a raised
        // object") renders shadow-none — the base default (unset emphasis)
        // renders shadow-sm instead, so this is the real, rendered marker
        // that the list-row treatment is actually applied to a genuine row.
        $response->assertSee('shadow-none', false);
    }
  ```

  These two methods belong directly after the existing
  `test_an_authenticated_customer_visiting_another_customers_url_gets_the_honest_empty_state_not_their_real_history`
  method, before the closing `}` of the class.

- [ ] **Step 2: Run test to verify it fails**

  Cannot run directly on this host (PHP 8.3, no `vendor/` in this worktree —
  see Task 1 Step 2/6). Read the current `care-history-page.blade.php`
  source by inspection to confirm today's shell renders `mx-auto w-full
  max-w-3xl px-4 py-10` and `text-2xl font-semibold text-neutral-900`
  (neither string matches the new assertion), and each work-order
  `<x-mk.card class="mb-3">` has no `emphasis` prop set, so it renders the
  `base` default's `shadow-sm` rather than `shadow-none` — i.e. confirm the
  new assertions do not match current output before making the change.

- [ ] **Step 3: Align the page shell to the account area's own convention**

  In `resources/views/livewire/public/care-subscription/care-history-page.blade.php`, change:

  ```blade
  <div class="mx-auto w-full max-w-3xl px-4 py-10">
      <h1 class="text-2xl font-semibold text-neutral-900">Riwayat Perawatan</h1>
  ```

  to:

  ```blade
  <div class="mx-auto max-w-content px-4 py-8 md:px-6 lg:px-8">
      <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">Riwayat Perawatan</h1>
  ```

  This matches `resources/views/livewire/public/akun/akun-index.blade.php`,
  `order-list.blade.php`, and `draft-list.blade.php`'s own identical shell —
  the account area's own established convention this page previously
  diverged from.

- [ ] **Step 4: Give each work-order card the list-row emphasis**

  In the same file, change:

  ```blade
              <x-mk.card class="mb-3">
  ```

  to:

  ```blade
              <x-mk.card emphasis="quiet" class="mb-3">
  ```

- [ ] **Step 5: Update the file's own top doc-comment**

  In the same file's leading `{{-- ... --}}` doc comment block, after the
  existing paragraph ending "...with a note pointing at login instead of a
  button that would just deny.", add:

  ```
    Page shell (container/heading classes) matches the account area's own
    established convention (`akun-index.blade.php`/`order-list.blade.php`/
    `draft-list.blade.php`'s identical shell) rather than this file's
    previous narrower, smaller-heading shell. Each work-order card renders
    `emphasis="quiet"` (design-system.md §3.3: "a row, not a raised
    object") — a list row, not a dashboard tile (FFI account-area visual
    restyle, `.scratch/ffi-clone-whole-frontend/issues/
    07-account-area-visual-restyle.md`).
  ```

- [ ] **Step 6: Run the tests inside the PHP 8.5 app container to verify they pass**

  ```bash
  docker compose -f <path-to-this-repo's-real-compose-file> exec app \
    php artisan test --filter=CareHistoryPageRouteTest
  ```

  If no such container is reachable from this workspace, do not claim PASS —
  report NOT TESTED locally and rely on CI's PHP job for the pushed branch.

  Expected once actually run: PASS (4 existing tests + the 2 new ones, 6
  total).

  Also run the sibling component-level tests that exercise this same view
  through `Livewire::test()` (unaffected by this plan's class-only changes,
  but must stay green):

  ```bash
  docker compose -f <path-to-this-repo's-real-compose-file> exec app \
    php artisan test --filter=CareHistoryPageTest
  docker compose -f <path-to-this-repo's-real-compose-file> exec app \
    php artisan test --filter=CareHistoryPageActionsTest
  ```

- [ ] **Step 7: Run `ci/verify-docs.sh`**

  Run: `bash ci/verify-docs.sh`

  Expected: PASS — same reasoning as Task 1 Step 7; `max-w-content`,
  `tracking-tight`, and `emphasis="quiet"` are all pre-existing tokens/props
  already used elsewhere in this codebase, not new values.

- [ ] **Step 8: Commit**

  ```bash
  git add resources/views/livewire/public/care-subscription/care-history-page.blade.php tests/Feature/Livewire/Public/CareSubscription/CareHistoryPageRouteTest.php
  git commit -m "feat: align care-history page shell and list rows to account-area visual language"
  ```

---

### Task 3: Whole-branch verification

**Files:**
- None modified — this task only runs verification across Tasks 1–2's combined diff.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah `AkunIndexRouteTest` dan `CareHistoryPageRouteTest` (dijalankan bersama sebagai verifikasi whole-branch), plus `tests/browser/e2e-akun.spec.ts`. Cakup SETIAP perilaku dan edge case task ini MELALUI seam itu, termasuk bentuk input yang tidak biasa, kondisi batas, dan jalur kegagalan. Helper internal diuji secara tidak langsung lewat seam, tidak pernah langsung, meskipun fungsi-fungsi itu diekspor. Nilai harapan dalam test harus literal yang diketahui, bukan dihitung ulang dengan cara yang sama seperti kode.

**Interfaces:**
- Consumes: the combined output of Task 1 and Task 2.
- Produces: nothing — this is the plan's final gate before handoff to code review / PR.

- [ ] **Step 1: Run `ci/verify-docs.sh` once more against the full branch diff**

  Run: `bash ci/verify-docs.sh`

  Expected: PASS.

- [ ] **Step 2: Run the full account-area PHP test files inside the PHP 8.5 app container**

  ```bash
  docker compose -f <path-to-this-repo's-real-compose-file> exec app \
    php artisan test tests/Feature/Livewire/Public/Akun tests/Feature/Livewire/Public/CareSubscription tests/Feature/View/AkunHeaderLinkTest.php
  ```

  If no such container is reachable, report NOT TESTED explicitly rather than
  PASS, and rely on the real CI run on the pushed branch.

- [ ] **Step 3: Confirm `tests/browser/e2e-akun.spec.ts` needs no changes**

  Read `tests/browser/e2e-akun.spec.ts` and confirm none of its locators
  depend on any class this plan changed (`shadow-sm`, `size-11`/`size-5`,
  `max-w-3xl`, `text-2xl`) — it asserts routes, visible copy, and ARIA
  attributes, none of which this plan's Tailwind-class-only changes touch.
  If this repo's CI runs this Playwright suite on the pushed branch, trust
  that real run rather than re-deriving pass/fail by inspection alone.

- [ ] **Step 4: Final commit check**

  ```bash
  git status
  git log --oneline origin/docs/design-system-and-planning..HEAD
  ```

  Expected: working tree clean, exactly the two feature commits from Task 1
  and Task 2 ahead of `origin/docs/design-system-and-planning`.

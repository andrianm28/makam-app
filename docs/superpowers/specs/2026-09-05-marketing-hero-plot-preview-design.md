# Marketing Hero Plot-Availability Preview — Design Spec

**Date:** 5 Sep 2026
**Status:** Draft (approved technical approach; this document is the write-up)
**Scope:** A new, read-only homepage section showing a live sample of real per-plot availability for a small, config-driven set of showcase cemeteries — reusing the visual vocabulary of the already-shipped Step 2 booking-wizard plot picker. No change to `<x-mk.hero>`, no change to the normative nine-section homepage order.
**Depends on:** `plot-inventory-and-reservation` (P3) — `GravePlot`/`CemeteryBlock`/`PlotState`, and the granular/aggregate tracking-tier split (`docs/superpowers/plans/2026-08-26-cemetery-plot-tracking-mode.md`). Brand visual refresh Phase 2 (`docs/superpowers/specs/2026-08-21-brand-visual-refresh-design.md`) — the hero this section sits beneath.

## 1. Finding and motivation

A competitive read of `makamia.id`'s homepage found it embeds a live, interactive plot-availability
map directly on the page. That reads as materially more trustworthy than a static hero photo: a
visitor sees real inventory before they commit to starting a booking. `makam-app`'s current
homepage hero (`resources/views/components/mk/hero.blade.php`, consumed by
`resources/views/livewire/public/home-page.blade.php` Section 2) is a static photo paired with one
heading and one CTA — honest and on-brand, but it makes no availability claim at all.

The platform already has the exact building block this needs, already shipped and already proven
correct: the Step 2 booking-wizard plot picker
(`resources/views/livewire/public/booking/wizard.blade.php:398-499`, backed by
`App\Livewire\Public\Booking\BookingWizard::pickerBlocks()`,
`app/Livewire/Public/Booking/BookingWizard.php:621-641`) already renders a live per-plot grid —
block code + name, one `<x-mk.badge>` per plot driven by `App\Support\Design\StatusIntent::intent()`
resolving `App\Domain\PlotInventory\PlotState` (`app/Support/Design/StatusIntent.php:303-308`), an
honest "peta plot sedang tidak dapat dimuat" degrade alert, and an honest "belum ada plot terdaftar"
empty state. The only thing standing between that vocabulary and a homepage proof point is that the
wizard's version is wired into a mutating, session-bound booking flow. This document specifies a
genuinely new, read-only surface that borrows the *vocabulary* (badges, intents, grid shape) without
touching the wizard's mutating code path at all.

`App\Domain\PlotInventory\Models\GravePlot.slot` (`app/Domain/PlotInventory/Models/GravePlot.php:52`)
is a block/slot code (e.g. `001`), never an occupant name — an availability-only read of this column
carries no personal data, so this section requires no privacy review beyond the general public-read
discipline every other homepage panel already follows.

## 2. In scope

1. A new Livewire component, `App\Livewire\Public\Home\PlotAvailabilityPreview`, rendering a
   read-only, cached sample of per-plot availability for a small, config-driven list of cemetery
   slugs.
2. A new Blade view for that component,
   `resources/views/livewire/public/home/plot-availability-preview.blade.php`, reusing
   `<x-mk.badge>` + `StatusIntent::{intent,icon,label}(..., StatusIntent::FAMILY_PLOT_STATE)` for the
   per-plot vocabulary.
3. A new config key, `config('marketing.homepage_plot_preview_cemetery_slugs')`, naming the
   showcase cemeteries.
4. One insertion point in `resources/views/livewire/public/home-page.blade.php`: a single
   `<livewire:public.home.plot-availability-preview />` line between Section 2 (Hero) and Section 3
   (four service cards).
5. Caching of the read (first use of Laravel's `Cache` facade anywhere in this codebase — verified
   by grep, §6 below) to bound the query cost this section adds to the highest-traffic page.
6. Tests: a component-level test seeding the exact conditions needed to make the section render,
   and a homepage-route-level test confirming placement and confirming the section is invisible
   under today's real seed data (no cemetery is provisioned as `granular` yet — §4.6).

## 3. Out of scope

- Any change to `<x-mk.hero>`'s props, markup, or contract (Rejected Alternative #2, §8).
- Any change to the four already-normative homepage sections' order (§4.5 of
  `design-system.md` is unchanged; this is an additional section the same way "Kehangatan Keluarga"
  was added between Sections 6 and 7 without renumbering — see `home-page.blade.php`'s own top doc
  block, "ADDED 26 Aug 2026").
- Holding, reserving, or otherwise mutating any plot. The new component calls no action under
  `App\Domain\PlotReservation\**` or `App\Domain\PlotInventory\Actions\**` — enforced as a hard,
  testable constraint (§7).
- Provisioning real block/plot data for any showcase cemetery. As of this writing, **no cemetery in
  seed data has `plot_tracking_mode = granular`** (verified: no call to
  `App\Domain\CemeteryDirectory\Actions\SetCemeteryPlotTrackingMode` or
  `App\Domain\PlotInventory\Actions\CreateCemeteryBlock` exists anywhere under `database/`). This
  section will render its honest empty state (§5) against today's real data until an operator seeds
  at least one showcase cemetery through the existing admin flow. That is a real, named data gap for
  this batch's report — not something this plan's tasks fix, since seeding production-looking block
  data is a content decision, not an engineering one.
- Analytics/impression tracking for this section. `HomePage::PRIMARY_MENUS` records impressions
  because AC9 names the four primary menus specifically; this new section is not one of them, and
  adding a second impression surface is not requested by any open acceptance criterion.

## 4. Architecture

### 4.1 Component

```php
<?php

declare(strict_types=1);

namespace App\Livewire\Public\Home;

use App\Domain\CemeteryDirectory\CemeteryPublicQuery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Throwable;

final class PlotAvailabilityPreview extends Component
{
    private const int MAX_CEMETERIES = 2;
    private const int MAX_BLOCKS_PER_CEMETERY = 2;
    private const int MAX_PLOTS_PER_BLOCK = 12;
    private const int CACHE_TTL_SECONDS = 60;

    public function render(): View
    {
        $slugs = array_slice(
            (array) config('marketing.homepage_plot_preview_cemetery_slugs'),
            0,
            self::MAX_CEMETERIES,
        );

        $showcase = new Collection;
        $unavailable = false;

        if ($slugs !== []) {
            try {
                $cacheKey = 'homepage:plot-availability-preview:'.md5(implode(',', $slugs));

                $showcase = Cache::remember(
                    $cacheKey,
                    self::CACHE_TTL_SECONDS,
                    fn (): Collection => $this->buildShowcase($slugs),
                );
            } catch (Throwable $e) {
                report($e);
                $unavailable = true;
            }
        }

        return view('livewire.public.home.plot-availability-preview', [
            'showcase' => $showcase,
            'unavailable' => $unavailable,
        ]);
    }

    /**
     * @param  list<string>  $slugs
     * @return Collection<int, array{cemetery: \App\Domain\CemeteryDirectory\Models\Cemetery, blocks: \Illuminate\Database\Eloquent\Collection<int, CemeteryBlock>}>
     */
    private function buildShowcase(array $slugs): Collection
    {
        $result = new Collection;

        foreach ($slugs as $slug) {
            $cemetery = CemeteryPublicQuery::findPublishedBySlug($slug);

            if ($cemetery === null || $cemetery->plot_tracking_mode !== PlotTrackingMode::GRANULAR) {
                continue;
            }

            $blocks = CemeteryBlock::query()
                ->where('cemetery_id', $cemetery->getKey())
                ->with(['plots' => fn ($query) => $query->orderBy('slot')->limit(self::MAX_PLOTS_PER_BLOCK)])
                ->orderBy('code')
                ->limit(self::MAX_BLOCKS_PER_CEMETERY)
                ->get();

            if ($blocks->isEmpty()) {
                continue;
            }

            $result->push(['cemetery' => $cemetery, 'blocks' => $blocks]);
        }

        return $result;
    }
}
```

**Why `CemeteryPublicQuery::findPublishedBySlug()`, not a bare `Cemetery::find()`.** Same reasoning
`BookingWizard::pickerAppliesTo()` already documents (`app/Livewire/Public/Booking/BookingWizard.php:585-596`):
a public read must start from `Cemetery::published()`, and an unpublished or draft cemetery must
resolve to the same "nothing here" outcome as a typo'd slug — never a distinguishable miss. Unlike
the wizard's `findPublishedById()` (a UUID-typed column, wrong-shape input is a Postgres type error),
`slug` is a plain string column, so `findPublishedBySlug()` needs no shape guard — it already returns
`null` cleanly for anything that doesn't match (`CemeteryPublicQuery::findPublishedBySlug()`,
`app/Domain/CemeteryDirectory/CemeteryPublicQuery.php:220-229`).

**Why a plain `plot_tracking_mode !== PlotTrackingMode::GRANULAR` check, not `pickerAppliesTo()`
itself.** `pickerAppliesTo()` lives on `BookingWizard` and re-resolves the cemetery a second time
internally — calling it here would mean two lookups per cemetery for no benefit, since this method
already holds the resolved `$cemetery`. The check is one line and matches the tracking-tier
invariant `pickerAppliesTo()` encodes (`app/Domain/CemeteryDirectory/PlotTrackingMode.php:25-49`) —
it is not a second definition of that invariant, just the same one-line condition inlined against an
already-loaded model, same as `CemeteryPublicQuery::activePackages()` inlines
`$cemetery->isPublished()` rather than calling back into a shared helper.

**Why `->limit()` inside the eager-load closure, not `->take()` after `->get()`.** `CemeteryBlock`'s
own `plots()` relation has no default ordering; capping in PHP after `get()` would still pull every
row for every block from the database first. `->limit()` inside the `with()` closure (exactly the
shape `pickerBlocks()` already uses for ordering,
`app/Livewire/Public/Booking/BookingWizard.php:632`, extended with a `limit()`) bounds the query
itself.

### 4.2 Config

New file, `config/marketing.php`:

```php
<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Homepage plot-availability preview — showcase cemeteries
|--------------------------------------------------------------------------
|
| Slugs of the cemeteries App\Livewire\Public\Home\PlotAvailabilityPreview
| samples for the homepage's live-availability proof point
| (docs/superpowers/specs/2026-09-05-marketing-hero-plot-preview-design.md).
| A cemetery here that is not published, or not yet flipped to
| App\Domain\CemeteryDirectory\PlotTrackingMode::GRANULAR via
| App\Domain\CemeteryDirectory\Actions\SetCemeteryPlotTrackingMode, is
| silently skipped by the component — this list is a wishlist of curated
| candidates, not a guarantee every entry renders.
|
| Config, not a class constant, so which cemeteries are showcased can
| change without a deploy-and-decide cycle — the same reasoning
| config/plot-reservation.php's draft_hold_ttl_minutes gives for the same
| choice.
|
| Default: the two real, published cemeteries with real photography
| already backed in via 2026_08_24_100000_backfill_photo_and_maps_url_for_
| real_cemeteries.php. Neither is granular-tier as of this writing (see
| this spec's §3, "Out of scope") — the default is a real, meaningful
| curated choice for the day an operator provisions block/plot data for
| one of them, not a placeholder.
|
*/
return [
    'homepage_plot_preview_cemetery_slugs' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env(
            'HOMEPAGE_PLOT_PREVIEW_CEMETERY_SLUGS',
            'tpu-petamburan,tpu-karet-bivak',
        )),
    ))),
];
```

`tpu-petamburan` and `tpu-karet-bivak` are real, currently-published cemetery slugs (verified against
`database/migrations/2026_08_24_100000_backfill_photo_and_maps_url_for_real_cemeteries.php:80-85`,
the same four-cemetery list that migration gave real photography). Both are `aggregate`-tier today
(`Cemetery.$attributes['plot_tracking_mode']` defaults to `PlotTrackingMode::AGGREGATE`,
`app/Domain/CemeteryDirectory/Models/Cemetery.php:119-121`, and neither has been flipped) — so this
section renders its honest empty state against today's real database, exactly as documented in §3
and asserted by the test in §7. The config exists so that the day an operator runs
`SetCemeteryPlotTrackingMode` + `CreateCemeteryBlock` for either cemetery, the section starts
rendering with zero code change.

### 4.3 View

```blade
<div>
    @unless ($unavailable || $showcase->isEmpty())
        <section aria-labelledby="plot-preview-heading" class="mx-auto max-w-content px-4 py-5 md:px-6 lg:px-8 lg:py-8">
            <h2 id="plot-preview-heading" class="mb-2 text-center text-2xl font-semibold text-neutral-900">
                Lihat Contoh Ketersediaan Plot
            </h2>
            <p class="mx-auto mb-6 max-w-prose text-center text-base text-neutral-600">
                Data plot di bawah ini nyata dan diperbarui secara berkala, bukan ilustrasi — sebagian kecil dari
                TPU/TPS kami yang sudah memiliki data plot rinci.
            </p>
            <div class="grid gap-y-8">
                @foreach ($showcase as $entry)
                    @php [$cemetery, $blocks] = [$entry['cemetery'], $entry['blocks']]; @endphp
                    <div wire:key="plot-preview-cemetery-{{ $cemetery->id }}">
                        <h3 class="mb-3 text-lg font-semibold text-neutral-900">{{ $cemetery->name }}</h3>
                        <div class="grid gap-y-4">
                            @foreach ($blocks as $block)
                                <div wire:key="plot-preview-block-{{ $block->id }}">
                                    <p class="mb-2 text-sm font-medium text-neutral-900">{{ $block->code }} &mdash; {{ $block->name }}</p>
                                    <ul class="flex flex-wrap gap-2" aria-label="Plot di {{ $block->code }}">
                                        @foreach ($block->plots as $plot)
                                            <li wire:key="plot-preview-plot-{{ $plot->id }}" class="inline-flex items-center gap-1 rounded-md border border-neutral-200 px-2 py-1 text-sm text-neutral-700">
                                                {{ $plot->slot }}
                                                <x-mk.badge
                                                    intent="{{ \App\Support\Design\StatusIntent::intent($plot->plot_state, \App\Support\Design\StatusIntent::FAMILY_PLOT_STATE) }}"
                                                    :icon="\App\Support\Design\StatusIntent::icon($plot->plot_state, \App\Support\Design\StatusIntent::FAMILY_PLOT_STATE)"
                                                    size="sm"
                                                >
                                                    {{ \App\Support\Design\StatusIntent::label($plot->plot_state, \App\Support\Design\StatusIntent::FAMILY_PLOT_STATE) }}
                                                </x-mk.badge>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
            <p class="mt-6 text-center text-sm text-neutral-600">
                <a href="{{ route('cemeteries.index') }}" class="font-medium text-primary-700 underline underline-offset-2">
                    Lihat semua TPU &amp; TPS
                </a>
            </p>
        </section>
    @endunless
</div>
```

**Deliberate divergence from the wizard's literal markup: no `<x-mk.button>` wrapper around each
plot.** The wizard wraps each plot in `<x-mk.button wire:click="holdPlotForDiscovery(...)"
:disabled="...">` because clicking an **available** plot there genuinely holds it — the button
affordance is honest because the action exists. Here, no click affordance exists for any plot,
available or not: this is a display-only sample, and every path to an actual booking runs through
`/pemesanan-makam` (Section 3's card, or the CTA below). Keeping the `<x-mk.button>` wrapper (even
`disabled`) would render a control that looks interactive but never is — the same "looks clickable,
isn't" trap `button.blade.php`'s own disabled-state contrast rule (§9.2 MUST NOT 5) exists to avoid
for colour; here it is the element choice itself that would mislead. The reused vocabulary is the
**badge + intent + slot text**, not the button wrapper — `<x-mk.badge>` and `StatusIntent` are the
parts of the wizard's picker that actually encode the domain vocabulary; the button was always the
wizard's own interaction chrome, not part of that vocabulary.

**No hold alert, no `activeDraftPlotHold()` call.** An anonymous homepage visitor has no
`BookingDraft`, so the wizard's "Plot ditahan sementara" concept has nothing to attach to here. This
component never reads or references `PlotReservation` at all.

**Single-root `<div>` wrapper.** Livewire requires exactly one root element per component. The
`@unless` sits *inside* that wrapper rather than around a `<livewire:...>` tag in the parent view, so
the "hide entirely" state (§5) still satisfies Livewire's one-root-element contract while rendering
functionally nothing — no heading, no landmark, no visible box — matching Section 5's own "Hide the
section entirely" precedent (`design-system.md` §6.2's table row for "Featured cemeteries absent").

### 4.4 Placement

One line, `resources/views/livewire/public/home-page.blade.php`, inserted immediately after the
closing `</x-mk.hero>` tag (currently line 173) and before the `{{-- Section 3: four service cards
... --}}` comment (currently line 175):

```blade
    </x-mk.hero>

    {{-- Plot-availability preview — NEW section, not a tenth entry in IA §3's normative nine-section
         list (same non-renumbering precedent as "Kehangatan Keluarga" below, added 26 Aug 2026 —
         see that section's own comment and this file's top doc block). Sits directly under the
         hero, ahead of the four service cards, per
         docs/superpowers/specs/2026-09-05-marketing-hero-plot-preview-design.md. Renders nothing
         when no showcase cemetery has real per-plot data yet (§4.3's @unless) or the read fails
         (component's own try/catch) — never a broken or empty-looking box on the highest-traffic
         page. --}}
    <livewire:public.home.plot-availability-preview />

    {{-- Section 3: four service cards — AC1's exact order, from
```

This does not touch `App\Livewire\Public\HomePage::render()` or its view-data array at all — the new
component resolves its own data independently, the same separation `<livewire:platform.notification.in-app-notification-list />`
already establishes as this codebase's one precedent for a nested Livewire component
(`resources/views/filament/admin/pages/in-app-notifications.blade.php:13`,
`App\Livewire\Platform\Notification\InAppNotificationList`).

## 5. Data flow

Anonymous visitor requests `/` → `HomePage::render()` runs unchanged → `home-page.blade.php` renders
Section 2 (hero), then mounts `PlotAvailabilityPreview` inline (eager, not lazy — §6) →
`PlotAvailabilityPreview::render()` reads `config('marketing.homepage_plot_preview_cemetery_slugs')`,
checks a 60-second cache keyed by that slug list's hash, and on a miss queries
`CemeteryPublicQuery::findPublishedBySlug()` + `CemeteryBlock::query()` per slug → view renders the
badge grid (or renders nothing, per §4.3's `@unless`) → the "Lihat semua TPU & TPS" link and Section
3's cards are the only paths onward; no plot in this grid is itself a link or a control.

## 6. Caching and rate-limiting

**Rate limiting: no new limiter is needed.** `bootstrap/app.php:94` already appends
`throttle:public-guest` to the entire `web` middleware group, which `/` belongs to
(`config/rate_limiting.php`'s own doc block: 60 requests/minute per IP for an anonymous visitor,
120/minute for an authenticated one). Because `PlotAvailabilityPreview` renders synchronously inside
the same HTTP response as the rest of the homepage — no `wire:poll`, no `wire:click`, no lazy-loading
placeholder-then-fetch — it adds **zero new Livewire round trips** and therefore zero new surface
against that limiter. This is a hard constraint on the component, not an incidental property: adding
any interactive `wire:` binding to this component later would need its own throttling review, and
the plan's global constraints (§7 below) forbid this component from exposing any public method other
than `render()` at all.

**Caching: `Cache::remember()`, 60-second TTL, keyed by the resolved slug list.** This is the first
use of Laravel's `Cache` facade anywhere in this codebase (verified: no
`Illuminate\Support\Facades\Cache` import exists under `app/` today). The default cache store is
`database` (`config/cache.php:18`, `env('CACHE_STORE', 'database')`) — this spec does not require
Redis; it works against the `cache` database table already migrated for Laravel's framework cache.
`design-system.md` §4.6 sets the homepage's server p95 budget at ≤500 ms
(`docs/operations/performance-and-capacity.md` §3); an uncached read here would add one
`cemeteries` lookup plus one `cemetery_blocks`+`grave_plots` eager load **per showcase cemetery, on
every single homepage request** — on the single most performance-sensitive public page. A 60-second
cache bounds that to one real query burst per cemetery per minute regardless of traffic, the same
order-of-magnitude protection `throttle:public-guest` already gives the rest of the page.

**The cache can only cause staleness, never a booking-integrity problem.** A plot shown "Tersedia"
here that was reserved by another visitor seconds ago through the *real* wizard is a display lag of
at most 60 seconds — this component never lets a visitor act on what it shows; every actual booking
still goes through `BookingWizard::holdPlotForDiscovery()`'s own `lockForUpdate()`-guarded, uncached,
authoritative read (`docs/superpowers/specs/2026-08-16-plot-inventory-reservation-design.md` §4.2).
This section cannot cause a double-hold or a stale success confirmation because it has no confirm
path of its own — it is display-only, and the constraint in §3 ("out of scope: holding, reserving,
or otherwise mutating any plot") is what keeps that true.

**Cache key includes the slug list, not a fixed string**, so changing
`HOMEPAGE_PLOT_PREVIEW_CEMETERY_SLUGS` (or the config default) takes effect on the next request with
no manual cache flush — an old key simply stops being read and ages out under its own TTL, it is
never actively invalidated.

**A genuine query failure is not cached.** `Cache::remember()`'s closure only writes to the cache
store on success; an exception inside `buildShowcase()` propagates out of `remember()` uncached, is
caught by the component's own `try`/`catch`, reported via `report($e)`, and renders the same "hide
entirely" state as the empty-showcase case (§4.3) — the next request tries again rather than serving
a cached failure.

## 7. States (empty, degrade, and the states that do not apply)

This section is display-only and secondary to the page's real purpose (booking, browsing, FAQ) — it
follows Section 5's own precedent (`design-system.md` §6.2's "Featured cemeteries absent → hide the
section entirely") rather than the wizard picker's own visible-alert states, because the wizard is a
transactional mid-booking screen where a customer needs to know *why* nothing is loading, and this is
an optional homepage proof point where a broken-looking box would undermine the very trust the
section exists to build.

| Situation | Behaviour | Precedent |
|---|---|---|
| No configured slug resolves to a published, granular cemetery with ≥1 non-empty block (today's real state — §3) | Render nothing (single empty root `<div>`) | `design-system.md` §6.2, Section 5's "hide entirely" row |
| `CemeteryPublicQuery`/`CemeteryBlock` query throws | `report($e)`, render nothing, no cache write | Same as `HomePage::render()`'s own FAQ/featured-cemetery `try`/`catch` (`app/Livewire/Public/HomePage.php:104-146`) |
| A showcase cemetery is granular but has zero blocks | Silently skipped in `buildShowcase()` (`if ($blocks->isEmpty()) { continue; }`), same as an unresolved slug | Mirrors `pickerBlocks()`'s own `@forelse ... @empty` for the zero-blocks case, collapsed one level earlier since there's no per-cemetery alert to show |
| A configured slug does not resolve (typo, unpublished, still aggregate-tier) | Silently skipped, not an error | Same posture as `CemeteryPublicQuery::inCity()` treating an unknown city code as "matches nothing", never an exception |
| Loading state | None needed — this is not lazy-loaded (§6); it renders synchronously with the rest of the page, so there is no interstitial spinner/skeleton state to design |
| Plot hold in progress / "ditahan sementara" | Does not apply — no `BookingDraft`, no reservation concept reachable from an anonymous homepage visitor (§4.3) |
| Validation error | Does not apply — no form, no user input anywhere in this component |
| Success / confirmation | Does not apply — no action to confirm |

## 8. Alternatives considered

**Rejected #1 — a static screenshot of a plot map instead of a live widget.** Weaker proof than a
live widget, and does not match the actual competitive pattern being responded to: `makamia.id`'s
widget is live and interactive, not a photo. A screenshot would also go stale the moment any plot's
real state changed, with no mechanism to catch that — the opposite of the "honest, truthful" posture
`design-system.md` §2.3 asks for everywhere else on this site.

**Rejected #2 — embed the widget inside `<x-mk.hero>` itself, rather than as its own section.**
`<x-mk.hero>` is a narrowly-scoped, already-shipped component (`docs/superpowers/specs/2026-08-21-brand-visual-refresh-design.md`
§4.2, live on the homepage since Phase 2 of that batch). Three concrete objections, none of which
depend on a formal governance gate:

1. It puts real, anonymous-visitor-triggered database load on the single most performance-sensitive,
   above-the-fold element on the page, instead of a section a visitor reaches by scrolling — directly
   working against the same §4.6 weight budget this spec's own caching section (§6) exists to
   protect.
2. It competes visually with the hero's one sanctioned primary action. `design-system.md` §2.3's DO
   is explicit: "exactly one primary action per view." A plot grid with its own implied "which one do
   I pick" affordance sitting inside the hero would blur that, even with no click handler attached.
3. `<x-mk.hero>`'s contract (`docs/design/design-system.md` §3.3c) is `image` + `heading` (required)
   + `cta` + one plain slot — adding a plot grid would mean either overloading that slot with content
   far heavier than the "one paragraph of supporting copy" it holds today (`home-page.blade.php`'s
   own hero usage, lines 164-173), or extending the component's props, either of which changes a
   contract a reviewer would reasonably expect to stay stable two weeks after shipping.

One caveat, corrected here rather than left standing: an earlier framing of this rejection argued
that reopening `<x-mk.hero>`'s contract would require a new ADR under `design-system.md` §9.4. That
is not accurate and is not repeated as a reason above — §9.4 is scoped explicitly to `tokens.css`
changes ("Rank 1" in §9.1's precedence table), and `design-system.md` line 842 records a real,
directly on-point precedent the other way: a component-contract change (the stepper's `labels` prop
default) was made and documented as *not* requiring an ADR, verbatim: "This is a component-contract
change, not a token change, so §9.4 does not apply and no ADR is required." The three objections
above stand on their own without that claim.

**Chosen approach's own biggest open cost, stated plainly:** as documented in §3, no cemetery in
today's seed data is provisioned to actually show anything through this section yet. The section is
correct and will render the moment real block/plot data exists for a granular-tier cemetery, but
shipping this plan alone does not make the homepage show a populated widget — that needs a separate,
content-side decision (which real cemetery, how many blocks) this batch does not make.

## 9. Testing

- **Component test**, `tests/Feature/Livewire/Public/Home/PlotAvailabilityPreviewTest.php`: seed one
  published, `granular`-tier cemetery (mirroring `BookingWizardPlotPickerTest::makeCemetery()`'s
  factory shape, `tests/Feature/Livewire/Public/Booking/BookingWizardPlotPickerTest.php:36-50`) with
  one block and a mix of `available`/`reserved`/`occupied` plots; assert the component renders each
  slot with the `StatusIntent`-mapped label/intent; assert a second, aggregate-tier cemetery in the
  same config list is silently skipped; assert the component renders nothing when the config list is
  empty; assert it renders nothing (and reports, via a `Log`/exception-handler spy) when the
  `cemetery_blocks` table is made unreadable (same technique as
  `BookingWizardPlotPickerTest::makeCemeteryBlocksUnreadable()`,
  `tests/Feature/Livewire/Public/Booking/BookingWizardPlotPickerTest.php:75-81`); assert the cache is
  populated after first render and a second render within the TTL does not re-query (`DB::listen()`
  or query-count assertion, `Illuminate\Support\Facades\DB::enableQueryLog()`).
- **Reflection test** (same file or a dedicated
  `PlotAvailabilityPreviewNeverMutatesTest`): assert
  `(new ReflectionClass(PlotAvailabilityPreview::class))->getMethods(ReflectionMethod::IS_PUBLIC)`
  contains nothing but Livewire's own inherited framework methods plus `render()` — i.e. the class
  declares no public method a `wire:click`/`wire:model` could ever target. This is the executable
  form of §3's "never mutates a plot" constraint: a class with no callable public surface beyond
  `render()` cannot be made to call a mutating action by any front-end binding, now or in a future
  edit, without that reviewer-visible reflection assertion failing first.
- **Homepage route test addition**, `tests/Feature/Livewire/Public/HomePageRouteTest.php`: assert
  `GET /` returns 200 and the response body contains no `plot-preview-heading` id when run against
  real, unmodified seed data (today's true state — no cemetery is granular yet, §3) — a currently-
  true, currently-passing assertion of the honest-empty behaviour, not a placeholder for later.
  Separately, assert that when a granular showcase cemetery with real blocks exists, the section's
  markup appears after the hero's closing content and before the Section 3 `services-heading`
  landmark (string-position assertion, same technique
  `test_all_four_menus_appear_in_ac1s_exact_order` already uses,
  `tests/Feature/Livewire/Public/HomePageRouteTest.php:46-91`).
- **Browser (dev, Playwright), as a follow-up UAT item, not a task in this plan:** load `/` against a
  seeded granular showcase cemetery and confirm the grid renders visually with correct badge colours
  at 320 px — deferred because it requires the content-side seeding work this plan's §3/§8 already
  name as out of scope.

All new tests run against real Postgres in CI, never SQLite, per this codebase's established testing
discipline (`CemeteryPublicQuery::findPublishedById()`'s own doc block records the same UUID-typing
gap class this feature must not reintroduce; this feature's own risk is lower since `slug` is a plain
string column, but the reservation/mutation-absence reflection test and the cache query-count
assertion both need the real query planner CI runs, not SQLite's).

## 10. Self-review notes

- **Placeholder scan:** no `TBD`, no "similar to above", no open brackets — every config value,
  cache TTL, cap, slug, and file path above is a concrete, real value grounded in a file this
  document cites by path and line.
- **Internal consistency:** the component's `render()` signature, the view's expected `$showcase`/
  `$unavailable` variables, and the config key name are identical across §4.1, §4.3, and §4.2 — no
  drift between the sketch and the prose describing it.
- **Scope check:** this is one cohesive unit (one component, one view, one config file, one
  insertion line, one set of tests) — not several unrelated changes bundled together. It does not
  touch `HomePage.php`, `<x-mk.hero>`, or any P3 plot-reservation code.
- **Ambiguity check:** the one open item genuinely left unresolved — no showcase cemetery has real
  data yet — is stated as a fact in §3 and §8, not smuggled in as an implicit assumption; it does not
  block this spec from being implementable exactly as written, since the honest-empty state is itself
  a designed, tested behaviour, not a bug.

<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryCapability\RegistryMode;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Faq\FaqPublicQuery;
use App\Jobs\RecordMenuImpressions;
use App\Platform\FeatureGate\ModeResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Component;
use Throwable;

/**
 * `/` — Sprint 4 S4-T3 `public-home-and-navigation`. `.kiro/specs/
 * public-home-and-navigation/{requirements,design}.md`,
 * `docs/product/information-architecture.md` §3 (the nine-section order,
 * normative) and §1 (this is the route that unblocks `/`, per
 * `routes/web.php`'s own former doc comment: "Nothing should be served
 * from `/` until public-home-and-navigation is built").
 *
 * Same structural precedent as `App\Livewire\Public\Faq\FaqIndex`: a plain
 * `Livewire\Component`, `->layout('layouts.app', [...])` attached
 * per-render (not the `#[Layout]` class attribute), read-only, no
 * mutation, no `app/Domain/**` write path touched anywhere in this class.
 *
 * ---------------------------------------------------------------------------
 * `PRIMARY_MENUS` — a SECOND hardcoded copy of the same four-menu product
 * contract `<x-mk.header>` already hardcodes, and why that is not the
 * "duplicate canonical catalogue data" AGENTS.md §Documentation forbids
 * ---------------------------------------------------------------------------
 * AGENTS.md's own Mandatory MVP UX section states the four-menu contract
 * directly ("Homepage has exactly these four primary services: Pemesanan
 * Makam, Layanan Pemakaman, Perpanjangan Makam, FAQ") — it is not owned
 * exclusively by `header.blade.php`. IA §3 requires TWO distinct UI
 * surfaces to render these same four labels: the header nav (§3 item 1)
 * and the four service cards (§3 item 3). `header.blade.php`'s own doc
 * block is explicit that its `$navItems` array is "hardcoded below, not
 * exposed as props" — this class does not modify that file to extract a
 * shared source (out of this batch's scope and risk budget), so the two
 * copies are kept in sync by convention (same labels, same order, same
 * routes), not by a shared constant. This is a real, named limitation —
 * see this batch's final report — of the same class as `docs/design/
 * design-system.md` OQ-09's already-accepted Filament-palette-vs-tokens.css
 * duplication, not a fabricated catalogue.
 */
final class HomePage extends Component
{
    /**
     * requirements.md AC1's exact order. Keys match
     * `header.blade.php`'s own `$navItems` keys so `MenuInteractionRecorder`
     * calls below and any future cross-reference use the same vocabulary.
     *
     * @var array<string, array{label: string, route: string}>
     */
    private const PRIMARY_MENUS = [
        'pemesanan' => ['label' => 'Pemesanan Makam', 'route' => '/pemesanan-makam'],
        'layanan' => ['label' => 'Layanan Pemakaman', 'route' => '/marketplace'],
        'perpanjangan' => ['label' => 'Perpanjangan Makam', 'route' => '/perpanjangan'],
        'faq' => ['label' => 'FAQ', 'route' => '/faq'],
    ];

    /**
     * requirements.md AC9 — one impression per real page view, for each of
     * the four primary menus (rendered here as the homepage's own service
     * cards, IA §3 item 3 — the clearest, lowest-risk instantiation of "a
     * user views ... a menu" for a page with no client-side event
     * pipeline). Fired from `mount()`, not `render()`: `mount()` runs
     * exactly once per initial GET, while `render()` can re-run within the
     * same Livewire request lifecycle — recording there risks
     * double-counting a single visit. `MenuInteractionRecorder::impression()`
     * never throws (see its own doc block), so a write failure here can
     * never turn into a 500 on the homepage.
     *
     * PERF-06 (batch M7b): all four impressions are dispatched as ONE
     * `RecordMenuImpressions` job (`default` queue) instead of four
     * synchronous `MenuInteractionRecorder::impression()` calls inline —
     * writing four analytics rows was real, unnecessary work on the
     * request path of the single highest-traffic route in the app. The
     * job performs a single batched insert. `dispatch()` queuing a job
     * never throws back into the caller under normal operation; on the
     * `sync` queue connection (tests, and this codebase's local default)
     * the job still runs inline, so behaviour is unchanged for anything
     * that already asserts against `MenuInteractionEvent` rows.
     *
     * The CLICK side of AC9 is NOT recorded anywhere in this batch — a
     * real, named gap. See `2026_07_26_200000_create_menu_interaction_
     * events_table.php`'s own doc block and this batch's final report.
     */
    public function mount(): void
    {
        $menus = [];

        foreach (self::PRIMARY_MENUS as $menuKey => $menu) {
            $menus[] = ['menuKey' => $menuKey, 'route' => $menu['route']];
        }

        RecordMenuImpressions::dispatch($menus);
    }

    public function render(): View
    {
        // requirements.md AC5 — truthful Urgent-availability indicator,
        // read from the server-side gate state (design-system.md §6.9:
        // "the UI must read the server value — a front-end flag is
        // insufficient"). Never hardcoded as open.
        $urgentMode = app(ModeResolver::class)->urgentMode();

        // IA §3 item 7 "FAQ highlights" — §6.5 provider-unavailable
        // discipline, mirroring FaqIndex::render()'s own try/catch around
        // its search query: a secondary panel failing must never take the
        // whole homepage down with it (design-system.md §6.3's homepage
        // row: "must still render the four menus if a secondary panel
        // fails").
        $faqHighlights = new Collection;
        $faqHighlightsUnavailable = false;

        try {
            $faqHighlights = FaqPublicQuery::allPublished()->take(4);
        } catch (Throwable $e) {
            report($e);
            $faqHighlightsUnavailable = true;
        }

        // Stage 3 ticket 04 — the old "featured cemeteries" query (IA §3
        // item 5's original, pre-Stage-3 query: published cemeteries
        // ordered by city/name, no capability filter) was removed here.
        // Its section is deleted by this ticket; its real-data
        // responsibility is now split across ticket 03's two sections
        // (urgent-availability, newest-published, both below) and this
        // ticket's own verified-cemeteries query (further below) — three
        // more specific signals replacing one generic one, per the design
        // doc §4.1 mapping. Removed rather than left unused: this
        // codebase's own established discipline against dead code (see
        // e.g. the Stage 3 bottom-nav-wiring ticket's own final-review fix
        // round for the same principle applied to a dead test branch).

        // Stage 3 ticket 03 — "urgent-availability TPU/TPS". No per-cemetery
        // "urgent" flag exists anywhere in this domain (confirmed by search
        // before writing this); the honest, non-fabricated signal is a real
        // one already tracked here: a cemetery with at least one package/
        // class explicitly marked `LIMITED` (CemeteryPackageAvailabilityStatus)
        // is genuinely running low, which is what FFI's own "urgent
        // campaigns about to close" pattern means applied honestly to this
        // domain's real data — not a new rule invented for this ticket.
        // Same §6.3 provider-unavailable / §6.2 empty-state discipline as
        // every other real-data section on this page.
        $urgentAvailabilityCemeteries = new Collection;
        $urgentAvailabilityCemeteriesUnavailable = false;

        try {
            $urgentAvailabilityCemeteries = Cemetery::published()
                ->whereHas('packages', function ($query): void {
                    $query->where('availability_status', CemeteryPackageAvailabilityStatus::LIMITED);
                })
                ->orderBy('city')
                ->orderBy('name')
                ->take(6)
                ->get();
        } catch (Throwable $e) {
            report($e);
            $urgentAvailabilityCemeteriesUnavailable = true;
        }

        // Stage 3 ticket 03 — "newest published TPU/TPS". `published_at` is
        // a real, existing timestamp column (set when a cemetery transitions
        // to published — see Cemetery::scopePublished()'s own doc block);
        // ordering by it descending is the honest "newest" signal, distinct
        // from the urgent-availability query above (which orders by
        // city/name, not recency) and the verified-cemeteries query below
        // (which filters by registry_mode, not recency).
        $newestPublishedCemeteries = new Collection;
        $newestPublishedCemeteriesUnavailable = false;

        try {
            // Secondary `id` tie-breaker: real cemeteries publish at
            // distinct times, but the seeded example-data fixture sets
            // `published_at` to the same instant for every row (see
            // CemeteryExampleData::cemeteries()), which would otherwise
            // leave the ordering among ties to the database's own
            // unspecified tie behaviour — not deterministic, not testable.
            $newestPublishedCemeteries = Cemetery::published()
                ->orderBy('published_at', 'desc')
                ->orderBy('id')
                ->take(6)
                ->get();
        } catch (Throwable $e) {
            report($e);
            $newestPublishedCemeteriesUnavailable = true;
        }

        // Stage 3 ticket 04 — "featured/verified TPU/TPS". "Lokasi
        // terverifikasi" is design-system.md's already-decided definition:
        // active capability profile, evidence present. Every seeded
        // cemetery's current profile carries the SAME placeholder evidence
        // text ("belum ada evaluasi operator lapangan ... bukan hasil
        // aktivasi kapabilitas nyata" — CemeteryExampleData::seed()'s own
        // insert), so a bare "evidence IS NOT NULL" check would dishonestly
        // mark every cemetery verified. RegistryMode::AUTHORITATIVE is the
        // real, meaningful signal here — its own doc comment: "An
        // authoritative, EVIDENCED registry exists", and it is explicitly
        // "never set by this batch's seed data." Using it (not a new rule
        // invented for this ticket) means this section is honestly EMPTY
        // against today's real seed data, exactly like the original
        // featured-cemeteries section was before its own dummy-data
        // backfill unblocked it — a real, named current-state gap, not a
        // bug.
        $verifiedCemeteries = new Collection;
        $verifiedCemeteriesUnavailable = false;

        try {
            $verifiedCemeteries = Cemetery::published()
                ->whereHas('capabilityProfiles', function ($query): void {
                    // Inlines CemeteryCapabilityProfile::scopeCurrent()'s own
                    // `whereNull('superseded_at')` condition rather than
                    // calling the scope through this closure: Larastan
                    // resolves the closure's $query parameter as a generic
                    // Builder<Model>, not Builder<CemeteryCapabilityProfile>,
                    // so it cannot see the custom scope method (real CI
                    // failure: "Call to an undefined method ...::current()").
                    // Same condition, no scope-resolution type gap.
                    $query->whereNull('superseded_at')->where('registry_mode', RegistryMode::AUTHORITATIVE);
                })
                ->orderBy('city')
                ->orderBy('name')
                ->take(6)
                ->get();
        } catch (Throwable $e) {
            report($e);
            $verifiedCemeteriesUnavailable = true;
        }

        return view('livewire.public.home-page', [
            'urgentMode' => $urgentMode,
            'faqHighlights' => $faqHighlights,
            'faqHighlightsUnavailable' => $faqHighlightsUnavailable,
            'urgentAvailabilityCemeteries' => $urgentAvailabilityCemeteries,
            'urgentAvailabilityCemeteriesUnavailable' => $urgentAvailabilityCemeteriesUnavailable,
            'newestPublishedCemeteries' => $newestPublishedCemeteries,
            'newestPublishedCemeteriesUnavailable' => $newestPublishedCemeteriesUnavailable,
            'verifiedCemeteries' => $verifiedCemeteries,
            'verifiedCemeteriesUnavailable' => $verifiedCemeteriesUnavailable,
            'primaryMenus' => self::PRIMARY_MENUS,
        ])->layout('layouts.app', [
            // No unsubstantiated superlative ("terpercaya"/"terbaik") in the
            // title — same honesty discipline this codebase already applies
            // to Urgent/hotline/cemetery-fixture copy, extended to page
            // metadata that is easy to overlook as "just marketing copy".
            'title' => 'Makam.co.id - Pemesanan dan Layanan Pemakaman',
            'active' => null,
            // <x-mk.bottom-nav>'s own five keys are 'beranda' | 'pemesanan'
            // | 'perpanjangan' | 'akun' | 'bantuan' — a distinct vocabulary
            // from <x-mk.header>'s 'active' above. The homepage is Beranda.
            'bottomNavActive' => 'beranda',
        ]);
    }
}

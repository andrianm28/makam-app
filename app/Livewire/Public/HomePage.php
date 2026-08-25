<?php

declare(strict_types=1);

namespace App\Livewire\Public;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\Faq\FaqPublicQuery;
use App\Platform\Analytics\MenuInteractionRecorder;
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
     * The CLICK side of AC9 is NOT recorded anywhere in this batch — a
     * real, named gap. See `2026_07_26_200000_create_menu_interaction_
     * events_table.php`'s own doc block and this batch's final report.
     */
    public function mount(): void
    {
        foreach (self::PRIMARY_MENUS as $menuKey => $menu) {
            MenuInteractionRecorder::impression($menuKey, $menu['route']);
        }
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

        // IA §3 item 5 "TPU/TPS unggulan/tersedia bila data ada" — REVERSED
        // 26 Jul 2026 from this class's original "deliberately NOT queried"
        // stance. That original reasoning (see git history on this method)
        // was sound at S4-T1/S4-T3 time: the only rows were ten fictional
        // seed fixtures with NULL price/photo/coordinates, and presenting
        // them as "featured" would have shown fabricated content as real.
        // The premise changed by explicit user authorization, the same one
        // `App\Support\ContactInfo`'s own doc block documents: `dev.makam.
        // co.id` is a real, intentionally public, non-production host
        // (docs/operations/dev-staging-environment.md, ADR-0031) where
        // clearly-fictional DUMMY data is the correct content type to
        // render end-to-end, not a fabrication risk. `2026_07_26_210000_
        // backfill_dummy_map_price_and_photo_for_seeded_cemeteries.php`
        // backfilled price/photo/coordinates for exactly this purpose.
        //
        // Same §6.3 provider-unavailable discipline as the FAQ highlights
        // query above: a secondary panel failing must never take the whole
        // homepage down. design-system.md §6.2's required-states row for
        // this section ("Featured cemeteries absent (homepage §5) | Hide
        // the section entirely") still governs the truly-empty case (e.g.
        // every cemetery unpublished later) — that branch is kept in the
        // view even though unreachable against today's seed data.
        $featuredCemeteries = new Collection;
        $featuredCemeteriesUnavailable = false;

        try {
            $featuredCemeteries = Cemetery::published()
                ->orderBy('city')
                ->orderBy('name')
                ->take(6)
                ->get();
        } catch (Throwable $e) {
            report($e);
            $featuredCemeteriesUnavailable = true;
        }

        return view('livewire.public.home-page', [
            'urgentMode' => $urgentMode,
            'faqHighlights' => $faqHighlights,
            'faqHighlightsUnavailable' => $faqHighlightsUnavailable,
            'featuredCemeteries' => $featuredCemeteries,
            'featuredCemeteriesUnavailable' => $featuredCemeteriesUnavailable,
            'primaryMenus' => self::PRIMARY_MENUS,
        ])->layout('layouts.app', [
            // No unsubstantiated superlative ("terpercaya"/"terbaik") in the
            // title — same honesty discipline this codebase already applies
            // to Urgent/hotline/cemetery-fixture copy, extended to page
            // metadata that is easy to overlook as "just marketing copy".
            'title' => 'Makam.co.id - Pemesanan dan Layanan Pemakaman',
            'active' => null,
        ]);
    }
}

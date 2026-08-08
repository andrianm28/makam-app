<?php

use App\Livewire\Public\ComingSoon\BookingWizardComingSoon;
use App\Livewire\Public\Directory\CemeteryDetail;
use App\Livewire\Public\Directory\CemeteryDirectoryIndex;
use App\Livewire\Public\Faq\FaqArticleDetail;
use App\Livewire\Public\Faq\FaqIndex;
use App\Livewire\Public\HomePage;
use App\Livewire\Public\Legal\PrivacyPolicy;
use App\Livewire\Public\Legal\TermsOfService;
use App\Livewire\Public\Marketplace\MarketplaceIndex;
use App\Livewire\Public\Marketplace\ProductDetail;
use App\Livewire\Public\Renewal\GraveSearch;
use App\Livewire\Public\Renewal\RenewalStart;
use App\Livewire\Public\Support\HelpCentre;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
| The canonical route contract is docs/product/information-architecture.md §1.
| Routes are added as their feature specs are implemented; see
| docs/planning/sprint-plan.md §2.4 for which sprint owns which spec.
|
| The four MVP entry points (mvp-scope.md §1) — current status:
|   /pemesanan-makam   Sprint 4  public-booking-wizard              STUB (S4-T3): honest
|                                "coming soon" page, not gated — real wizard is S4-T4
|   /marketplace       Sprint 4  funeral-marketplace-and-vendor-portal implemented (S4-T8,
|                                08 Aug 2026) — browse only; see MarketplaceIndex's own
|                                doc block for what is deliberately still missing (cart,
|                                checkout, vendor portal — Sprint 11-12)
|   /perpanjangan      Sprint 4  renewal-and-grave-registry          implemented (S4-T7,
|                                08 Aug 2026) — AC1-AC5, AC14 only; steps 4-6 (fee,
|                                payment, confirmation) are real stepper entries with no
|                                screen behind them yet, per RenewalStart's own doc block
|   /faq               Sprint 4  public-faq                          implemented (S4-T2)
|   /                  Sprint 4  public-home-and-navigation          implemented (S4-T3,
|                                this batch) — homepage now serves real content; see
|                                App\Livewire\Public\HomePage's own doc block.
|
| Laravel's default welcome page was deliberately removed: it hardcodes colours
| and Tailwind arbitrary values, which the ci/verify-docs.sh token gates reject.
| `/` now serves the real HomePage component — AGENTS.md's four-fixed-order-
| services requirement is what HomePage + <x-mk.header> together implement,
| not a placeholder.
|
| The one remaining STUB route below returns a real Livewire full-page
| component rendering an honest "coming soon" state (200 OK, header + footer
| intact), never Laravel's default 404 — requirements.md AC6 read expansively;
| see resources/views/livewire/public/coming-soon.blade.php's own doc block for
| why this is deliberately NOT <x-mk.gate-closed-page> (that component's
| copy assumes a real closed FEATURE GATE, which does not apply here — this
| route is simply not built yet this sprint). A stub route is expected to be
| REPLACED wholesale by its owning spec's real routes, not extended in place
| — `/marketplace` and `/perpanjangan` have now both gone through that
| replacement; `MarketplaceComingSoon` and `RenewalComingSoon`
| (app/Livewire/Public/ComingSoon/) are now dead code, deliberately left in
| place rather than deleted in this same change (no test depends on either;
| removing them is separable cleanup).
*/

Route::get('/', HomePage::class)->name('home');

Route::get('/pemesanan-makam', BookingWizardComingSoon::class)->name('pemesanan-makam.index');

/*
|--------------------------------------------------------------------------
| Marketplace — funeral-marketplace-and-vendor-portal AC1-AC3 (S4-T8)
|--------------------------------------------------------------------------
| Browse only, built by an agent-team teammate (marketplace-builder) and
| independently reviewed (spec + design lenses) before wiring, per this
| project's standing practice of never committing on a batch's self-report
| alone. Both reviews: every specific claim MET; the only gap was these two
| lines not existing yet.
|
| Read-only. Both components resolve catalogue data exclusively through
| App\Domain\Marketplace\MarketplaceCatalogQuery — never Product::query()
| directly. Category filtering uses `?kategori=<KEY>` with the three raw
| MarketplaceProductCategory keys (FLOWERS/GRAVESTONES/GRAVE_CARE); a
| `/marketplace/kategori/{categorySlug}` route is deliberately NOT
| registered — marketplace-catalog.md defines nine product codes and ZERO
| category slugs, and inventing an Indonesian slug would mint new canonical
| catalogue data, which AGENTS.md forbids. Product detail routes by
| ProductCode, not a slug — `products` has no slug column
| (information-architecture.md's `/marketplace/produk/{productSlug}` is
| stale against the schema; see MarketplaceCatalogQuery::findActiveByCode()'s
| own doc block, which already anticipated this exact route shape).
*/
Route::get('/marketplace', MarketplaceIndex::class)->name('marketplace.index');
Route::get('/marketplace/produk/{productCode}', ProductDetail::class)->name('marketplace.product');

/*
|--------------------------------------------------------------------------
| Cemetery directory — cemetery-directory-and-availability AC1-AC12 (S4-T6)
|--------------------------------------------------------------------------
| Built by an agent-team teammate (directory-builder) and independently
| reviewed (spec + design lenses) before wiring. Both reviews: every
| specific claim MET; the only gap was these two lines not existing yet.
|
| Path chosen (not fixed by information-architecture.md §1, which has no
| directory entry at all — a real, separate documentation gap, tracked
| rather than silently patched): `/cemeteries`, matching the noun
| docs/contracts/openapi.yaml already uses for `GET /cemeteries` and
| `GET /cemeteries/{cemeteryId}`, so this introduces no new vocabulary.
| Every internal link and every test resolves via `route('cemeteries.…')`,
| never a literal path, so only the two NAMES below are load-bearing.
|
| Read-only. Resolves capability data exclusively through
| App\Livewire\Public\Directory\Support\CemeteryDirectoryQuery, which
| composes Cemetery::published() and ResolveCemeteryCapabilityProfile
| rather than querying either directly. AC12's public capability
| projection is structurally incapable of leaking `registry_mode` or
| `certificate_mode` — see PublicCapabilityProjection's own doc block.
*/
Route::get('/cemeteries', CemeteryDirectoryIndex::class)->name('cemeteries.index');
Route::get('/cemeteries/{cemeterySlug}', CemeteryDetail::class)->name('cemeteries.show');

/*
|--------------------------------------------------------------------------
| Renewal — renewal-and-grave-registry AC1-AC5, AC14 (S4-T7)
|--------------------------------------------------------------------------
| Built by an agent-team teammate (renewal-builder) and independently
| reviewed (spec + design lenses, read directly against the code — the two
| review subagents this batch would normally use both failed on a session
| API limit, so this review was done manually against the same checklist)
| before wiring. Every claim checked: the three empty states are enforced
| structurally in GraveSearchOutcome (gate-closed is not even representable
| by it — that state is the search never having run), a `closed`-mode
| record is matched and counted, never dropped, the six stepper labels come
| from RenewalJourneyStep and stepper.blade.php itself is untouched, the
| migration is additive-only and does not swallow a real pg_trgm failure,
| and heir_contact_reference is projected by no access mode at all.
|
| Replaces `RenewalComingSoon` wholesale, per that stub's own doc block.
| Paths from information-architecture.md §1's route tree. `/perpanjangan`
| is Step 1-2 (city, cemetery); `/perpanjangan/cari` is Step 3 (grave
| search) — steps 4-6 (fee, payment, confirmation) are Sprint 13 per
| sprint-plan.md §9 and render as real, visible, not-yet-reachable stepper
| entries rather than being hidden (§6.9: a closed/future gate never
| removes a required MVP step).
|
| Read-only. Both components resolve exclusively through
| App\Domain\GraveRegistry\GraveRegistryPublicQuery and
| App\Domain\Renewal\RenewalLocationQuery — never a GraveRecord or Cemetery
| model directly. G-DATA-01 is read server-side via ModeResolver, matching
| every other gated surface in this codebase.
*/
Route::get('/perpanjangan', RenewalStart::class)->name('perpanjangan.index');
Route::get('/perpanjangan/cari', GraveSearch::class)->name('perpanjangan.cari');

/*
|--------------------------------------------------------------------------
| FAQ — public-faq spec (design.md's Routes section)
|--------------------------------------------------------------------------
| Read-only. Both FaqIndex routes and FaqArticleDetail resolve their data
| exclusively through App\Domain\Faq\FaqPublicQuery / FaqArticle::published()
| — see those classes' own doc blocks for the AC6 guarantee. `/faq/kategori/
| {categorySlug}` (3 segments) and `/faq/{articleSlug}` (2 segments) never
| collide regardless of registration order, since Laravel routes match on
| segment count/shape, not first-match-wins ambiguity here — the more
| specific route is still registered first for readability.
*/
Route::get('/faq', FaqIndex::class)->name('faq.index');
Route::get('/faq/kategori/{categorySlug}', FaqIndex::class)->name('faq.category');
Route::get('/faq/{articleSlug}', FaqArticleDetail::class)->name('faq.show');

/*
|--------------------------------------------------------------------------
| Legal — privacy policy and terms of service
|--------------------------------------------------------------------------
| Closes a real, previously-documented gap: layouts/app.blade.php's footer
| has linked to /privasi and /syarat-ketentuan since the homepage footer
| was upgraded (26 Jul 2026), but neither route existed, so both links
| 404d — see that file's own (now-updated) doc comment. Read-only, no
| App\Domain\** dependency, same shape as the FAQ routes above.
|
| The body copy these two routes serve is placeholder/draft legal content,
| explicitly labelled as such on both pages — see App\Livewire\Public\
| Legal\PrivacyPolicy's own doc block for why (sprint-plan.md §10: "legal/
| privacy decisions (G7)" are "Not delegable to an agent at all").
*/
Route::get('/privasi', PrivacyPolicy::class)->name('legal.privacy');
Route::get('/syarat-ketentuan', TermsOfService::class)->name('legal.terms');

/*
|--------------------------------------------------------------------------
| Bantuan — PUB-060, the customer-service escape hatch
|--------------------------------------------------------------------------
| Closes a real defect found 8 Aug 2026, the same class as the /privasi and
| /syarat-ketentuan gap closed on 26 Jul 2026 — a link shipped ahead of its
| destination, except this one shipped on EVERY page.
|
| <x-mk.header> renders a persistent "Bantuan" action on both the mobile and
| desktop bars (information-architecture.md §2 makes it mandatory and
| forbids collapsing it into the hamburger), and seven further views link
| /bantuan: layouts/app.blade.php's footer, both FAQ views, both legal
| views, the coming-soon stub, and the homepage. No route backed it, so the
| one affordance design-system.md §6.10 requires on every transactional
| screen 404d from every screen — and product-brief.md §5 point 10 ("Semua
| halaman memiliki customer-service escape hatch") was unmet site-wide.
|
| Deliberately static: no domain read, no form. This is where §6.5's
| provider-unavailable copy and the FAQ's empty state send a stuck user, so
| it must still render when the database is down. See App\Livewire\Public\
| Support\HelpCentre's own doc block for the full reasoning, including why
| no contact form exists and why no wa.me deep link is minted.
*/
Route::get('/bantuan', HelpCentre::class)->name('bantuan.index');

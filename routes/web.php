<?php

use App\Livewire\Public\Faq\FaqArticleDetail;
use App\Livewire\Public\Faq\FaqIndex;
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
|   /pemesanan-makam   Sprint 4  public-booking-wizard              NOT YET implemented
|   /marketplace       Sprint 4  funeral-marketplace-and-vendor-portal NOT YET implemented
|   /perpanjangan      Sprint 4  renewal-and-grave-registry          NOT YET implemented
|   /faq               Sprint 4  public-faq                          implemented (S4-T2, this batch)
|
| Laravel's default welcome page was deliberately removed: it hardcodes colours
| and Tailwind arbitrary values, which the ci/verify-docs.sh token gates reject.
| Nothing should be served from `/` until public-home-and-navigation is built —
| AGENTS.md requires the homepage to present exactly four services in a fixed
| order, so a placeholder here would be a false product claim.
*/

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

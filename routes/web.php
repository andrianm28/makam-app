<?php

use App\Http\Controllers\Admin\DisableMfaController;
use App\Http\Controllers\Admin\FinanceExportController;
use App\Http\Controllers\DocumentVault\DownloadDocumentController;
use App\Http\Middleware\EnforceMfaChallenge;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Livewire\Public\Booking\BookingWizard;
use App\Livewire\Public\Directory\CemeteryDetail;
use App\Livewire\Public\Directory\CemeteryDirectoryIndex;
use App\Livewire\Public\Faq\FaqArticleDetail;
use App\Livewire\Public\Faq\FaqIndex;
use App\Livewire\Public\HomePage;
use App\Livewire\Public\Legal\PrivacyPolicy;
use App\Livewire\Public\Legal\TermsOfService;
use App\Livewire\Public\Marketplace\Cart;
use App\Livewire\Public\Marketplace\Checkout;
use App\Livewire\Public\Marketplace\MarketplaceIndex;
use App\Livewire\Public\Marketplace\OrderTracking;
use App\Livewire\Public\Marketplace\ProductDetail;
use App\Livewire\Public\Renewal\GraveSearch;
use App\Livewire\Public\Renewal\RenewalConfirmation;
use App\Livewire\Public\Renewal\RenewalFee;
use App\Livewire\Public\Renewal\RenewalPayment;
use App\Livewire\Public\Renewal\RenewalStart;
use App\Livewire\Public\Support\HelpCentre;
use App\Platform\Payment\Http\Controllers\PaymentCancelController;
use App\Platform\Payment\Http\Controllers\PaymentReturnController;
use App\Platform\Payment\Http\Controllers\RecordPaymentReversalController;
use App\Platform\Payment\Http\Controllers\VerifyManualPaymentController;
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
|   /pemesanan-makam   Sprint 4  public-booking-wizard              implemented — S4-T4/S4-T5
|                                (08 Aug 2026) built steps 1-5; steps 6-9 landed with
|                                lane/l6-booking-completion (presentation) and
|                                lane/l7-order-orchestration (domain), both merged
|                                13 Aug 2026, so all nine steps are real screens — see
|                                BookingWizard's own doc block
|   /marketplace       Sprint 4  funeral-marketplace-and-vendor-portal implemented (S4-T8,
|                                08 Aug 2026) — browse only; the vendor portal shipped
|                                separately with lane/l10-vendor-portal (merged 13 Aug
|                                2026, Filament panel at /vendor); see MarketplaceIndex's
|                                own doc block for what is still deliberately missing
|                                (cart, checkout — lane/l11-marketplace-checkout, unmerged)
|   /perpanjangan      Sprint 4  renewal-and-grave-registry          implemented — S4-T7
|                                (08 Aug 2026) covered AC1-AC5 + AC14; steps 4-6 (fee,
|                                payment, confirmation, AC6-AC9) landed with
|                                lane/l8-renewal-completion (merged 13 Aug 2026), so all
|                                six steps are real screens — see RenewalStart's own
|                                doc block
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
| Every MVP entry point above now serves a real route. A stub route is
| expected to be REPLACED wholesale by its owning spec's real routes, not
| extended in place — `/pemesanan-makam`, `/marketplace`, and `/perpanjangan`
| have now all gone through that replacement; `BookingWizardComingSoon`,
| `MarketplaceComingSoon`, and `RenewalComingSoon`
| (app/Livewire/Public/ComingSoon/) are now dead code, deliberately left in
| place rather than deleted in this same change (no test depends on either;
| removing them is separable cleanup). The stub pattern itself (a real
| Livewire full-page component rendering an honest "coming soon" state —
| 200 OK, header + footer intact, never Laravel's default 404 — per
| requirements.md AC6 read expansively) is still documented in
| resources/views/livewire/public/coming-soon.blade.php's own doc block,
| including why it is deliberately NOT <x-mk.gate-closed-page>.
*/

Route::get('/', HomePage::class)->name('home');

/*
|--------------------------------------------------------------------------
| Booking wizard — public-booking-wizard AC1-AC6, AC11-AC13 (S4-T4/S4-T5,
| resumed 08 Aug 2026) + booking-and-order-orchestration AC2, AC3
|--------------------------------------------------------------------------
| All nine steps implemented. Steps 1-5 landed S4-T4/S4-T5 (resumed
| 08 Aug 2026); steps 6-9 followed with lane/l6-booking-completion
| (presentation) and lane/l7-order-orchestration (domain), both merged
| 13 Aug 2026 — BookingWizardStep::LAST_IMPLEMENTED = CONFIRMATION,
| saveStep6()/saveStep7()/saveStep8() are real Livewire actions over
| SaveBookingDraftStep, and Step 9 renders the order read model.
| REPLACES the BookingWizardComingSoon stub — see that class's own doc
| block and this file's top-of-file note on stub replacement.
*/
Route::get('/pemesanan-makam', BookingWizard::class)->name('pemesanan-makam.index');
Route::redirect('/pemesanan-makam/baru', '/pemesanan-makam')->name('pemesanan-makam.new');
Route::get('/pemesanan-makam/draft/{draftId}', BookingWizard::class)->name('pemesanan-makam.draft');

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
Route::get('/marketplace/keranjang', Cart::class)->name('marketplace.cart');
Route::get('/marketplace/checkout', Checkout::class)->name('marketplace.checkout');
Route::get('/marketplace/pesanan/{orderNumber}', OrderTracking::class)->name('marketplace.order.tracking');

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
| App\Domain\CemeteryDirectory\CemeteryPublicQuery and
| App\Livewire\Public\Directory\Support\PublicCapabilityProjection, which
| compose Cemetery::published() and ResolveCemeteryCapabilityProfile
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
| search). Steps 4-6 (fee, payment, confirmation) landed with lane L8
| (docs/superpowers/plans/2026-08-12-platform-renewal-completion.md) and are
| now real screens — this block previously described them as Sprint 13
| stepper entries with nothing behind them.
|
| Every one of these five routes is a GET, and none of them writes. Step 4's
| screen calculates its quote without persisting; the only write on the whole
| public journey is Domain\Renewal\Actions\OpenRenewal, reached from the
| family's explicit acceptance (a Livewire action, i.e. a POST) on step 4.
|
| AC14 field projection is enforced in the components, not the templates: the
| directory and search screens resolve through
| App\Domain\GraveRegistry\GraveRegistryPublicQuery and
| App\Domain\CemeteryDirectory\CemeteryPublicQuery, and the fee screen reduces
| its grave to a GraveRecordProjection before anything renders, so no public
| surface holds a GraveRecord model. G-DATA-01 is read server-side via
| ModeResolver, matching every other gated surface in this codebase.
|
| Steps 5 and 6 are reached by an unguessable renewal UUID and are readable by
| whoever holds it — deliberate for an anonymous journey with no accounts, and
| bounded by the fact that neither screen projects any grave record field.
*/
Route::get('/perpanjangan', RenewalStart::class)->name('perpanjangan.index');
Route::get('/perpanjangan/cari', GraveSearch::class)->name('perpanjangan.cari');
Route::get('/perpanjangan/biaya', RenewalFee::class)->name('perpanjangan.biaya');
Route::get('/perpanjangan/pembayaran', RenewalPayment::class)->name('perpanjangan.pembayaran');
Route::get('/perpanjangan/konfirmasi', RenewalConfirmation::class)->name('perpanjangan.konfirmasi');

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

/*
|--------------------------------------------------------------------------
| Admin — MFA disable (Task 6, mfa-reauthentication-integration)
|--------------------------------------------------------------------------
| Not a Filament panel page (those auto-register their own routes via
| `->pages()` in `AdminPanelProvider`) — a plain controller route, matching
| `RequireRecentAuthentication`'s own doc block's literal `Route::post(...)`
| example. Lives here, in the standard `web` group, rather than inside the
| `/admin` panel's own middleware group, so it needs its own explicit `auth`
| guard.
|
| `RequireRecentAuthentication::class.':mfa_disable,filament.admin.pages.
| mfa-challenge'` is this middleware's first real attachment anywhere in
| this repo (S3-T3 built it fully audited and tested, but never wired) — a
| stale or absent `ActorContext::$lastAuthenticatedAt` redirects here to
| `MfaChallenge` instead of letting the disable through.
*/
Route::post('/admin/mfa/disable', DisableMfaController::class)
    ->middleware(['web', 'auth', RequireRecentAuthentication::class.':mfa_disable,filament.admin.pages.mfa-challenge'])
    ->name('admin.mfa.disable');

/*
|--------------------------------------------------------------------------
| Admin — bulk financial export (L4 Task 6, AC13)
|--------------------------------------------------------------------------
| Registered here rather than as a Filament page because the response is a
| streamed file download, not a rendered page. That placement is what made
| the route miss the panel's own middleware stack, so the two protections it
| would have inherited are attached explicitly:
|
|   - `EnforceMfaChallenge` — a no-op for an actor with no confirmed MFA
|     enrolment, and for an enrolled actor the same per-session challenge the
|     panel requires (`AdminPanelProvider`).
|   - `RequireRecentAuthentication` — session-freshness only. It is NOT the
|     authorization check: `Actions\BulkFinancialExport` owns the finance
|     policy and the query-level badan-usaha scope, so a non-HTTP caller is
|     gated identically and no route configuration can widen it.
|
| `throttle:financial-export` mirrors `internal.documents.download` below: a
| bulk export of a period's books deserves at least the rate limit a single
| document download already carries.
*/
Route::get('/admin/finance/exports', FinanceExportController::class)
    ->middleware([
        'web',
        'auth',
        'throttle:financial-export',
        EnforceMfaChallenge::class,
        RequireRecentAuthentication::class.':bulk_financial_export,filament.admin.pages.mfa-challenge',
    ])
    ->name('admin.finance.exports');

/*
|--------------------------------------------------------------------------
| Payment provider browser return — platform-payment-adapter AC4
|--------------------------------------------------------------------------
| ADR-0033 lets a hosted checkout be created with `success_return_url` and
| `cancel_return_url`. These are the two routes the CUSTOMER'S BROWSER is
| redirected back to afterwards. They are NOT the provider's callback — that
| is `POST /api/payments/webhook/{merchant}` in routes/api.php, which is
| signed, merchant-scoped, replay-protected and durable.
|
| AGENTS.md §Domain and financial invariants: "Never mark paid from browser
| return URL." Requirements AC4 says the same in EARS form and adds the
| negative criterion: "No paid state from a browser return, a client
| callback, or a notification."
|
| The enforcement is route-controller separation, and it is why these two are
| plain view-rendering controllers rather than Livewire components: a
| Livewire component exposes callable actions to the browser, which is
| exactly the surface AC4 wants absent. Neither controller takes a
| dependency on any model or action; the only thing they read is two
| display-only lookup keys (`session`, `payment_id`) delegated to
| `ReturnPageState::fromRequest()` — read its doc block and
| tests/Feature/Payment/PaymentReturnRouteTest.php, which fails if a
| write-shaped name appears in either file or if hitting either route moves
| any row.
|
| Two routes, two controllers, no shared parameter deciding which outcome to
| render: a single endpoint branching on a query parameter would put the
| visitor in charge of which page they are shown.
|
| Paths follow information-architecture.md §1's Indonesian public route tree.
| Screen PUB-019 (screen-inventory.md §A).
*/
Route::get('/pembayaran/kembali', PaymentReturnController::class)->name('payments.return');
Route::get('/pembayaran/batal', PaymentCancelController::class)->name('payments.cancel');

/*
|--------------------------------------------------------------------------
| Admin — manual payment verification (Task 5, Wave 1c Append-Correction)
|--------------------------------------------------------------------------
| AC8 ("Admin verification SHALL be a separate authorized action") and AC9
| (recent re-authentication). `RequireRecentAuthentication`'s SECOND real
| attachment anywhere in this repo — the first is `/admin/mfa/disable`
| above, and this route follows its exact precedent: a plain controller
| route (not a Filament panel page) in the standard `web` group, its own
| explicit `auth` guard, and the same `filament.admin.pages.mfa-challenge`
| challenge destination (no dedicated payment-verification challenge page
| exists, and this task does not invent one).
|
| Approves/rejects a `payment_verifications` row — a NEW, self-contained
| table with no foreign key to `payment_sessions` or any order/booking
| table (see the migration's own doc block). Does NOT transition
| `payment_sessions`, post a journal entry, or write any order/invoice
| status — see `App\Platform\Payment\VerifyManualPayment`'s own doc block
| for why those remain structurally absent under the current deny-only
| payment guard (Wave 1b ruling 1b-L3-01).
*/
Route::post('/admin/payments/manual-verifications/{paymentVerification}/verify', VerifyManualPaymentController::class)
    ->middleware(['web', 'auth', RequireRecentAuthentication::class.':payment_manual_verification,filament.admin.pages.mfa-challenge'])
    ->name('admin.payments.manual-verifications.verify');

/*
|--------------------------------------------------------------------------
| Admin — payment reversals (Task 6, Wave 1d Append-Correction)
|--------------------------------------------------------------------------
| `RequireRecentAuthentication`'s THIRD real attachment anywhere in this
| repo — after `/admin/mfa/disable` and
| `/admin/payments/manual-verifications/{id}/verify` above, following their
| exact precedent: a plain controller route in the standard `web` group,
| its own explicit `auth` guard, and the same
| `filament.admin.pages.mfa-challenge` challenge destination.
|
| Records a `payment_reversals` row (`REFUND` or `CHARGEBACK`) — a NEW,
| self-contained table decoupled from `Journal`, `payment_sessions`, any
| order aggregate, and `payment_verifications` (see the migration's own
| doc block). Does NOT post a journal reversal batch, call
| `PaymentProvider::refund()`, or touch any customer-balance concept — see
| `App\Platform\Payment\Actions\RecordRefund`'s own doc block for why those
| remain structurally absent.
|
| One parameterized route for both reversal types (`{reversalType}`
| constrained to `refund|chargeback`) rather than two separate routes — a
| flagged judgement call, see
| `App\Platform\Payment\Http\Controllers\RecordPaymentReversalController`'s
| own doc block for why.
*/
Route::post('/admin/payments/reversals/{reversalType}', RecordPaymentReversalController::class)
    ->whereIn('reversalType', ['refund', 'chargeback'])
    ->middleware(['web', 'auth', RequireRecentAuthentication::class.':payment_reversal,filament.admin.pages.mfa-challenge'])
    ->name('admin.payments.reversals.record');

Route::get('/internal/documents/{document}/download/{token}', DownloadDocumentController::class)
    ->middleware(['web', 'auth', 'throttle:document-download'])
    ->name('internal.documents.download');

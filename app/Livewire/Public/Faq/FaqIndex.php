<?php

declare(strict_types=1);

namespace App\Livewire\Public\Faq;

use App\Domain\Faq\FaqPublicQuery;
use App\Domain\Faq\Models\FaqCategory;
use App\Livewire\Public\Faq\Support\FaqCategorySlug;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * `/faq` and `/faq/kategori/{categorySlug}` — Sprint 4 S4-T2 `public-faq`.
 * One component handles both routes (`$categorySlug` is `null` on the
 * bare `/faq` route, a validated category slug on the category route) —
 * .kiro/specs/public-faq's own brief calls this "your call" and names this
 * shape as the suggested one; kept as one class because the two routes
 * differ only in "which published articles are listed", not in any other
 * behaviour (search, states, layout are all identical).
 *
 * Read-only. Every article/category read in this class goes through
 * `FaqPublicQuery` — never a bare `FaqArticle::query()`/`FaqCategory::
 * query()` call. See `FaqPublicQuery`'s and `FaqArticle`'s own class-level
 * doc blocks for the AC6 guarantee this composes with.
 *
 * ---------------------------------------------------------------------------
 * Category filter and free-text search are ALTERNATE mechanisms, not
 * composable ones — a deliberate reading of AC3, not a shortcut
 * ---------------------------------------------------------------------------
 * requirements.md AC3: "WHEN a user selects a category filter OR submits a
 * search query THE SYSTEM SHALL filter or search ... accordingly" — "or",
 * not "and". `FaqPublicQuery` exposes `articlesInCategory()` and `search()`
 * as two separate methods with no combined "search within category"
 * method, and this batch does not touch `app/Domain/Faq/**` to add one.
 * Reimplementing `FaqArticle::scopeMatching()`'s substring logic here in
 * the presentation layer (to combine it with a category filter client-side)
 * would duplicate domain logic and risk the two silently drifting apart —
 * worse than just treating them as alternates. Submitting a search clears
 * any active category (navigates to `/faq?q=...`); selecting a category
 * chip is a plain link to `/faq/kategori/{slug}` and is unaffected by
 * whatever the search box currently holds, because it is a fresh page
 * request.
 *
 * Layout is attached per-render via `->layout('layouts.app', [...])` (not
 * the `#[Layout]` class attribute) so the `<title>` and header `active`
 * state can vary with which category, if any, is active — see `render()`.
 */
final class FaqIndex extends Component
{
    public ?string $categorySlug = null;

    #[Url(as: 'q', history: true)]
    public string $term = '';

    /**
     * §6.5 "Provider unavailable" — see class doc block's honest-limits
     * note in this batch's final report. Set true only when the search
     * QUERY ITSELF throws; the rest of the page (categories, nav) already
     * rendered from data fetched earlier in render(), so it keeps working.
     */
    public bool $searchUnavailable = false;

    public function mount(?string $categorySlug = null): void
    {
        $this->categorySlug = $categorySlug;

        if ($categorySlug !== null && $this->resolveCategoryBySlug($categorySlug) === null) {
            // Unknown category slug — genuinely not found, same "no
            // distinction between unknown and hidden" discipline as AC6's
            // article-slug 404 (there is no "hidden" category concept to
            // protect here, but a raw 404 is still the honest response to
            // a URL that names nothing real).
            abort(404);
        }
    }

    /**
     * §3.2/§6.3-shaped validation — the search box is this page's one and
     * only form. `max:200` is a generous ceiling (no real FAQ search phrase
     * approaches it); its only purpose is giving this page a genuine,
     * exercisable validation-error state rather than a form with nothing
     * that can ever fail validation.
     *
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'term' => ['nullable', 'string', 'max:200'],
        ];
    }

    public function search(): void
    {
        $this->validate();
    }

    public function resetSearch(): void
    {
        $this->reset('term');
        $this->resetValidation();
    }

    private function resolveCategoryBySlug(string $slug): ?FaqCategory
    {
        return FaqPublicQuery::categories()
            ->first(fn (FaqCategory $category): bool => FaqCategorySlug::matches($category, $slug));
    }

    public function render(): View
    {
        $categories = FaqPublicQuery::categories();
        $activeCategory = $this->categorySlug !== null
            ? $this->resolveCategoryBySlug($this->categorySlug)
            : null;

        $term = trim($this->term);
        // Self-contained re-check of the same `max:200` ceiling `rules()`
        // enforces, rather than depending on `getErrorBag()` — a term this
        // long can only reach here either via a tampered `?q=` query string
        // (mount() never validates) or via a `search()` call whose
        // `validate()` already threw; either way, the query is simply not
        // run and `$errors->has('term')` (rendered by the view directly)
        // still surfaces the real validation message.
        $searchActive = $term !== '' && mb_strlen($term) <= 200;

        $this->searchUnavailable = false;
        $articles = new Collection;

        if ($searchActive) {
            try {
                $articles = FaqPublicQuery::search($term);
            } catch (Throwable $e) {
                report($e);

                // §6.5 fallback — never a dead end: fall back to plain
                // category browse (or the full list) and say so plainly.
                $this->searchUnavailable = true;
                $searchActive = false;
                $articles = $activeCategory !== null
                    ? FaqPublicQuery::articlesInCategory($activeCategory->id)
                    : FaqPublicQuery::allPublished();
            }
        } elseif ($activeCategory !== null) {
            $articles = FaqPublicQuery::articlesInCategory($activeCategory->id);
        } else {
            $articles = FaqPublicQuery::allPublished();
        }

        return view('livewire.public.faq.index', [
            'categories' => $categories,
            'activeCategory' => $activeCategory,
            'articles' => $articles,
            'searchActive' => $searchActive,
            'searchTerm' => $term,
        ])->layout('layouts.app', [
            'title' => match (true) {
                $searchActive => 'Hasil Pencarian - FAQ - Makam.co.id',
                $activeCategory !== null => $activeCategory->name.' - FAQ - Makam.co.id',
                default => 'FAQ - Makam.co.id',
            },
            'active' => 'faq',
        ]);
    }
}

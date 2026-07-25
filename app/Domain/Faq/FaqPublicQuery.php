<?php

declare(strict_types=1);

namespace App\Domain\Faq;

use App\Domain\Faq\Models\FaqArticle;
use App\Domain\Faq\Models\FaqCategory;
use Illuminate\Database\Eloquent\Collection;

/**
 * The recommended public-facing read entry point for FAQ content — a thin
 * wrapper around `FaqArticle`'s own scopes/helpers so a later batch building
 * `/faq`, `/faq/kategori/{categorySlug}`, `/faq/{articleSlug}` (`design.md`'s
 * Routes section) never needs to write a raw Eloquent query against
 * `FaqArticle` itself. Every method here is built from `FaqArticle::
 * published()` (directly or transitively) — see that model's own
 * class-level doc block for the full AC6 guarantee this composes with, not
 * around.
 *
 * This class is a convenience, not a NEW mechanism: everything it exposes
 * is also directly reachable via `FaqArticle`'s own public scopes/statics.
 * Prefer this class from outside `app/Domain/Faq/**` for the simplest
 * possible call sites; use the model's scopes directly only when composing
 * a query this class does not already anticipate (e.g. pagination, a future
 * batch's own additional filter) — and when doing so, ALWAYS start from
 * `FaqArticle::published()`, never a bare `FaqArticle::query()`.
 */
final class FaqPublicQuery
{
    /**
     * All six categories, in their stable public display order.
     *
     * @return Collection<int, FaqCategory>
     */
    public static function categories(): Collection
    {
        return FaqCategory::orderedForDisplay()->get();
    }

    /**
     * Published articles in one category, in display order. Never includes
     * a draft or unpublished article — see `FaqArticle::scopePublished()`.
     *
     * @return Collection<int, FaqArticle>
     */
    public static function articlesInCategory(int $categoryId): Collection
    {
        return FaqArticle::published()
            ->inCategory($categoryId)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * All published articles across every category, in category then
     * article display order — the `/faq` index route's listing.
     *
     * @return Collection<int, FaqArticle>
     */
    public static function allPublished(): Collection
    {
        return FaqArticle::published()
            ->join('faq_categories', 'faq_categories.id', '=', 'faq_articles.category_id')
            ->orderBy('faq_categories.sort_order')
            ->orderBy('faq_articles.sort_order')
            ->select('faq_articles.*')
            ->get();
    }

    /**
     * Published articles matching a simple case-insensitive search term
     * across title/summary/body — requirements.md AC3. Never returns a
     * draft or unpublished article (AC6) — see
     * `FaqArticle::scopeMatching()`'s own doc block for why this is a plain
     * portable `LIKE`, not `pg_trgm`/full-text, at this catalogue's MVP
     * scale. A blank `$term` returns every published article (AC8's
     * "no bare empty state" is a presentation concern for the later batch
     * that renders this list, not this method's own).
     *
     * @return Collection<int, FaqArticle>
     */
    public static function search(string $term): Collection
    {
        return FaqArticle::published()
            ->matching($term)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * The `/faq/{articleSlug}` detail route's lookup — `null` for a slug
     * that does not exist OR belongs to a draft/unpublished article. Never
     * distinguishes the two cases (both are AC6-required to look identical
     * to a public caller — "not found" either way, never "found but
     * hidden").
     */
    public static function findBySlug(string $slug): ?FaqArticle
    {
        return FaqArticle::publishedBySlug($slug);
    }
}

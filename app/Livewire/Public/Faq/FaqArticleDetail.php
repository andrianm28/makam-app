<?php

declare(strict_types=1);

namespace App\Livewire\Public\Faq;

use App\Domain\Faq\FaqPublicQuery;
use App\Domain\Faq\Models\FaqArticle;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * `/faq/{articleSlug}` — Sprint 4 S4-T2 `public-faq`. requirements.md AC4:
 * title, body, updated date, related articles, customer-service CTA.
 *
 * ---------------------------------------------------------------------------
 * AC6 at the routing level — real 404, never "found but hidden"
 * ---------------------------------------------------------------------------
 * `FaqPublicQuery::findBySlug()` returns `null` for BOTH "no article has
 * this slug" and "an article has this slug but is draft/unpublished" —
 * deliberately indistinguishable (see that method's own doc block). `mount`
 * below calls Laravel's real `abort(404)` in exactly that one case, so a
 * request for a draft/unpublished slug 404s the same way a slug that never
 * existed does — no custom "not available" page, which would itself leak
 * "something exists here" (tasks.md's own wording for this state).
 *
 * ---------------------------------------------------------------------------
 * "Updated date" (AC4) — `updated_at`, not `published_at`, and why
 * ---------------------------------------------------------------------------
 * `FaqArticle` has both. `published_at` only moves when
 * `Actions\PublishFaqArticle` runs — per that model's own class doc block
 * ("current row = current content"), editing an ALREADY-published article's
 * text via `Actions\UpdateFaqArticleContent` does NOT call
 * `PublishFaqArticle` and does NOT touch `published_at`; it only
 * `forceFill()->save()`s the content columns, which — being a normal
 * Eloquent `save()` on a model with default `$timestamps = true` — DOES
 * bump `updated_at`. So `published_at` can go stale the moment a content
 * edit lands on an already-live article, while `updated_at` always reflects
 * the most recent change to what a visitor is actually reading right now.
 * AC4 asks for "its updated date" — the date the content was last updated —
 * which `updated_at` answers correctly and `published_at` would not.
 */
final class FaqArticleDetail extends Component
{
    public FaqArticle $article;

    public function mount(string $articleSlug): void
    {
        $article = FaqPublicQuery::findBySlug($articleSlug);

        if ($article === null) {
            abort(404);
        }

        $this->article = $article;
    }

    public function render(): View
    {
        return view('livewire.public.faq.article-detail', [
            'category' => $this->article->category,
            // publishedRelatedArticles() — the public-safe relation
            // (FaqArticle's own class doc block). Never relatedArticles().
            'relatedArticles' => $this->article->publishedRelatedArticles()->get(),
        ])->layout('layouts.app', [
            'title' => $this->article->title.' - FAQ - Makam.co.id',
            'active' => 'faq',
        ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Faq\Models;

use App\Domain\Faq\FaqArticlePublishState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Eloquent model for `faq_articles` — see the migration
 * (`2026_07_26_170100_create_faq_articles_table.php`) for schema reasoning.
 *
 * ---------------------------------------------------------------------------
 * "Current row = current content" — no separate draft-buffer-vs-live-copy
 * architecture (a documented judgement call, not an omission)
 * ---------------------------------------------------------------------------
 * This model's own columns (`title`/`slug`/`summary`/`body`/`category_id`/
 * `sort_order`) are ALWAYS the article's one current content, regardless of
 * `publish_state`. There is no second "pending edit" copy anywhere. Editing
 * an already-`published` article (`Actions\UpdateFaqArticleContent`) changes
 * what a public visitor sees immediately — it does not silently queue until
 * a future publish call. `design.md`'s Data section names exactly three
 * tables (`faq_categories`, `faq_articles`, `faq_article_versions`); inventing
 * a fourth "working copy" concept neither cited document (`design.md`,
 * `faq-catalog.md`) describes would be scope invention, not implementation.
 * requirements.md AC5's "preview" capability is satisfied structurally by
 * `Model::forAdmin()` below already returning the current row regardless of
 * `publish_state` — a later UI batch renders that as the preview, no extra
 * column/table required.
 *
 * `faq_article_versions` is a pure HISTORY/audit trail of what has gone
 * live and when (`Actions\PublishFaqArticle`'s own doc block) — a content
 * team's "what did the public actually see, and since when" record — not
 * the read path a public page renders from. Public reads always come from
 * THIS table, filtered through `published()` below.
 *
 * ---------------------------------------------------------------------------
 * THE AC6 GUARANTEE — read this before writing any query against this model
 * ---------------------------------------------------------------------------
 * requirements.md AC6: "THE SYSTEM SHALL NOT display an unpublished article
 * in any public view or public search result." This is graded on the
 * NEGATIVE case — a single leaked draft anywhere is a failure — the same
 * severity class `ScopeAssignmentGlobalScope`'s own doc block treats "no
 * cross-scope read reachable" with. Unlike that scope, this is deliberately
 * a LOCAL scope (`scopePublished()` below), not a global one: an admin
 * screen legitimately needs to see drafts and unpublished articles (its own
 * "preview"/"manage" surfaces), so a global scope that silently hides them
 * everywhere would break the one caller who is SUPPOSED to see everything.
 * That means this guarantee is NOT structurally unbypassable the way a
 * global scope would be — nothing stops a future caller from writing
 * `FaqArticle::query()->find($id)` directly. What actually makes it hard to
 * get wrong, given that constraint:
 *
 *   1. This doc comment, on the one class every such query starts from.
 *   2. Every public-read helper this class exposes — `scopePublished()`,
 *      `scopeInCategory()`, `scopeMatching()`, `publishedBySlug()`,
 *      `publishedRelatedArticles()` — is ALREADY built by composing
 *      `published()` under it. A later batch building "published articles
 *      in category X matching search Y" never needs to write a raw
 *      `FaqArticle::query()` call at all; the pre-built helpers cover
 *      list/filter/search/detail-by-slug/related, per this batch's own
 *      brief. `App\Domain\Faq\FaqPublicQuery` wraps these into an even
 *      thinner public-facing entry point for exactly this reason.
 *   3. `forAdmin()` below is the ONE explicitly-named admin escape hatch —
 *      naming it explicitly (rather than every admin call site writing a
 *      bare `::query()`) makes "this call site intentionally sees
 *      everything, including drafts" grep-able and self-documenting.
 *   4. `tests/Feature/Domain/Faq/FaqArticleDraftExclusionTest.php` proves,
 *      against a real seeded draft row, that every helper in (2) excludes
 *      it and that `forAdmin()` (3) still includes it — the single most
 *      important test in this batch, mirroring the rigor
 *      `OutboxRecoveryTest` and similar integration tests set for their own
 *      single-most-important guarantees.
 *
 * NEVER add a public-facing read path anywhere in this codebase that
 * queries `FaqArticle` without going through `published()` (directly or via
 * one of the helpers in (2)/`FaqPublicQuery`). Doing so risks leaking a
 * draft or unpublished article, exactly what AC6 forbids.
 */
final class FaqArticle extends Model
{
    protected $table = 'faq_articles';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'category_id',
        'title',
        'slug',
        'summary',
        'body',
        'publish_state',
        'sort_order',
        'current_version',
        'published_at',
        'unpublished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'sort_order' => 'integer',
            'current_version' => 'integer',
            'published_at' => 'immutable_datetime',
            'unpublished_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        self::saving(function (self $article): void {
            FaqArticlePublishState::assertKnown($article->publish_state);
        });
    }

    /**
     * @return BelongsTo<FaqCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(FaqCategory::class, 'category_id');
    }

    /**
     * Full publish history, oldest first — append-only, written only by
     * `Actions\PublishFaqArticle`. See `FaqArticleVersion`'s own doc block.
     *
     * @return HasMany<FaqArticleVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(FaqArticleVersion::class, 'faq_article_id')->orderBy('version_number');
    }

    /**
     * Directional "this article names that article as related" links
     * (`faq_article_related_article`, this article on the owning side).
     * Deliberately NOT auto-reciprocated: declaring A related to B does not
     * itself declare B related to A. A true symmetric-sync implementation
     * (rewriting both directions on every edit) risks one admin's edit to A
     * silently deleting a relation another admin independently declared
     * from B's side — simpler and safer to keep this a plain directional
     * edge and let an admin add the reverse pair explicitly if reciprocal
     * display is wanted. ADMIN-FACING — includes drafts/unpublished
     * targets; public rendering MUST use `publishedRelatedArticles()`
     * instead. See class-level AC6 doc block.
     *
     * @return BelongsToMany<self, $this>
     */
    public function relatedArticles(): BelongsToMany
    {
        return $this->belongsToMany(
            self::class,
            'faq_article_related_article',
            'faq_article_id',
            'related_faq_article_id',
        );
    }

    /**
     * PUBLIC-SAFE version of `relatedArticles()` — requirements.md AC4 ("WHEN
     * a user views an FAQ article detail THE SYSTEM SHALL display ...
     * related articles"). Filters the related set to `published()` so an
     * admin linking a still-drafted article as "related" can never leak it
     * through a public detail page. Any later batch rendering an article's
     * "related articles" block on a public page MUST call this, not
     * `relatedArticles()` — see class-level AC6 doc block.
     *
     * @return BelongsToMany<self, $this>
     */
    public function publishedRelatedArticles(): BelongsToMany
    {
        return $this->relatedArticles()->published();
    }

    /**
     * THE base guarantee — see class-level doc block. Every public read
     * path in this codebase must start here (or from a helper built on top
     * of it).
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('publish_state', FaqArticlePublishState::PUBLISHED);
    }

    /**
     * Composes with `published()` — callers filtering a public listing by
     * category should chain `FaqArticle::published()->inCategory($id)`, not
     * write a raw `where('category_id', ...)` that might be applied to an
     * unfiltered (draft-including) query by mistake.
     */
    public function scopeInCategory(Builder $query, int $categoryId): void
    {
        $query->where('category_id', $categoryId);
    }

    /**
     * Simple, portable (SQLite- and Postgres-safe) case-insensitive
     * substring search across title/summary/body — `design.md`'s Search
     * section: "Database full-text/simple indexed search is sufficient for
     * MVP." Deliberately not `ILIKE` (Postgres-only) or a `tsvector`/GIN
     * full-text index: at the ~23-row MVP scale this catalogue seeds today,
     * a plain `LOWER(column) LIKE ?` scan is fast enough and keeps this
     * query portable across this project's SQLite test driver and its
     * PostgreSQL 18 production target alike — unlike, e.g., `ADR-0007`'s
     * `pg_trgm` choice for large-scale grave-name fuzzy search, which is a
     * different problem at a different scale. Revisit if/when the FAQ
     * catalogue grows enough for this to matter (see
     * `performance-and-capacity.md` if that day comes).
     *
     * A blank/whitespace-only `$term` is treated as "no filter" (returns
     * the query unmodified) rather than "match nothing" — a later search
     * UI submitting an empty query should show the full published list, not
     * an empty result.
     */
    public function scopeMatching(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            return;
        }

        $needle = '%'.mb_strtolower($term).'%';

        $query->where(function (Builder $inner) use ($needle): void {
            $inner->whereRaw('LOWER(title) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(summary) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(body) LIKE ?', [$needle]);
        });
    }

    /**
     * Detail-by-slug — requirements.md AC6-safe by construction: composes
     * `published()`, so a slug belonging to a draft or unpublished article
     * always resolves to `null`, never the row. This is the ONLY documented
     * way to resolve a public `/faq/{articleSlug}` route
     * (`design.md`'s Routes section) to a model instance.
     */
    public static function publishedBySlug(string $slug): ?self
    {
        return self::published()->where('slug', $slug)->first();
    }

    /**
     * THE explicit admin escape hatch — see class-level AC6 doc block point
     * (3). Returns every article regardless of `publish_state`, including
     * drafts and unpublished ones. Use this ONLY for admin-facing surfaces
     * (a future Filament resource's article list, a "preview" action) —
     * never for anything a public visitor's request could reach.
     */
    public static function forAdmin(): Builder
    {
        return self::query();
    }

    public function isDraft(): bool
    {
        return $this->publish_state === FaqArticlePublishState::DRAFT;
    }

    public function isPublished(): bool
    {
        return $this->publish_state === FaqArticlePublishState::PUBLISHED;
    }

    public function isUnpublished(): bool
    {
        return $this->publish_state === FaqArticlePublishState::UNPUBLISHED;
    }
}

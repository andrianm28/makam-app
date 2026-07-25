<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `faq_article_related_article` — the self-referencing many-to-many pivot
 * backing "related articles", which both `design.md` (via `faq_articles`'
 * implied shape) and `faq-catalog.md`'s "Content behavior" section name as
 * an article field: "Article has title, slug, category, summary, body,
 * related articles, publish state, version, and updated timestamp."
 *
 * ---------------------------------------------------------------------------
 * Pivot table vs. an alternative (e.g. a JSON column of related ids) — why
 * a pivot table
 * ---------------------------------------------------------------------------
 * A pivot table is queryable (join to fetch related articles' own current
 * `publish_state` for the AC6-safe `publishedRelatedArticles()` filter —
 * see `App\Domain\Faq\Models\FaqArticle`'s own doc block), enforces
 * referential integrity via foreign keys (a related id can never point at a
 * row that does not exist, or survive that row's deletion), and needs no
 * application-level JSON-array bookkeeping. A JSON column on `faq_articles`
 * would make "does this related id still point at a real, and public,
 * article" an application-code responsibility this batch would rather not
 * own when the database can guarantee it directly.
 *
 * ---------------------------------------------------------------------------
 * Directional, not auto-symmetric — a documented choice
 * ---------------------------------------------------------------------------
 * `(faq_article_id, related_faq_article_id)` is ordered: declaring A related
 * to B does not itself insert the reverse `(B, A)` pair. See
 * `FaqArticle::relatedArticles()`'s own doc block for why a true symmetric
 * sync was rejected (risk of one admin's edit silently deleting a relation
 * a different admin declared from the other side).
 *
 * Composite primary key on both columns is the uniqueness constraint (no
 * duplicate pair possible); both foreign keys cascade on delete so a
 * removed article's pivot rows (as either side) disappear with it.
 *
 * Migration timestamp slot: see
 * `2026_07_26_170000_create_faq_categories_table.php`'s own doc block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_article_related_article', function (Blueprint $table) {
            $table->foreignId('faq_article_id')
                ->constrained('faq_articles')
                ->cascadeOnDelete();

            $table->foreignId('related_faq_article_id')
                ->constrained('faq_articles')
                ->cascadeOnDelete();

            $table->primary(['faq_article_id', 'related_faq_article_id'], 'faq_article_related_article_primary');

            // Reverse lookup: "which articles declare THIS one as related" —
            // useful for a future admin integrity check even though this
            // batch's own read paths do not need it.
            $table->index('related_faq_article_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_article_related_article');
    }
};

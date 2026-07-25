<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `faq_articles` — `design.md`'s Data section. Backs requirements.md AC3
 * (filter/search by title/summary/body), AC4 (detail view: updated date,
 * related articles, CTA), AC5 (admin create/preview/publish/unpublish/
 * reorder/version), and AC6 (never display an unpublished article publicly
 * — see `App\Domain\Faq\Models\FaqArticle`'s own class-level doc block for
 * the full guarantee, which this migration's `publish_state` column is the
 * foundation of).
 *
 * ---------------------------------------------------------------------------
 * "Current row = current content" — no separate draft-buffer table
 * ---------------------------------------------------------------------------
 * This table's content columns (`title`/`slug`/`summary`/`body`/
 * `category_id`/`sort_order`) are always the article's one current content,
 * regardless of `publish_state` — see `FaqArticle`'s own doc block for the
 * full reasoning on why this batch did not invent a fourth "pending edit"
 * table beyond the three `design.md` names.
 *
 * ---------------------------------------------------------------------------
 * `publish_state` — three values, not two (a documented judgement call)
 * ---------------------------------------------------------------------------
 * `draft` | `published` | `unpublished` — plain string,
 * application-layer-validated against
 * `App\Domain\Faq\FaqArticlePublishState::KNOWN_STATES` (not a Postgres
 * enum, same established convention as every other closed-list string
 * column in this codebase). See that class's own doc block for why
 * `unpublish` is its own terminal state distinct from `draft`, rather than
 * an unpublish action simply reverting the row back to `draft`.
 *
 * ---------------------------------------------------------------------------
 * Versioning timing — `current_version` increments ONLY on publish, not on
 * every edit
 * ---------------------------------------------------------------------------
 * `current_version` starts at `0` (never published). `App\Domain\Faq\
 * Actions\PublishFaqArticle` increments it by exactly 1 and appends a
 * matching `faq_article_versions` row EVERY time it runs — including a
 * republish of unchanged content after an `unpublish`. Editing a draft's
 * content (`Actions\UpdateFaqArticleContent`) never touches this column or
 * writes a version row — see that Action's own doc block for the full
 * "version = a published state, not every draft keystroke" reasoning
 * requirements.md AC5 (publish and version as separate capabilities) points
 * toward.
 *
 * `published_at` is the timestamp of the MOST RECENT publish (updated on
 * every republish, never cleared by `unpublish` — a pulled article still
 * honestly answers "when was this last live"). `unpublished_at` is set by
 * `Actions\UnpublishFaqArticle` and cleared (`null`) by the next successful
 * publish.
 *
 * ---------------------------------------------------------------------------
 * `category_id` — `restrictOnDelete()`, not cascade
 * ---------------------------------------------------------------------------
 * Categories are seed data this batch expects to be permanent
 * (`AGENTS.md` §FAQ: "preserve six required categories") — a `restrict`
 * makes an accidental category deletion fail loudly instead of silently
 * orphaning or cascading away real published content.
 *
 * Migration timestamp slot: `2026_07_26_170000`-`2026_07_26_179999` (see
 * `2026_07_26_170000_create_faq_categories_table.php`'s own doc block for
 * the range and the module-boundary finding, N-13, this table is also part
 * of).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_articles', function (Blueprint $table) {
            $table->id();

            $table->foreignId('category_id')
                ->constrained('faq_categories')
                ->restrictOnDelete();

            $table->string('title');
            $table->string('slug')->unique();
            $table->text('summary');
            $table->text('body');

            $table->string('publish_state', 16)->default('draft');

            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedInteger('current_version')->default(0);

            $table->timestamp('published_at')->nullable();
            $table->timestamp('unpublished_at')->nullable();

            $table->timestamps();

            // Public listing/filter's primary access path: "published
            // articles in this category, in display order" — see
            // FaqArticle::scopePublished()/::scopeInCategory().
            $table->index(['category_id', 'publish_state', 'sort_order'], 'faq_articles_category_state_order_idx');

            // Admin surfaces filtering by state across all categories.
            $table->index('publish_state');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_articles');
    }
};

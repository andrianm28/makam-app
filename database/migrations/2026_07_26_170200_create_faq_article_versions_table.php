<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `faq_article_versions` — `design.md`'s Data section names this table
 * explicitly, separate from `faq_articles`. Append-only: one row per
 * successful `App\Domain\Faq\Actions\PublishFaqArticle` call — mirrors this
 * codebase's established append-only pattern for `gate_activations` /
 * `mfa_challenges` / `reauthentication_events` (no `updated_at`; history is
 * never rewritten).
 *
 * ---------------------------------------------------------------------------
 * Versioning timing decision: a snapshot on PUBLISH, not on every edit
 * ---------------------------------------------------------------------------
 * requirements.md AC5 lists "publish" and "version" as two separate admin
 * capabilities. Read literally as "every edit is a version," this table
 * would fill with rows for every draft keystroke, most of which the public
 * never saw — a version history a content team can't actually use to answer
 * "what did customers see, and when." Read as "a version is a
 * publish-time snapshot," this table becomes exactly that answerable
 * history: each row is a real, dated, previously-public state of the
 * article. This batch takes the second reading. `faq_articles`' own current
 * columns already hold "what is being worked on/currently live" (see that
 * migration's own doc block); this table exists purely to answer "what has
 * ever gone live."
 *
 * ---------------------------------------------------------------------------
 * Column shape
 * ---------------------------------------------------------------------------
 * - `version_number` is 1-indexed per article (`faq_articles.current_version`
 *   mirrors whichever row here is "current"), enforced unique per article
 *   below — `PublishFaqArticle` computes it as `current_version + 1` inside
 *   a row-locked transaction, so two concurrent publishes of the same
 *   article can never collide on the same number.
 * - `title`/`slug`/`summary`/`body` are a full content snapshot at publish
 *   time, not a diff — simplest to read back later, and cheap at this
 *   catalogue's scale (a few dozen articles).
 * - `category_id` is snapshotted WITHOUT a foreign key to `faq_categories` —
 *   deliberately: this row is a point-in-time historical fact ("this
 *   article was published under this category on this date"), and should
 *   not be affected by any future category lifecycle change (categories are
 *   expected to be permanent per `AGENTS.md` §FAQ, so this is a defensive
 *   choice rather than one this batch expects to matter in practice).
 * - `published_at` is this specific version's own publish timestamp — NOT
 *   the same column as `faq_articles.published_at` (which tracks only the
 *   MOST RECENT publish); every row here keeps its own historical value
 *   forever.
 * - `published_by` is a plain string actor reference, matching
 *   `gate_activations.actor_reference` / `scope_assignments
 *   .actor_identifier`'s established "identity-adapter-agnostic" pattern in
 *   this codebase (no real K1/K2-backed identity adapter exists yet).
 * - No `created_at`/`updated_at` at all — same choice
 *   `reauthentication_events` makes for the same reason (see that
 *   migration's own doc block): this is an append-only event log, not a
 *   record meant to look editable, and `published_at` already is this
 *   table's "when."
 *
 * Migration timestamp slot: see
 * `2026_07_26_170000_create_faq_categories_table.php`'s own doc block.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_article_versions', function (Blueprint $table) {
            $table->id();

            $table->foreignId('faq_article_id')
                ->constrained('faq_articles')
                ->cascadeOnDelete();

            $table->unsignedInteger('version_number');

            // Snapshot only — deliberately no FK. See class doc block.
            $table->unsignedBigInteger('category_id');

            $table->string('title');
            $table->string('slug');
            $table->text('summary');
            $table->text('body');

            $table->timestamp('published_at');
            $table->string('published_by');

            // No created_at/updated_at — see class doc block.

            $table->unique(['faq_article_id', 'version_number'], 'faq_article_versions_article_version_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_article_versions');
    }
};

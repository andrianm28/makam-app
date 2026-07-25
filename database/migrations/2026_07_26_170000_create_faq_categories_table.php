<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `faq_categories` — `.kiro/specs/public-faq/design.md`'s Data section names
 * this table explicitly. Backs requirements.md AC2: "THE SYSTEM SHALL
 * present the six FAQ categories defined in `faq-catalog.md`."
 *
 * ---------------------------------------------------------------------------
 * Module-boundary gap this table's existence surfaces — see
 * `docs/planning/sprint-plan.md` finding N-13 for the full writeup
 * ---------------------------------------------------------------------------
 * `app/Domain/README.md` requires one directory per module boundary listed
 * in `docs/architecture/overview.md` §5 — that table lists 23 modules and
 * does NOT include one named `Faq`, even though this spec's own `design.md`
 * clearly needs a schema-owning module (three named tables: this one,
 * `faq_articles`, `faq_article_versions`). Resolution taken here: create
 * `app/Domain/Faq/**` anyway (skipping the established module-per-directory
 * convention, or cramming these three tables into an unrelated existing
 * module's directory, are both worse than the gap this creates) — see
 * finding N-13 for the full reasoning and what still needs to happen
 * (`overview.md` §5 needs a `Faq` row from whoever owns that document next).
 *
 * ---------------------------------------------------------------------------
 * Column shape
 * ---------------------------------------------------------------------------
 * - `code` is the exact catalogue code (`CARA_MEMESAN`, `DOKUMEN`, ...) —
 *   plain string, application-layer-validated against
 *   `App\Domain\Faq\FaqCategoryCode::KNOWN_CODES`, not a Postgres enum —
 *   same established convention as every other closed-list string column in
 *   this codebase (see that class's own doc block).
 * - `name` is the exact Indonesian label `faq-catalog.md` gives each code
 *   (e.g. "Cara Memesan") — the public-facing display name.
 * - `sort_order` is the stable public presentation order AC2/AC9 need
 *   ("render the FAQ responsively... indexable where appropriate" implies a
 *   stable, non-arbitrary category order) — seeded 1-6 in
 *   `faq-catalog.md`'s own listed order by
 *   `2026_07_26_170400_seed_faq_categories_and_articles.php`.
 *
 * No admin write-action exists to create/delete a category in this batch —
 * `AGENTS.md` §FAQ: "Seed and preserve six required categories." Categories
 * are seed data, not admin-editable content, for this MVP.
 *
 * Migration timestamp slot: `2026_07_26_170000` through `2026_07_26_179999`
 * (the next free range after Sprint 3's re-authentication batch, which used
 * up to `2026_07_26_160000`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('faq_categories', function (Blueprint $table) {
            $table->id();

            $table->string('code', 32)->unique();
            $table->string('name');
            $table->unsignedSmallInteger('sort_order');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faq_categories');
    }
};

<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Faq;

use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Domain\Faq\Actions\PublishFaqArticle;
use App\Domain\Faq\Actions\UnpublishFaqArticle;
use App\Domain\Faq\Actions\UpdateFaqArticleContent;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\Models\FaqArticle;
use App\Domain\Faq\Models\FaqArticleVersion;
use App\Domain\Faq\Models\FaqCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The full draft -> publish -> edit -> republish -> unpublish -> republish
 * lifecycle, and the versioning-timing decision this batch made:
 * `faq_article_versions` gets a new row ONLY on publish, never on a plain
 * content edit. See `PublishFaqArticle`'s and
 * `2026_07_26_170200_create_faq_article_versions_table.php`'s own doc
 * blocks for the full reasoning.
 */
final class FaqArticleLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private function categoryId(): int
    {
        return FaqCategory::findByCode(FaqCategoryCode::DOKUMEN)->id;
    }

    public function test_a_new_article_starts_as_draft_with_version_zero_and_no_version_rows(): void
    {
        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Judul uji siklus hidup',
            slug: 'uji-siklus-hidup-draft-awal',
            summary: 'Ringkasan awal.',
            body: 'Isi awal.',
            actorReference: 42,
        );

        $this->assertTrue($article->isDraft());
        $this->assertSame(0, $article->current_version);
        $this->assertNull($article->published_at);
        $this->assertSame(0, FaqArticleVersion::query()->where('faq_article_id', $article->id)->count());
    }

    public function test_editing_a_draft_never_writes_a_version_row(): void
    {
        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Judul sebelum diedit',
            slug: 'uji-siklus-hidup-edit-draft',
            summary: 'Ringkasan sebelum diedit.',
            body: 'Isi sebelum diedit.',
            actorReference: 42,
        );

        $edited = (new UpdateFaqArticleContent)(
            article: $article,
            categoryId: $this->categoryId(),
            title: 'Judul setelah diedit',
            slug: $article->slug,
            summary: 'Ringkasan setelah diedit.',
            body: 'Isi setelah diedit.',
            sortOrder: $article->sort_order,
            actorReference: 42,
        );

        $this->assertSame('Judul setelah diedit', $edited->title);
        $this->assertSame(0, $edited->current_version);
        $this->assertSame(0, FaqArticleVersion::query()->where('faq_article_id', $article->id)->count());
    }

    public function test_publishing_appends_exactly_one_version_row_and_updates_the_article(): void
    {
        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Judul akan diterbitkan',
            slug: 'uji-siklus-hidup-terbit-pertama',
            summary: 'Ringkasan pertama.',
            body: 'Isi pertama.',
            actorReference: 42,
        );

        $published = (new PublishFaqArticle)($article, actorReference: 42, reason: 'Konten sudah siap.');

        $this->assertTrue($published->isPublished());
        $this->assertSame(1, $published->current_version);
        $this->assertNotNull($published->published_at);
        $this->assertNull($published->unpublished_at);

        $this->assertDatabaseCount('faq_article_versions', 1);
        $version = FaqArticleVersion::query()->sole();
        $this->assertSame($article->id, $version->faq_article_id);
        $this->assertSame(1, $version->version_number);
        $this->assertSame('Judul akan diterbitkan', $version->title);
        $this->assertSame('42', $version->published_by);
    }

    public function test_editing_after_publish_changes_the_live_row_but_not_the_recorded_version(): void
    {
        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Judul versi 1',
            slug: 'uji-siklus-hidup-edit-setelah-terbit',
            summary: 'Ringkasan versi 1.',
            body: 'Isi versi 1.',
            actorReference: 42,
        );
        $published = (new PublishFaqArticle)($article, actorReference: 42);

        $edited = (new UpdateFaqArticleContent)(
            article: $published,
            categoryId: $this->categoryId(),
            title: 'Judul diedit setelah terbit',
            slug: $published->slug,
            summary: 'Ringkasan diedit setelah terbit.',
            body: 'Isi diedit setelah terbit.',
            sortOrder: $published->sort_order,
            actorReference: 42,
        );

        // Still published, live row changed immediately (this module's own
        // "current row = current content" decision — see FaqArticle's doc
        // block), but the FIRST version snapshot must still read the
        // original content, never the edit.
        $this->assertTrue($edited->isPublished());
        $this->assertSame(1, $edited->current_version);
        $this->assertSame('Judul diedit setelah terbit', $edited->title);

        $version = FaqArticleVersion::query()->where('faq_article_id', $article->id)->where('version_number', 1)->sole();
        $this->assertSame('Judul versi 1', $version->title);
    }

    public function test_republishing_after_an_edit_appends_a_second_version_row(): void
    {
        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Judul versi 1',
            slug: 'uji-siklus-hidup-republish',
            summary: 'Ringkasan versi 1.',
            body: 'Isi versi 1.',
            actorReference: 42,
        );
        $published = (new PublishFaqArticle)($article, actorReference: 42);
        $edited = (new UpdateFaqArticleContent)(
            article: $published,
            categoryId: $this->categoryId(),
            title: 'Judul versi 2',
            slug: $published->slug,
            summary: 'Ringkasan versi 2.',
            body: 'Isi versi 2.',
            sortOrder: $published->sort_order,
            actorReference: 42,
        );

        $republished = (new PublishFaqArticle)($edited, actorReference: 42);

        $this->assertSame(2, $republished->current_version);
        $this->assertDatabaseCount('faq_article_versions', 2);

        $versionTwo = FaqArticleVersion::query()->where('faq_article_id', $article->id)->where('version_number', 2)->sole();
        $this->assertSame('Judul versi 2', $versionTwo->title);
    }

    public function test_unpublish_moves_a_published_article_to_unpublished_not_back_to_draft(): void
    {
        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Judul akan dicabut',
            slug: 'uji-siklus-hidup-unpublish',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: 42,
        );
        $published = (new PublishFaqArticle)($article, actorReference: 42);
        $publishedAt = $published->published_at;

        $unpublished = (new UnpublishFaqArticle)($published, actorReference: 42, reason: 'Ditinjau ulang.');

        $this->assertTrue($unpublished->isUnpublished());
        $this->assertFalse($unpublished->isDraft());
        $this->assertNotNull($unpublished->unpublished_at);
        // published_at (most-recent-publish timestamp) is retained, not
        // cleared — see UnpublishFaqArticle's own doc block.
        $this->assertTrue($publishedAt->equalTo($unpublished->published_at));
        // Content and version history untouched by a pure visibility change.
        $this->assertSame(1, $unpublished->current_version);
        $this->assertDatabaseCount('faq_article_versions', 1);
    }

    public function test_unpublishing_a_draft_is_rejected(): void
    {
        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Judul draft murni',
            slug: 'uji-siklus-hidup-unpublish-draft-invalid',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: 42,
        );

        $this->expectException(InvalidArgumentException::class);

        (new UnpublishFaqArticle)($article, actorReference: 42);
    }

    public function test_republishing_an_unpublished_article_appends_a_new_version_and_clears_unpublished_at(): void
    {
        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Judul akan dicabut lalu terbit lagi',
            slug: 'uji-siklus-hidup-republish-setelah-unpublish',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: 42,
        );
        $published = (new PublishFaqArticle)($article, actorReference: 42);
        $unpublished = (new UnpublishFaqArticle)($published, actorReference: 42);
        $this->assertNotNull($unpublished->unpublished_at);

        $republished = (new PublishFaqArticle)($unpublished, actorReference: 42);

        $this->assertTrue($republished->isPublished());
        $this->assertSame(2, $republished->current_version);
        $this->assertNull($republished->unpublished_at);
        $this->assertDatabaseCount('faq_article_versions', 2);
    }

    public function test_publish_state_saving_hook_rejects_an_unknown_state(): void
    {
        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Judul',
            slug: 'uji-siklus-hidup-state-tidak-valid',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: 42,
        );

        $this->expectException(InvalidArgumentException::class);

        $article->forceFill(['publish_state' => 'not_a_real_state'])->save();
    }

    public function test_category_saving_hook_rejects_an_unknown_code(): void
    {
        $this->expectException(InvalidArgumentException::class);

        FaqCategory::create(['code' => 'NOT_A_REAL_CODE', 'name' => 'Invalid', 'sort_order' => 99]);
    }

    public function test_faq_article_versions_table_has_no_updated_at_or_created_at_column(): void
    {
        // Structural proof, mirroring GateActivationRecorderTest's own
        // "no updated_at column at all" assertion — append-only.
        $this->assertFalse(Schema::hasColumn('faq_article_versions', 'updated_at'));
        $this->assertFalse(Schema::hasColumn('faq_article_versions', 'created_at'));
        $this->assertFalse((new FaqArticleVersion)->usesTimestamps());
    }
}

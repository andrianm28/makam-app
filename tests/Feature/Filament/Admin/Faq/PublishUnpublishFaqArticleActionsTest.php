<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Faq;

use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Domain\Faq\Actions\PublishFaqArticle;
use App\Domain\Faq\FaqArticlePublishState;
use App\Domain\Faq\FaqAuditActions;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\Models\FaqArticleVersion;
use App\Domain\Faq\Models\FaqCategory;
use App\Filament\Admin\Resources\FaqArticles\Pages\ListFaqArticles;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proves the list table's "Terbitkan" (publish) / "Batalkan publikasi"
 * (unpublish) row actions are wired to the real
 * `PublishFaqArticle`/`UnpublishFaqArticle` Domain Actions — not just
 * flipping `publish_state` inline. This batch only needs to prove the UI
 * WIRES to the Action correctly (`ReorderFaqArticlesTest`/
 * `FaqArticleLifecycleTest`, Batch 4.1, already prove the Actions' own
 * internal correctness) — so each assertion here checks a side effect only
 * the real Action produces: the `faq_article_versions` snapshot row
 * (`PublishFaqArticle`-only) and the `audit_events` row
 * (`FaqAuditActions::PUBLISHED`/`UNPUBLISHED`).
 */
final class PublishUnpublishFaqArticleActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function categoryId(): int
    {
        return FaqCategory::findByCode(FaqCategoryCode::PEMBAYARAN)->id;
    }

    public function test_the_publish_row_action_transitions_state_and_appends_a_version(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Artikel akan diterbitkan',
            slug: 'row-action-akan-diterbitkan',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: $user->id,
        );

        $this->assertSame(0, FaqArticleVersion::query()->where('faq_article_id', $article->id)->count());

        Livewire::test(ListFaqArticles::class)
            ->callTableAction('publish', $article, data: ['reason' => 'Konten sudah ditinjau.']);

        $article->refresh();
        $this->assertSame(FaqArticlePublishState::PUBLISHED, $article->publish_state);
        $this->assertSame(1, $article->current_version);
        $this->assertNotNull($article->published_at);

        // Only `PublishFaqArticle` appends a `faq_article_versions` row —
        // a bare `publish_state` column flip would not.
        $version = FaqArticleVersion::query()->where('faq_article_id', $article->id)->sole();
        $this->assertSame(1, $version->version_number);

        $event = AuditEvent::query()
            ->where('action', FaqAuditActions::PUBLISHED)
            ->where('subject_id', (string) $article->id)
            ->sole();
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('Konten sudah ditinjau.', $event->reason);
        $this->assertSame(['previous_state' => 'draft', 'new_state' => 'published'], $event->metadata);
    }

    public function test_the_publish_row_action_works_with_no_reason_given(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Artikel tanpa alasan',
            slug: 'row-action-tanpa-alasan',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: $user->id,
        );

        Livewire::test(ListFaqArticles::class)
            ->callTableAction('publish', $article);

        $this->assertTrue($article->refresh()->isPublished());
        $this->assertDatabaseHas('audit_events', [
            'action' => FaqAuditActions::PUBLISHED,
            'subject_id' => (string) $article->id,
            'reason' => null,
        ]);
    }

    public function test_the_unpublish_row_action_transitions_state_without_touching_the_version_history(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $draft = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Artikel akan dicabut',
            slug: 'row-action-akan-dicabut',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: $user->id,
        );
        $published = (new PublishFaqArticle)($draft, actorReference: $user->id);

        Livewire::test(ListFaqArticles::class)
            ->callTableAction('unpublish', $published, data: ['reason' => 'Informasi sudah usang.']);

        $published->refresh();
        $this->assertSame(FaqArticlePublishState::UNPUBLISHED, $published->publish_state);
        $this->assertNotNull($published->unpublished_at);
        // `current_version` is untouched by unpublish -- still the one
        // version `PublishFaqArticle` wrote above.
        $this->assertSame(1, $published->current_version);
        $this->assertSame(1, FaqArticleVersion::query()->where('faq_article_id', $published->id)->count());

        $event = AuditEvent::query()
            ->where('action', FaqAuditActions::UNPUBLISHED)
            ->where('subject_id', (string) $published->id)
            ->sole();
        $this->assertSame('Informasi sudah usang.', $event->reason);
        $this->assertSame(['previous_state' => 'published', 'new_state' => 'unpublished'], $event->metadata);
    }

    public function test_the_publish_action_is_hidden_for_an_already_published_article_and_unpublish_is_hidden_for_a_draft(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $draft = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Artikel draf',
            slug: 'row-action-visibility-draf',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: $user->id,
        );
        $publishedSource = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Artikel terbit',
            slug: 'row-action-visibility-terbit',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: $user->id,
        );
        $published = (new PublishFaqArticle)($publishedSource, actorReference: $user->id);

        $component = Livewire::test(ListFaqArticles::class);

        $component->assertTableActionHidden('unpublish', $draft);
        $component->assertTableActionVisible('publish', $draft);

        $component->assertTableActionHidden('publish', $published);
        $component->assertTableActionVisible('unpublish', $published);
    }
}

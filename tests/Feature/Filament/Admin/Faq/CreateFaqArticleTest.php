<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Faq;

use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Domain\Faq\FaqArticlePublishState;
use App\Domain\Faq\FaqAuditActions;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\Models\FaqArticle;
use App\Domain\Faq\Models\FaqCategory;
use App\Filament\Admin\Resources\FaqArticles\Pages\CreateFaqArticle;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proves the Create page's form submission is actually wired to
 * `App\Domain\Faq\Actions\CreateFaqArticleDraft` — via
 * `Pages\CreateFaqArticle::handleRecordCreation()` — and not to Filament's
 * default `Model::create()` behaviour. `app/Domain/README.md`'s binding
 * rule is the thing being verified: a bare `Model::create()` would insert a
 * row but write no `audit_events` row at all, so asserting BOTH the
 * resulting row's shape AND its audit trail (reusing `FaqAuditActions`'s
 * constants, per the batch brief) is what actually distinguishes "went
 * through the Action" from "Filament saved the model directly."
 */
final class CreateFaqArticleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function categoryId(): int
    {
        return FaqCategory::findByCode(FaqCategoryCode::DOKUMEN)->id;
    }

    public function test_submitting_the_create_form_creates_a_draft_article_via_the_domain_action(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(CreateFaqArticle::class)
            ->fillForm([
                'category_id' => $this->categoryId(),
                'title' => 'Bagaimana cara mengunggah dokumen?',
                'slug' => 'bagaimana-cara-mengunggah-dokumen',
                'summary' => 'Ringkasan singkat.',
                'body' => 'Isi lengkap jawaban.',
                'related_article_ids' => [],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $article = FaqArticle::forAdmin()->where('slug', 'bagaimana-cara-mengunggah-dokumen')->sole();

        $this->assertSame('Bagaimana cara mengunggah dokumen?', $article->title);
        $this->assertSame($this->categoryId(), $article->category_id);
        $this->assertSame(FaqArticlePublishState::DRAFT, $article->publish_state);
        $this->assertSame(0, $article->current_version);

        // Proves the write went through `CreateFaqArticleDraft` (which
        // calls `Audit::record()`), not a bare `FaqArticle::create()` (which
        // would leave `audit_events` untouched for this article entirely).
        $event = AuditEvent::query()
            ->where('action', FaqAuditActions::CREATED)
            ->where('subject_id', (string) $article->id)
            ->sole();

        $this->assertSame('faq_article', $event->subject_type);
        $this->assertSame((string) $user->id, $event->actor_ref);
        $this->assertSame('admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('allowed', $event->outcome);
        $this->assertSame(['new_state' => FaqArticlePublishState::DRAFT], $event->metadata);
    }

    public function test_creating_an_article_with_a_related_article_links_it(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $existing = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Artikel referensi',
            slug: 'create-page-artikel-referensi',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: $user->id,
        );

        Livewire::test(CreateFaqArticle::class)
            ->fillForm([
                'category_id' => $this->categoryId(),
                'title' => 'Artikel baru dengan rujukan',
                'slug' => 'create-page-artikel-baru-dengan-rujukan',
                'summary' => 'Ringkasan.',
                'body' => 'Isi.',
                'related_article_ids' => [$existing->id],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $article = FaqArticle::forAdmin()->where('slug', 'create-page-artikel-baru-dengan-rujukan')->sole();

        $this->assertSame([$existing->id], $article->relatedArticles()->pluck('id')->all());
    }

    public function test_a_blank_title_fails_validation_and_creates_no_row(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(CreateFaqArticle::class)
            ->fillForm([
                'category_id' => $this->categoryId(),
                'title' => '',
                'slug' => 'create-page-judul-kosong',
                'summary' => 'Ringkasan.',
                'body' => 'Isi.',
            ])
            ->call('create')
            ->assertHasFormErrors(['title' => 'required']);

        $this->assertDatabaseMissing('faq_articles', ['slug' => 'create-page-judul-kosong']);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Faq;

use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Domain\Faq\FaqAuditActions;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\Models\FaqCategory;
use App\Filament\Admin\Resources\FaqArticles\Pages\EditFaqArticle;
use App\Models\User;
use App\Platform\Audit\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proves the Edit page's form submission is wired to `App\Domain\Faq\
 * Actions\UpdateFaqArticleContent` (via `Pages\EditFaqArticle::
 * handleRecordUpdate()`) — mirroring `CreateFaqArticleTest`'s reasoning for
 * why the audit row, not just the resulting column values, is what proves
 * the Action ran rather than a bare `$record->update($data)`. Also proves
 * `related_article_ids` is correctly pre-filled from the pivot on open
 * (`mutateFormDataBeforeFill()`), since that field has no matching
 * `faq_articles` column for Filament's default fill behaviour to find.
 */
final class EditFaqArticleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    private function categoryId(): int
    {
        return FaqCategory::findByCode(FaqCategoryCode::PERPANJANGAN)->id;
    }

    public function test_submitting_the_edit_form_updates_content_via_the_domain_action(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Judul lama',
            slug: 'edit-page-judul-lama',
            summary: 'Ringkasan lama.',
            body: 'Isi lama.',
            actorReference: $user->id,
        );

        Livewire::test(EditFaqArticle::class, ['record' => $article->getRouteKey()])
            ->fillForm([
                'title' => 'Judul baru',
                'summary' => 'Ringkasan baru.',
                'body' => 'Isi baru.',
            ])
            ->call('save')
            ->assertHasNoFormErrors();

        $article->refresh();
        $this->assertSame('Judul baru', $article->title);
        $this->assertSame('Ringkasan baru.', $article->summary);
        $this->assertSame('Isi baru.', $article->body);
        // Slug and category were untouched in the submitted diff -- proves
        // `UpdateFaqArticleContent`'s PUT-style full-replacement contract is
        // satisfied from the pre-filled existing values, not silently
        // blanked.
        $this->assertSame('edit-page-judul-lama', $article->slug);

        $event = AuditEvent::query()
            ->where('action', FaqAuditActions::UPDATED)
            ->where('subject_id', (string) $article->id)
            ->sole();
        $this->assertSame((string) $user->id, $event->actor_ref);
    }

    public function test_the_related_articles_field_is_prefilled_from_the_pivot_on_open(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $related = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Artikel terkait',
            slug: 'edit-page-artikel-terkait',
            summary: 'R.',
            body: 'I.',
            actorReference: $user->id,
        );
        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Artikel utama',
            slug: 'edit-page-artikel-utama',
            summary: 'R.',
            body: 'I.',
            actorReference: $user->id,
            relatedArticleIds: [$related->id],
        );

        Livewire::test(EditFaqArticle::class, ['record' => $article->getRouteKey()])
            ->assertSchemaStateSet([
                'related_article_ids' => [$related->id],
            ]);
    }

    public function test_editing_preserves_the_sort_order_when_the_field_is_left_untouched(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Artikel urutan',
            slug: 'edit-page-artikel-urutan',
            summary: 'R.',
            body: 'I.',
            actorReference: $user->id,
            sortOrder: 5,
        );

        Livewire::test(EditFaqArticle::class, ['record' => $article->getRouteKey()])
            ->fillForm(['title' => 'Artikel urutan (revisi)'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame(5, $article->refresh()->sort_order);
    }
}

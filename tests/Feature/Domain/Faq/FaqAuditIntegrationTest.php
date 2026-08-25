<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Faq;

use App\Domain\Faq\Actions\CreateFaqArticleDraft;
use App\Domain\Faq\Actions\PublishFaqArticle;
use App\Domain\Faq\Actions\ReorderFaqArticles;
use App\Domain\Faq\Actions\UnpublishFaqArticle;
use App\Domain\Faq\Actions\UpdateFaqArticleContent;
use App\Domain\Faq\FaqAuditActions;
use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\Models\FaqCategory;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\Models\AuditEvent;
use App\Platform\Audit\SensitiveActions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proves this batch's Audit-integration decision: every write Action calls
 * `App\Platform\Audit\Audit::record()` (a complete history exists for FAQ
 * content the same way it does for every other admin mutation in this
 * codebase), but NONE of the five action names are on `SensitiveActions::
 * ACTIONS` (no mandatory reason) — the judgement call
 * `App\Domain\Faq\FaqAuditActions`'s own doc block documents, mirroring
 * `tests/Unit/Platform/Audit/SensitiveActionsTest.php`'s own shape for
 * asserting exactly which action names require a reason and which do not.
 */
final class FaqAuditIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private function categoryId(): int
    {
        return FaqCategory::findByCode(FaqCategoryCode::CUSTOMER_SERVICE)->id;
    }

    public function test_none_of_the_faq_audit_actions_are_sensitive_listed(): void
    {
        foreach ([
            FaqAuditActions::CREATED,
            FaqAuditActions::UPDATED,
            FaqAuditActions::PUBLISHED,
            FaqAuditActions::UNPUBLISHED,
            FaqAuditActions::REORDERED,
        ] as $action) {
            $this->assertNotContains($action, SensitiveActions::ACTIONS, "{$action} should not be sensitive-listed.");
            $this->assertFalse(SensitiveActions::requiresReason($action), "{$action} should not require a mandatory reason.");
        }
    }

    public function test_create_writes_an_audit_row_with_the_expected_shape(): void
    {
        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Judul audit',
            slug: 'audit-uji-create',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: 5,
            actorRole: 'content-admin',
            auditSource: AuditSource::Panel,
        );

        $event = AuditEvent::query()->where('action', FaqAuditActions::CREATED)->sole();
        $this->assertSame('5', $event->actor_ref);
        $this->assertSame('content-admin', $event->actor_role);
        $this->assertSame('panel', $event->source);
        $this->assertSame('faq_article', $event->subject_type);
        $this->assertSame((string) $article->id, $event->subject_id);
        $this->assertSame('0', $event->subject_version);
        $this->assertSame('allowed', $event->outcome);
        $this->assertNull($event->reason);
        $this->assertSame(['new_state' => 'draft'], $event->metadata);
    }

    public function test_create_does_not_require_a_reason(): void
    {
        // No `reason` argument passed at all — must not throw.
        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Judul tanpa alasan',
            slug: 'audit-uji-create-tanpa-alasan',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: 5,
        );

        $this->assertNotNull($article->id);
        $this->assertDatabaseHas('audit_events', ['action' => FaqAuditActions::CREATED, 'reason' => null]);
    }

    public function test_update_writes_an_audit_row(): void
    {
        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Judul awal',
            slug: 'audit-uji-update',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: 5,
        );

        (new UpdateFaqArticleContent)(
            article: $article,
            categoryId: $this->categoryId(),
            title: 'Judul baru',
            slug: $article->slug,
            summary: 'Ringkasan baru.',
            body: 'Isi baru.',
            sortOrder: $article->sort_order,
            actorReference: 5,
        );

        $this->assertDatabaseHas('audit_events', ['action' => FaqAuditActions::UPDATED, 'subject_id' => (string) $article->id]);
    }

    public function test_publish_writes_an_audit_row_with_previous_and_new_state_metadata(): void
    {
        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Judul akan terbit',
            slug: 'audit-uji-publish',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: 5,
        );

        $published = (new PublishFaqArticle)($article, actorReference: 5);

        $event = AuditEvent::query()->where('action', FaqAuditActions::PUBLISHED)->sole();
        $this->assertSame((string) $article->id, $event->subject_id);
        $this->assertSame('1', $event->subject_version);
        $this->assertSame(['previous_state' => 'draft', 'new_state' => 'published'], $event->metadata);
        $this->assertTrue($published->isPublished());
    }

    public function test_unpublish_writes_an_audit_row_with_previous_and_new_state_metadata(): void
    {
        $article = (new CreateFaqArticleDraft)(
            categoryId: $this->categoryId(),
            title: 'Judul akan dicabut',
            slug: 'audit-uji-unpublish',
            summary: 'Ringkasan.',
            body: 'Isi.',
            actorReference: 5,
        );
        $published = (new PublishFaqArticle)($article, actorReference: 5);

        (new UnpublishFaqArticle)($published, actorReference: 5);

        $event = AuditEvent::query()->where('action', FaqAuditActions::UNPUBLISHED)->sole();
        $this->assertSame(['previous_state' => 'published', 'new_state' => 'unpublished'], $event->metadata);
    }

    public function test_reorder_writes_a_single_audit_row_for_the_category(): void
    {
        $create = new CreateFaqArticleDraft;
        $a = $create($this->categoryId(), 'A', 'audit-uji-reorder-a', 'R.', 'I.', actorReference: 5);
        $b = $create($this->categoryId(), 'B', 'audit-uji-reorder-b', 'R.', 'I.', actorReference: 5);

        (new ReorderFaqArticles)([$b->id, $a->id], actorReference: 5);

        $event = AuditEvent::query()->where('action', FaqAuditActions::REORDERED)->sole();
        $this->assertSame('faq_category', $event->subject_type);
        $this->assertSame((string) $this->categoryId(), $event->subject_id);
        $this->assertSame(['note' => 'Reordered 2 article(s).'], $event->metadata);
    }
}

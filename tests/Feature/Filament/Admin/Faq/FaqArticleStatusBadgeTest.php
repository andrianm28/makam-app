<?php

declare(strict_types=1);

namespace Tests\Feature\Filament\Admin\Faq;

use App\Domain\Faq\FaqArticlePublishState;
use App\Filament\Admin\Resources\FaqArticles\FaqArticleStatusBadge;
use Tests\TestCase;

/**
 * Unit-level proof of the ONE hard rule `.kiro/specs/public-faq/tasks.md`'s
 * design-system section states explicitly for this badge: "never
 * `intent=success` for an unpublished article." Translated to this
 * Resource's local Filament-colour mapping: neither `draft` nor
 * `unpublished` may resolve to the `success` colour, and `published` must.
 */
final class FaqArticleStatusBadgeTest extends TestCase
{
    public function test_published_is_the_only_state_that_reads_success(): void
    {
        $this->assertSame('success', FaqArticleStatusBadge::color(FaqArticlePublishState::PUBLISHED));
    }

    public function test_draft_never_reads_success(): void
    {
        $this->assertNotSame('success', FaqArticleStatusBadge::color(FaqArticlePublishState::DRAFT));
    }

    public function test_unpublished_never_reads_success(): void
    {
        $this->assertNotSame('success', FaqArticleStatusBadge::color(FaqArticlePublishState::UNPUBLISHED));
    }

    public function test_draft_and_unpublished_are_visibly_distinguishable_from_each_other(): void
    {
        // Both are non-success, but a content admin still needs to tell
        // "never published" apart from "was live, then pulled" at a
        // glance — see `FaqArticleStatusBadge`'s own doc block.
        $this->assertNotSame(
            FaqArticleStatusBadge::color(FaqArticlePublishState::DRAFT),
            FaqArticleStatusBadge::color(FaqArticlePublishState::UNPUBLISHED),
        );
    }

    public function test_every_known_state_has_a_distinct_non_blank_label(): void
    {
        $labels = array_map(
            fn (string $state): string => FaqArticleStatusBadge::label($state),
            FaqArticlePublishState::KNOWN_STATES,
        );

        foreach ($labels as $label) {
            $this->assertNotSame('', trim($label));
        }

        $this->assertCount(count($labels), array_unique($labels));
    }
}

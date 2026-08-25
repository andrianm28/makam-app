<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FaqArticles;

use App\Domain\Faq\FaqArticlePublishState;

/**
 * A local, FAQ-specific `publish_state` -> (Filament badge colour, label)
 * mapping — deliberately NOT routed through `App\Support\Design\
 * StatusIntent`.
 *
 * ---------------------------------------------------------------------------
 * Why not `StatusIntent` — scope mismatch, not an oversight
 * ---------------------------------------------------------------------------
 * `StatusIntent`'s own class-level doc block is explicit about what it is
 * scoped to: "Only two families are populated below: order lifecycle and
 * vendor processing — the two tables design-system.md §3.7 normatively
 * defines," and goes on to name several OTHER specs' status enums
 * (funeral-case-management, recurring-care-subscriptions, ...) that were
 * found but deliberately NOT mapped, because assigning them an intent is "a
 * design decision... not invented in this file" absent a design-system.md
 * §3.7 table for that family. `faq_articles.publish_state`
 * (`draft`/`published`/`unpublished`, `App\Domain\Faq\
 * FaqArticlePublishState`) is not order-lifecycle or vendor-processing
 * vocabulary, and design-system.md §3.7 does not define a FAQ table either
 * — it only gives ONE specific, narrow instruction for this exact badge
 * (`.kiro/specs/public-faq/tasks.md`'s design-system section, draft-badge
 * row): "`<x-mk.badge intent=neutral>` for a draft article — never
 * `intent=success` for an unpublished article." Forcing `publish_state`
 * through `StatusIntent::resolve()` would either (a) silently fall back to
 * its `neutral`/`question-mark-circle` UNRECOGNISED-status path for all
 * three FAQ states (never actually wrong per the tasks.md rule, but also
 * never informative — a published article would render identically to a
 * draft), or (b) require extending `StatusIntent::MAP` with a FAQ family
 * that class's own doc block says should come from a design-system.md
 * update, not be invented here. Neither is this batch's call to make
 * silently. A small, local, three-state mapping — exactly what the batch
 * brief for this Resource asked for — keeps the real, documented
 * instruction (never green/success for anything but `published`) enforced
 * without mis-scoping a class that explicitly says it does not cover this
 * vocabulary.
 *
 * ---------------------------------------------------------------------------
 * Colour choices
 * ---------------------------------------------------------------------------
 * - `draft` -> gray ("neutral" in design-system.md's vocabulary; the exact
 *   colour tasks.md's draft-badge row names).
 * - `unpublished` -> warning (amber), NOT gray. tasks.md only constrains
 *   `draft`'s colour explicitly; the batch brief for this Resource restates
 *   the constraint more broadly as "draft/unpublished both read as neutral
 *   or a non-success intent" — warning satisfies that (it is not success)
 *   while still letting an admin visually tell "never published" (gray)
 *   apart from "was live, then deliberately pulled" (amber) — two states
 *   `FaqArticlePublishState`'s own doc block says carry "very different
 *   operational meaning." Collapsing both to identical gray badges would
 *   lose exactly the distinction that class was written to preserve.
 * - `published` -> success (green) — the one state tasks.md/the batch brief
 *   agree unambiguously reads as success.
 *
 * Defensive fallback (gray + raw state as label) for anything outside
 * `FaqArticlePublishState::KNOWN_STATES`, mirroring `StatusIntent`'s own
 * "never throw, never crash a table render, degrade to neutral" posture —
 * `FaqArticle::booted()`'s `saving` hook already asserts a known state
 * before persistence, so this fallback should be unreachable in practice,
 * but a badge column render must never be the place a bad value surfaces
 * as a fatal error.
 */
final class FaqArticleStatusBadge
{
    /**
     * @var array<string, array{color: string, label: string}>
     */
    private const array MAP = [
        FaqArticlePublishState::DRAFT => ['color' => 'gray', 'label' => 'Draf'],
        FaqArticlePublishState::PUBLISHED => ['color' => 'success', 'label' => 'Dipublikasikan'],
        FaqArticlePublishState::UNPUBLISHED => ['color' => 'warning', 'label' => 'Tidak dipublikasikan'],
    ];

    private const string FALLBACK_COLOR = 'gray';

    public static function color(string $state): string
    {
        return self::MAP[$state]['color'] ?? self::FALLBACK_COLOR;
    }

    public static function label(string $state): string
    {
        return self::MAP[$state]['label'] ?? $state;
    }
}

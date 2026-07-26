<?php

declare(strict_types=1);

namespace App\Livewire\Public\Faq\Support;

use App\Domain\Faq\Models\FaqCategory;

/**
 * Presentation-only URL-slug transform for `/faq/kategori/{categorySlug}`
 * (`.kiro/specs/public-faq/design.md` Routes section).
 *
 * `faq_categories` has no `slug` column — only `code` (e.g. `CARA_MEMESAN`,
 * `App\Domain\Faq\FaqCategoryCode`) and `name` (e.g. "Cara Memesan"). This
 * batch is presentation-only and does not touch `app/Domain/Faq/**`
 * (`app/Domain/README.md`'s own rule), so it cannot add a migration column
 * for a "real" slug. Instead this class derives a stable, readable,
 * lowercase-hyphenated slug straight from the existing `code` value
 * (`CARA_MEMESAN` -> `cara-memesan`) — deterministic, reversible, and
 * requires no new domain state. All six known codes
 * (`FaqCategoryCode::KNOWN_CODES`) already produce unique slugs under this
 * transform (verified by inspection: no two codes collide once
 * underscore -> hyphen and lowercased).
 *
 * This class never queries the database and never bypasses
 * `FaqPublicQuery`/`FaqArticle::published()` — it only maps strings.
 */
final class FaqCategorySlug
{
    public static function fromCode(string $code): string
    {
        return strtolower(str_replace('_', '-', $code));
    }

    public static function forCategory(FaqCategory $category): string
    {
        return self::fromCode($category->code);
    }

    public static function matches(FaqCategory $category, string $slug): bool
    {
        return self::forCategory($category) === $slug;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Faq;

use InvalidArgumentException;

/**
 * The closed list of values `faq_articles.publish_state` may hold. Plain
 * string column with application-layer validation, not a Postgres enum
 * type — same established convention as
 * `App\Domain\Marketplace\ProductCode` et al. (see that
 * class's own doc block for why).
 *
 * ---------------------------------------------------------------------------
 * Three states, not two — `draft` vs `unpublished` is a deliberate,
 * documented judgement call, not an oversight
 * ---------------------------------------------------------------------------
 * requirements.md AC5 lists "publish" and "unpublish" as two independent
 * admin capabilities. If `unpublish` simply set an article back to `draft`,
 * an admin (or a later batch's UI) could no longer distinguish "this article
 * has never been reviewed and gone live" from "this article WAS live and a
 * human deliberately pulled it down" — two states with very different
 * operational meaning (the second implies real version history exists in
 * `faq_article_versions`; the first never has any). Keeping them distinct
 * costs nothing extra in the schema (still a single plain string column) and
 * lets a future admin screen render "Never published" vs "Unpublished on
 * <date>" accurately instead of collapsing both into one ambiguous "Draft"
 * label. `UNPUBLISHED::current_version` is NOT reset to 0 and its historical
 * `faq_article_versions` rows are NOT deleted on unpublish — only visibility
 * changes.
 */
final class FaqArticlePublishState
{
    /**
     * Never yet published. The article's own columns (title/body/etc.) are
     * freely editable and carry no version history yet
     * (`faq_articles.current_version` is `0` for every row in this state).
     */
    public const string DRAFT = 'draft';

    /**
     * Currently live and publicly visible. Every transition INTO this state
     * (`Actions\PublishFaqArticle`) appends exactly one `faq_article_versions`
     * snapshot row — see that class's own doc block for the "version = a
     * published state" reasoning.
     */
    public const string PUBLISHED = 'published';

    /**
     * Was published at least once (`current_version >= 1`), then explicitly
     * pulled from public visibility (`Actions\UnpublishFaqArticle`). Distinct
     * from `DRAFT` — see class-level doc block above. Can be republished
     * (`Actions\PublishFaqArticle` again), which appends a NEW version row
     * rather than reusing the last one, even if the content did not change
     * in between.
     */
    public const string UNPUBLISHED = 'unpublished';

    /**
     * @var list<string>
     */
    public const array KNOWN_STATES = [
        self::DRAFT,
        self::PUBLISHED,
        self::UNPUBLISHED,
    ];

    public static function isKnown(string $state): bool
    {
        return in_array($state, self::KNOWN_STATES, true);
    }

    /**
     * @throws InvalidArgumentException when `$state` is not one of
     *                                  `self::KNOWN_STATES`.
     */
    public static function assertKnown(string $state): void
    {
        if (! self::isKnown($state)) {
            throw new InvalidArgumentException(
                "Unknown FAQ article publish state [{$state}]. Known states: ".implode(', ', self::KNOWN_STATES).'.'
            );
        }
    }
}

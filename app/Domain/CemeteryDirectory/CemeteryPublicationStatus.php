<?php

declare(strict_types=1);

namespace App\Domain\CemeteryDirectory;

use InvalidArgumentException;

/**
 * The closed list of values `cemeteries.publication_status` may hold.
 * requirements.md AC2: "WHEN a user applies a city/regency filter THE
 * SYSTEM SHALL filter published TPU/TPS by that city/regency" — "published"
 * implies a non-published state exists; this is that state machine.
 *
 * Three states, not two — mirrors `App\Domain\Faq\FaqArticlePublishState`'s
 * own documented reasoning exactly: `draft` (never yet gone live) is kept
 * distinct from `unpublished` (was live, deliberately pulled) so an admin
 * screen (a later, not-yet-built batch — S4-T6/`admin-operations`) can
 * render "Belum pernah dipublikasikan" differently from "Ditarik dari
 * publikasi pada <date>" instead of collapsing both into one ambiguous
 * state. This batch (S4-T1, master data/seeds only) does not build the
 * admin publish/unpublish action itself — only the column and the closed
 * list it validates against — but seeds one deliberately-`draft` cemetery
 * so a later batch's "published-only" scoping has real fixture data to
 * prove against, the same reasoning `2026_07_26_170400_seed_faq_categories
 * _and_articles.php` applied to its one seeded draft article.
 *
 * Plain string column with application-layer validation, not a Postgres
 * enum type — same established convention as every other closed-list
 * string column in this codebase.
 */
final class CemeteryPublicationStatus
{
    /**
     * Never yet published. Not visible to any public directory query.
     */
    public const string DRAFT = 'draft';

    /**
     * Live and publicly visible. requirements.md AC2/AC12 scope every
     * public read to this state.
     */
    public const string PUBLISHED = 'published';

    /**
     * Was published at least once, then deliberately pulled. Distinct from
     * `DRAFT` — see class-level doc block.
     */
    public const string UNPUBLISHED = 'unpublished';

    public const string DEFAULT = self::DRAFT;

    /**
     * @var list<string>
     */
    public const array KNOWN_STATUSES = [
        self::DRAFT,
        self::PUBLISHED,
        self::UNPUBLISHED,
    ];

    public static function isKnown(string $status): bool
    {
        return in_array($status, self::KNOWN_STATUSES, true);
    }

    /**
     * @throws InvalidArgumentException when `$status` is not one of
     *                                  `self::KNOWN_STATUSES`.
     */
    public static function assertKnown(string $status): void
    {
        if (! self::isKnown($status)) {
            throw new InvalidArgumentException(
                "Unknown cemetery publication status [{$status}]. Known statuses: ".implode(', ', self::KNOWN_STATUSES).'.'
            );
        }
    }
}

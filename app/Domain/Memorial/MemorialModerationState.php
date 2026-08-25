<?php

declare(strict_types=1);

namespace App\Domain\Memorial;

use InvalidArgumentException;

/**
 * The closed list of moderation states for `memorial_contents.body` and
 * `memorial_media` rows — `.kiro/specs/memorial-and-qr/requirements.md`
 * AC6 ("THE SYSTEM SHALL moderate user-generated messages/media").
 *
 * Only `approved` rows ever render in `MemorialPublicProjection`; every
 * other state is deliberately invisible to the public surface.
 * `ModerateMemorialContent` may move a row from any state to
 * `approved`/`rejected`/`hidden` (a moderator re-hiding an approved
 * message is the normal flow), but never back to `pending` — pending is
 * the submission default, not a moderator's destination.
 */
enum MemorialModerationState: string
{
    case PENDING = 'pending';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case HIDDEN = 'hidden';

    /**
     * Submission default — nothing family-authored renders until a
     * moderator acts.
     */
    public const string DEFAULT = 'pending';

    /**
     * The three terminal destinations a moderator may set.
     *
     * @var list<string>
     */
    public const array MODERATOR_DESTINATIONS = [
        self::APPROVED->value,
        self::REJECTED->value,
        self::HIDDEN->value,
    ];

    /**
     * @var list<string>
     */
    public const array KNOWN_MODES = [
        self::PENDING->value,
        self::APPROVED->value,
        self::REJECTED->value,
        self::HIDDEN->value,
    ];

    public static function assertKnown(string $state): void
    {
        if (! in_array($state, self::KNOWN_MODES, true)) {
            throw new InvalidArgumentException(
                'Unknown memorial moderation state ['.$state.']. Known states: '.implode(', ', self::KNOWN_MODES).'.'
            );
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\Memorial;

use App\Domain\Memorial\Models\MemorialProfile;
use App\Platform\IdentityAccess\ActorContext;
use Carbon\CarbonImmutable;

/**
 * The public projection of a memorial — the ALLOWLIST view AC3 requires:
 * *"THE SYSTEM SHALL render the public projection using an explicit
 * field/media allowlist."*
 *
 * ---------------------------------------------------------------------------
 * The allowlist (everything else never renders)
 * ---------------------------------------------------------------------------
 * 1. `profileId` — the memorial profile's own UUID. Never any other
 *    identifier: `grave_record_id` stays out (a QR visitor must not
 *    learn which grave record backs the memorial — the grave record's
 *    own access modes govern that surface, and they are never
 *    circumvented through here).
 * 2. `displayName` — the family-authored `memorial_profiles.display_name`
 *    (set at create/publish). Deliberately NOT the grave record's
 *    `deceased_name` (AC7: nothing is copied from the grave record).
 * 3. `publishedAt` — the profile's own publication timestamp.
 * 4. `approvedContentBodies` — bodies of `memorial_contents` rows whose
 *    `moderation_state` is `approved` (AC6: pending/rejected/hidden
 *    never render).
 * 5. `acceptedMediaRefs` — `storage_ref`s (vault `documents.id` UUIDs,
 *    never file paths) of `memorial_media` rows whose `moderation_state`
 *    is `approved`. A media row can only exist when its vault document
 *    is `DocumentState::Accepted` (the model's creating guard), so the
 *    approved rows here are accepted documents by construction.
 *
 * Everything else on the profile — `grave_record_id`, `privacy_mode`,
 * `unpublished_at`, editors, consent evidence, QR tokens — is a private
 * field and NEVER renders.
 *
 * `$actor` is accepted for signature compatibility with
 * `ResolveMemorialQr` (the brief's verbatim call passes it) and is
 * reserved for future per-actor projection variants; visibility was
 * already decided before the projection is built, so no per-actor
 * filtering happens here today.
 */
final readonly class MemorialPublicProjection
{
    /**
     * @param  list<string>  $approvedContentBodies
     * @param  list<string>  $acceptedMediaRefs
     */
    public function __construct(
        public string $profileId,
        public ?string $displayName,
        public ?CarbonImmutable $publishedAt,
        public array $approvedContentBodies = [],
        public array $acceptedMediaRefs = [],
    ) {}

    public static function forProfile(MemorialProfile $profile, ?ActorContext $actor = null): self
    {
        return new self(
            profileId: (string) $profile->getKey(),
            displayName: $profile->display_name,
            publishedAt: $profile->published_at,
            approvedContentBodies: $profile->contents
                ->where('moderation_state', MemorialModerationState::APPROVED->value)
                ->pluck('body')
                ->all(),
            acceptedMediaRefs: $profile->media
                ->where('moderation_state', MemorialModerationState::APPROVED->value)
                ->pluck('storage_ref')
                ->all(),
        );
    }
}

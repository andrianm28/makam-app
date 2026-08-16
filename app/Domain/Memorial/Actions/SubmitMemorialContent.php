<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Actions;

use App\Domain\Memorial\MemorialModerationState;
use App\Domain\Memorial\Models\MemorialContent;
use App\Domain\Memorial\Models\MemorialProfile;
use App\Platform\Audit\AuditSource;

/**
 * AC6's family submission: a new message starts `pending` and is
 * invisible to the public projection until a moderator acts
 * (`ModerateMemorialContent`).
 *
 * Deliberately NOT audited and NOT an outbox event: the catalog has no
 * `memorial.content_submitted` event, the plan's brief names no
 * constant for it, and the moderation transition is what carries the
 * durable trail. Consent gating for the family surface happens at the
 * page/route layer (Task 4's `MemorialFamilyPage`), not here.
 *
 * `$actorReference`/`$actorRole`/`$auditSource` are accepted for module-
 * wide action-signature consistency and reserved for the day the
 * submission path gains its own audit requirement; no write path uses
 * them yet.
 */
final readonly class SubmitMemorialContent
{
    public function __invoke(
        MemorialProfile $profile,
        string $body,
        int|string $actorReference,
        string $actorRole,
        ?AuditSource $auditSource = null,
    ): MemorialContent {
        return $profile->contents()->create([
            'body' => $body,
            'moderation_state' => MemorialModerationState::DEFAULT,
        ]);
    }
}

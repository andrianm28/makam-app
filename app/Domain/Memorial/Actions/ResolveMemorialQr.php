<?php

declare(strict_types=1);

namespace App\Domain\Memorial\Actions;

use App\Domain\Memorial\Exceptions\MemorialNotVisibleException;
use App\Domain\Memorial\MemorialPublicProjection;
use App\Domain\Memorial\Models\MemorialQrToken;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\MemorialMode;
use App\Platform\IdentityAccess\ActorContext;

/**
 * The gate-checked QR resolve — the ONE public read path for a
 * memorial (`.kiro/specs/memorial-and-qr/design.md`'s "Sequence — QR
 * resolve, gate-checked", AC3/AC4/AC5).
 *
 * Order matters, exactly as the sequence diagram draws it:
 *
 * 1. GATE FIRST. A closed `G-MEM-01` must not even attempt a token
 *    lookup — a lookup that answers "gate closed" for a valid token and
 *    "not found" for an invalid one would itself leak which tokens
 *    exist.
 * 2. Token lookup (active only — revoked/rotated tokens are
 *    indistinguishable from tokens that never existed).
 * 3. Publication (`published_at !== null`). Unpublish is INSTANT (AC5,
 *    `UnpublishMemorial`): the moderator's "Kode QR yang sudah dicetak
 *    berhenti berlaku seketika" holds because a printed token stops
 *    resolving the moment `published_at` clears — and a profile that
 *    was never published never resolves, fail-closed. This is the
 *    public-mode resolution gate; the family surface is consent-gated
 *    independently and is unaffected.
 * 4. Visibility (`MemorialProfile::isVisibleTo`) with `hasToken: true`
 *    — every resolver physically holds the token.
 * 5. The allowlist projection (`MemorialPublicProjection`), never the
 *    model.
 *
 * Every denial — closed gate, unknown/revoked token, unpublished,
 * privacy — throws the SAME `MemorialNotVisibleException` (AC5's
 * negative criterion): nothing in the error surface reveals which case
 * applied.
 */
final readonly class ResolveMemorialQr
{
    public function __invoke(string $token, ?ActorContext $actor): MemorialPublicProjection
    {
        if (app(ModeResolver::class)->memorialMode() !== MemorialMode::PublicMemorial) {
            throw MemorialNotVisibleException::becauseGateClosed();
        }

        $qr = MemorialQrToken::query()
            ->where('token', $token)
            ->whereNull('revoked_at')
            ->with('profile.contents', 'profile.media', 'profile.editors')
            ->first();

        if (! $qr instanceof MemorialQrToken) {
            throw MemorialNotVisibleException::becauseUnknownToken();
        }

        $profile = $qr->profile;

        // Publication gate (AC5's immediate unpublish): an unpublished
        // profile's token is uniformly not-visible, matching the admin
        // unpublish modal's "Kode QR yang sudah dicetak berhenti berlaku
        // seketika" — and a never-published profile never resolves at all
        // (fail-closed).
        if ($profile->published_at === null) {
            throw MemorialNotVisibleException::becauseUnpublished();
        }

        // Privacy modes: public → anyone; unlisted → token holders (all
        // resolvers hold the token); family_only → token + an active
        // family editor for the actor; private → active editor only
        // (token insufficient).
        if (! $profile->isVisibleTo($actor, hasToken: true)) {
            throw MemorialNotVisibleException::becausePrivacy($profile->privacy_mode);
        }

        return MemorialPublicProjection::forProfile($profile, $actor);
    }
}

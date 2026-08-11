<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

/**
 * The blank-`--reason` guard shared by the four `identity:*` grant/revoke
 * commands.
 *
 * ---------------------------------------------------------------------------
 * Why this is not just `trim()`
 * ---------------------------------------------------------------------------
 * PHP's `trim()` strips only the ASCII whitespace set (" \t\n\r\0\x0B"). It
 * does NOT strip Unicode whitespace, so `trim("\u{00A0}") === ""` is `false`
 * — a `--reason` consisting solely of a non-breaking space (or an
 * ideographic space, U+3000) reads as a non-blank justification to every
 * `trim()`-based check while being invisible to a human reviewing the audit
 * trail. A grant whose recorded justification is one invisible character is
 * indistinguishable, in review, from one nobody authorised.
 *
 * `\p{Z}` covers Unicode separators (including U+00A0 and U+3000) and
 * `\p{C}` covers control/format characters (including zero-width joiners and
 * the like), so this rejects the whole class rather than playing whack-a-mole
 * with individual code points.
 *
 * ---------------------------------------------------------------------------
 * This is defence in depth, NOT the root fix
 * ---------------------------------------------------------------------------
 * `Audit::record()` performs the authoritative mandatory-reason check for
 * every action on `SensitiveActions::ACTIONS`, and it uses `trim()` — so the
 * same bypass exists there for every already-merged sensitive action across
 * the application (`PAYMENT_REFUND`, `VENDOR_PAYOUT`, `MFA_RESET`,
 * `DOCUMENT_DELETE`, `JOURNAL_REVERSAL`, and others), not only this lane's
 * four. Fixing that shared check is deliberately out of this lane's scope: it
 * is shared infrastructure that already-merged lanes depend on, and changing
 * it needs its own review. Recorded as a cross-cutting finding in this lane's
 * merge sign-off bundle.
 *
 * Consequence worth stating plainly: closing the hole here means the
 * `identity:*` commands are safe, NOT that the platform is.
 */
trait RequiresAuditReason
{
    /**
     * True when `$reason` is absent, empty, or consists only of whitespace
     * — including Unicode whitespace that `trim()` would leave in place.
     */
    private function reasonIsBlank(?string $reason): bool
    {
        if ($reason === null) {
            return true;
        }

        return preg_match('/^[\p{Z}\p{C}\s]*$/u', $reason) === 1;
    }
}

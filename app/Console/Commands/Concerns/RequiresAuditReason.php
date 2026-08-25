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
 * This is defence in depth; the root fix lives in Audit::reasonIsBlank()
 * ---------------------------------------------------------------------------
 * `Audit::record()` performs the authoritative mandatory-reason check for
 * every action on `SensitiveActions::ACTIONS`. It used to use `trim()`, so
 * the same bypass existed there for every already-merged sensitive action
 * across the application (`PAYMENT_REFUND`, `VENDOR_PAYOUT`, `MFA_RESET`,
 * `DOCUMENT_DELETE`, `JOURNAL_REVERSAL`, and others), not only this lane's
 * four. That shared check now runs this same pattern, so the platform-wide
 * hole is closed at its root; this trait remains as the command-layer
 * defence-in-depth layer above it.
 *
 * This copy of the pattern is deliberately identical to the root one and must
 * be changed with it. `Platform\Audit\Rules\NonBlankReason` guards the HTTP
 * boundary the same way, but delegates to `Audit::reasonIsBlank()` instead of
 * copying it — this trait keeps its own copy only because it must produce the
 * command-layer error message operators see before any Action is invoked.
 *
 * Four further blank-reason gates exist elsewhere and still use plain
 * `trim()`; they are listed in `Audit::reasonIsBlank()`'s docblock. Do not
 * assume the pattern below is one of only two copies. See
 * `Audit::reasonIsBlank()` for the full rationale, including why both failure
 * paths fail closed and which invisible code points remain a known residual.
 */
trait RequiresAuditReason
{
    /**
     * True when `$reason` is absent, empty, consists only of whitespace
     * — including Unicode whitespace that `trim()` would leave in place —
     * or cannot be evaluated at all (malformed UTF-8, or any other PCRE
     * error).
     */
    private function reasonIsBlank(?string $reason): bool
    {
        if ($reason === null) {
            return true;
        }

        // `!== 0`, not `=== 1`: a `false` return must count as blank.
        return preg_match('/^[\p{Z}\p{C}\s]*$/u', $reason) !== 0;
    }
}

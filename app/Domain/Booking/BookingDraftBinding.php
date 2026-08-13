<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Booking\Models\BookingDraft;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * Binds a `BookingDraft` to the session that started it.
 *
 * A draft holds customer and deceased PII (name, mobile, email, home
 * address, dates of birth/death) from Step 6 onward. Before this class the
 * draft id was the ONLY thing standing between a stranger and that PII: the
 * id travels in the URL (`/pemesanan-makam/draft/{draftId}`), so it reaches
 * browser history, `Referer` headers and web-server access logs, and
 * anything holding it got full read AND write on the draft. Possession of an
 * identifier is not authorisation.
 *
 * So a draft is additionally bound to a high-entropy secret issued to the
 * originating session. The secret lives in the session; only its SHA-256
 * lives on the row, so a database read alone cannot reconstruct it. Resuming
 * requires proving possession of the secret, not merely knowing the id.
 *
 * Deliberately session-only: the coordinator's ruling for this lane is that
 * cross-device resume is NOT a requirement. Opening the link on another
 * device is therefore expected to fail closed, and that is the intended
 * trade — a resume token that survived a shared URL would reintroduce the
 * exact hole this closes.
 *
 * Fails closed everywhere: an absent session entry, an absent hash, or a
 * mismatch all deny. A draft created before this binding existed has a null
 * hash and is unreadable by design rather than grandfathered in.
 */
final class BookingDraftBinding
{
    private const SESSION_KEY = 'booking_draft_secrets';

    /**
     * Issue a fresh secret for a newly created draft and persist its hash.
     * Called once, by the Action that creates the draft.
     */
    public static function issue(BookingDraft $draft): void
    {
        $secret = Str::random(64);

        $draft->forceFill(['resume_secret_hash' => hash('sha256', $secret)])->save();

        Session::put(self::SESSION_KEY.'.'.$draft->id, $secret);
    }

    /**
     * Does the current session hold the secret this draft was issued with?
     *
     * `hash_equals` rather than `===` so a timing signal cannot be used to
     * recover the stored hash byte by byte.
     */
    public static function isBound(BookingDraft $draft): bool
    {
        $storedHash = $draft->resume_secret_hash;

        if (! is_string($storedHash) || $storedHash === '') {
            return false;
        }

        $secret = Session::get(self::SESSION_KEY.'.'.$draft->id);

        if (! is_string($secret) || $secret === '') {
            return false;
        }

        return hash_equals($storedHash, hash('sha256', $secret));
    }

    /**
     * Drop this session's claim on a draft. Used when a draft no longer
     * resolves, so a stale secret does not linger in the session forever.
     */
    public static function forget(string $draftId): void
    {
        Session::forget(self::SESSION_KEY.'.'.$draftId);
    }
}

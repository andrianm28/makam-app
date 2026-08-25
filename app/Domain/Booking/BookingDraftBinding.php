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
 * Session secret OR proven authenticated ownership — never a URL alone. The
 * original ruling for this lane was session-only, full stop: cross-device
 * resume was NOT a requirement, and opening the link on another device was
 * expected to fail closed. That predates customer accounts existing at all.
 * Now that a draft can carry a `user_id`, `App\Livewire\Public\Booking\
 * BookingWizard::resolveDraftById()` additionally accepts a session-secret
 * miss when the current authenticated user IS the draft's owner
 * (`$candidate->user_id === auth()->id()`) — a strictly stronger proof than
 * the session secret, since unlike the secret it cannot be reconstructed
 * from a shared URL. A successful ownership rescue still calls `issue()`
 * below, re-establishing normal session-bound resume for the rest of that
 * visit. Possession of the id alone is still never authorisation: a guest,
 * or an authenticated user who is not the owner, fails closed exactly as
 * before.
 *
 * Fails closed everywhere: an absent session entry, an absent hash, or a
 * mismatch all deny. A draft created before this binding existed has a null
 * hash and is unreadable by design rather than grandfathered in.
 */
final class BookingDraftBinding
{
    private const SESSION_KEY = 'booking_draft_secrets';

    /**
     * Issue a fresh secret for a draft and persist its hash. Two call sites:
     * the Action that creates the draft, and
     * `App\Livewire\Public\Booking\BookingWizard::resolveDraftById()`, which
     * calls this again on every successful ownership rescue to re-establish
     * the session binding for the rest of that visit.
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

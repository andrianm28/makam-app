<?php

declare(strict_types=1);

namespace App\Platform\IdentityAccess\Mfa\Totp;

/**
 * RFC 6238 TOTP: `T = floor((unixTime - T0) / X)`, then `HOTP(K, T)`
 * (`Hotp::generate()`). `T0 = 0` and `X = 30` seconds are RFC 6238's own
 * defaults and match virtually every real authenticator app (Google
 * Authenticator, Authy, 1Password, etc.) — this class hardcodes neither,
 * but every real call site in this module (`Mfa\MfaEnrolmentService`,
 * `Mfa\MfaChallengeService`) constructs it with those exact defaults.
 *
 * ---------------------------------------------------------------------------
 * Digit count: 6, not 8
 * ---------------------------------------------------------------------------
 * RFC 6238 Appendix B's own published test vectors use 8 digits, but 6 is
 * the near-universal convention for real authenticator apps, and is what
 * this module's callers actually use. Both digit counts run through the
 * exact same `Hotp::generate()` dynamic-truncation code — only the final
 * `% 10**digits` differs — and both are independently verified against
 * official RFC vectors:
 *
 * - 8-digit path: `TotpRfc6238VectorsTest` asserts this class's own output
 *   against RFC 6238 Appendix B's published 8-digit codes, exactly.
 * - 6-digit path: `HotpRfc4226VectorsTest` asserts `Hotp::generate()`
 *   directly against RFC 4226 Appendix D's own published 6-digit vectors
 *   (counters 0-9), which is the same DT + modulus computation this class
 *   delegates to — so the 6-digit production path is verified against an
 *   official source just as rigorously as the 8-digit one, not merely
 *   "should work by analogy."
 *
 * ---------------------------------------------------------------------------
 * Replay protection (`verify()`)
 * ---------------------------------------------------------------------------
 * A TOTP code is valid for its entire 30-second (or wider, with window
 * tolerance) step — without an explicit check, the SAME code could be
 * replayed by an attacker who observed it (e.g. over the network, or a
 * shoulder-surfed screen) at any point before it naturally expires. This is
 * a well-known TOTP pitfall, not a hypothetical: RFC 6238 itself does not
 * mandate a replay defense, leaving it to implementers.
 *
 * `verify()` takes the LAST successfully-verified counter (persisted by the
 * caller, e.g. `mfa_enrolments.last_verified_counter`) and refuses to
 * accept any candidate counter that is not STRICTLY greater than it — even
 * if that candidate is mathematically valid and inside the tolerance
 * window. Once a step is consumed, it and every step before it are dead
 * forever for this enrolment.
 */
final class Totp
{
    public function __construct(
        private readonly int $t0 = 0,
        private readonly int $period = 30,
    ) {}

    public function timeStepFor(int $timestamp): int
    {
        return intdiv($timestamp - $this->t0, $this->period);
    }

    public function generate(string $keyBytes, int $timestamp, int $digits = 6): string
    {
        return Hotp::generate($keyBytes, $this->timeStepFor($timestamp), $digits);
    }

    /**
     * @param  string  $keyBytes  Raw (Base32-decoded) HMAC key bytes.
     * @param  string  $submittedCode  The code the actor typed in.
     * @param  int  $timestamp  Server-side "now" (unix seconds) — never
     *                          caller-supplied from an untrusted client.
     * @param  int|null  $lastVerifiedCounter  The enrolment's last consumed
     *                                         counter, or `null` for an
     *                                         enrolment that has never
     *                                         completed a verification
     *                                         (e.g. a brand-new pending
     *                                         enrolment).
     * @param  int  $window  How many steps of clock drift to tolerate on
     *                       EACH side of the current step (RFC 6238 §5.2
     *                       explicitly permits a small window; ±1 step is
     *                       ±30s here, a common, conservative default).
     */
    public function verify(
        string $keyBytes,
        string $submittedCode,
        int $timestamp,
        ?int $lastVerifiedCounter,
        int $digits = 6,
        int $window = 1,
    ): TotpVerificationResult {
        $currentStep = $this->timeStepFor($timestamp);

        for ($offset = -$window; $offset <= $window; $offset++) {
            $candidateCounter = $currentStep + $offset;

            // Replay/reuse guard: a step that was already consumed (or any
            // step before it) is never accepted again, regardless of
            // whether the code is still mathematically correct for it.
            if ($lastVerifiedCounter !== null && $candidateCounter <= $lastVerifiedCounter) {
                continue;
            }

            $candidateCode = Hotp::generate($keyBytes, $candidateCounter, $digits);

            if (hash_equals($candidateCode, $submittedCode)) {
                return TotpVerificationResult::success($candidateCounter);
            }
        }

        return TotpVerificationResult::failure();
    }
}

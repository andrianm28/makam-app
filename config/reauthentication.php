<?php

declare(strict_types=1);

/**
 * S3-T3 (`platform-identity-and-access` requirements.md AC3): "WHEN a user
 * attempts a financial, gate, bank-detail, certificate, plot-override, or
 * bulk-export action THE SYSTEM SHALL require recent re-authentication,
 * using a freshness window that is configured, not hardcoded."
 *
 * This is the one threshold in this codebase's identity/access work that
 * AC3's own text singles out as needing a real, env-overridable Laravel
 * config file rather than a PHP class constant — every other threshold
 * added so far (`App\Platform\Outbox\OutboxPublisher::STALE_CLAIM_SECONDS`,
 * `App\Platform\IdentityAccess\Reauthentication\ReauthenticationRateLimiter`'s 5 attempts / 60
 * seconds) was fine as a constant because no cited spec text demanded
 * otherwise for those. AC3 explicitly does for this one.
 *
 * ---------------------------------------------------------------------------
 * Default: 900 seconds (15 minutes)
 * ---------------------------------------------------------------------------
 * `docs/security/authentication-and-mfa.md` §5 says only "Suggested
 * recent-auth window is configuration-backed and security-approved" —
 * it does not name a number, and no other cited document in this repo
 * does either. 15 minutes is a judgement call for this batch, chosen
 * because:
 *
 * - it is short enough that a session left unattended after a sensitive
 *   action (payout approval, bank-detail change, certificate issue) is
 *   very unlikely to still be "fresh" for an attacker who gets physical
 *   or session access afterward;
 * - it is long enough that an operator working through a short multi-step
 *   sensitive workflow (e.g. reviewing then approving a payout) is not
 *   forced to re-authenticate between consecutive steps of the same task,
 *   which would otherwise push people toward insecure workarounds;
 * - it matches the common industry default for "recent authentication"
 *   windows guarding step-up actions (e.g. payment processors' typical
 *   10-30 minute step-up windows), without this repo having its own prior
 *   figure to anchor to.
 *
 * A future batch or security review may revisit this figure once real
 * operational data exists — flagged the same way `OutboxPublisher`'s own
 * `STALE_CLAIM_SECONDS` doc block flags its own judgement-call default.
 */
return [
    /*
    |--------------------------------------------------------------------
    | Re-authentication freshness window
    |--------------------------------------------------------------------
    |
    | How many seconds ago `ActorContext::$lastAuthenticatedAt` must fall
    | within for `App\Http\Middleware\RequireRecentAuthentication` to treat
    | the actor as "recently authenticated" and let a sensitive-action
    | request through without a fresh re-authentication challenge.
    |
    | Env-overridable per AC3's explicit "configured, not hardcoded"
    | requirement — see this file's class-level doc comment for the
    | default value and the reasoning behind it.
    |
    */
    'freshness_seconds' => (int) env('REAUTHENTICATION_FRESHNESS_SECONDS', 900),
];

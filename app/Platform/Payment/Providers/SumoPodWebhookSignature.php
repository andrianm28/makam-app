<?php

declare(strict_types=1);

namespace App\Platform\Payment\Providers;

use App\Platform\Payment\Http\WebhookCredentials;
use App\Platform\Payment\PaymentProviders;
use Carbon\CarbonImmutable;

/**
 * AC6's signature and replay-window check, implementing ADR-0033 §Decision:
 * "Two verification mechanisms, either acceptable: Svix signatures (`svix-id`,
 * `svix-timestamp`, `svix-signature`; HMAC-SHA256 over `{id}.{timestamp}.{rawBody}`
 * with `whsec_…` secret) or a shared-token header `X-Webhook-Token` (`whtok_…`)."
 *
 * ---------------------------------------------------------------------------
 * Four decisions that are security-relevant and therefore not silent
 * ---------------------------------------------------------------------------
 * 1. **Signature before timestamp.** An unauthenticated caller learns nothing
 *    about which timestamps this endpoint accepts, and — more usefully — a
 *    `TimestampOutsideWindow` outcome then means an AUTHENTIC delivery arrived
 *    outside the window, which is the actual definition of a replay. Reversing
 *    the order (Svix's own reference libraries check the timestamp first,
 *    because it is cheaper) would make `REJECTED_REPLAY` reachable by any
 *    forger with a clock, and the recorded status would stop meaning anything.
 *
 * 2. **Presenting Svix headers commits the delivery to the Svix mechanism.** A
 *    valid shared token cannot rescue a forged signature. Otherwise the weaker
 *    of two enabled mechanisms would silently become the only one that
 *    mattered.
 *
 * 3. **Constant-time comparison everywhere** (`hash_equals`), including the
 *    shared token, and every candidate secret is tried even after a match, so
 *    the number of comparisons does not depend on which secret matched. That
 *    matters during a rotation, when both an old and a new secret are live:
 *    a timing signal for "matched the first secret" narrows an attacker's
 *    search.
 *
 * 4. **No configured credential rejects everything** (`NotConfigured`). There
 *    is no development shortcut, no "skip verification when the secret is
 *    empty" branch. An environment that has not been given a secret cannot
 *    accept a webhook, which is the only safe reading of AC6.
 *
 * ---------------------------------------------------------------------------
 * Secret decoding
 * ---------------------------------------------------------------------------
 * A Svix secret is `whsec_` + base64 of the raw HMAC key. The prefix is
 * stripped and the remainder base64-decoded (strict). If it is not valid
 * base64 — some deployments store the key verbatim — the remainder is used as
 * raw key bytes rather than failing closed on a formatting difference alone.
 * That fallback cannot weaken anything: a wrong key produces a wrong HMAC.
 *
 * NOT TESTED against the live SumoPod sandbox — no sandbox webhook has been
 * exercised from this host (the plan's Task 8 owns that smoke run). What is
 * tested is conformance to the Svix scheme ADR-0033 names, against locally
 * generated test secrets.
 */
final readonly class SumoPodWebhookSignature
{
    private const string SECRET_PREFIX = 'whsec_';

    private const string SUPPORTED_VERSION = 'v1';

    /**
     * @param  list<string>  $signingSecrets  current first, then rotating older ones
     * @param  list<string>  $sharedTokens
     */
    public function __construct(
        private array $signingSecrets,
        private array $sharedTokens,
        private bool $allowSharedToken,
        private int $replayWindowSeconds,
    ) {}

    /**
     * Build from `config/payment.php` for the active provider — the only place
     * the credentials are read, and they are read fresh per request rather
     * than cached on a singleton so a rotated secret takes effect without a
     * process restart.
     */
    public static function fromConfig(): self
    {
        $provider = (string) config('payment.default', PaymentProviders::SUMOPOD_SANDBOX);

        /** @var list<string> $secrets */
        $secrets = (array) config("payment.providers.{$provider}.webhook_signing_secrets", []);
        /** @var list<string> $tokens */
        $tokens = (array) config("payment.providers.{$provider}.webhook_tokens", []);

        return new self(
            signingSecrets: array_values($secrets),
            sharedTokens: array_values($tokens),
            allowSharedToken: (bool) config('payment.webhook.allow_shared_token', false),
            replayWindowSeconds: (int) config('payment.webhook.replay_window_seconds', 300),
        );
    }

    public function verify(
        WebhookCredentials $credentials,
        ?string $svixId,
        ?string $svixTimestamp,
        string $rawBody,
        CarbonImmutable $now,
    ): SignatureVerification {
        $svixAttempted = $svixId !== null
            || $svixTimestamp !== null
            || $credentials->svixSignature !== null;

        if ($svixAttempted) {
            return $this->verifySvix($credentials->svixSignature, $svixId, $svixTimestamp, $rawBody, $now);
        }

        if ($this->allowSharedToken && $this->sharedTokens !== [] && $credentials->sharedToken !== null) {
            return $this->verifySharedToken($credentials->sharedToken);
        }

        // Either no credential arrived at all, or the only one that did belongs
        // to a mechanism this environment has not enabled. Both are refusals,
        // never a pass-through.
        return SignatureVerification::failed(SignatureOutcome::MechanismUnavailable);
    }

    private function verifySvix(
        ?string $signatureHeader,
        ?string $svixId,
        ?string $svixTimestamp,
        string $rawBody,
        CarbonImmutable $now,
    ): SignatureVerification {
        if ($signatureHeader === null || $svixId === null || $svixTimestamp === null) {
            return SignatureVerification::failed(SignatureOutcome::MalformedSignatureHeader);
        }

        if ($this->signingSecrets === []) {
            return SignatureVerification::failed(SignatureOutcome::NotConfigured);
        }

        $signedContent = "{$svixId}.{$svixTimestamp}.{$rawBody}";
        $presented = $this->parseSignatureHeader($signatureHeader);

        if ($presented === []) {
            return SignatureVerification::failed(SignatureOutcome::MalformedSignatureHeader);
        }

        $matched = false;

        // Every secret against every presented signature, with no early exit —
        // see decision 3 in the class doc block.
        foreach ($this->signingSecrets as $secret) {
            $expected = base64_encode(hash_hmac('sha256', $signedContent, $this->keyFor($secret), true));

            foreach ($presented as $candidate) {
                if (hash_equals($expected, $candidate)) {
                    $matched = true;
                }
            }
        }

        if (! $matched) {
            return SignatureVerification::failed(SignatureOutcome::SignatureMismatch);
        }

        return $this->checkReplayWindow($svixTimestamp, $now);
    }

    /**
     * Only now, with the delivery proven authentic, is the timestamp meaningful.
     */
    private function checkReplayWindow(string $svixTimestamp, CarbonImmutable $now): SignatureVerification
    {
        if (preg_match('/\A-?\d{1,19}\z/D', $svixTimestamp) !== 1) {
            return SignatureVerification::failed(SignatureOutcome::TimestampMalformed);
        }

        $skew = abs($now->getTimestamp() - (int) $svixTimestamp);

        if ($skew > $this->replayWindowSeconds) {
            return SignatureVerification::failed(SignatureOutcome::TimestampOutsideWindow);
        }

        return SignatureVerification::verified(SignatureMechanism::Svix);
    }

    private function verifySharedToken(string $presented): SignatureVerification
    {
        $matched = false;

        foreach ($this->sharedTokens as $token) {
            if (hash_equals($token, $presented)) {
                $matched = true;
            }
        }

        if (! $matched) {
            return SignatureVerification::failed(SignatureOutcome::SignatureMismatch);
        }

        // No replay-window check is possible on this path and none is faked —
        // ADR-0033's shared-token delivery carries no provider timestamp. That
        // is precisely why `payment.webhook.allow_shared_token` defaults to
        // false; `provider_events.signature_mechanism` records which path a
        // given event took so the difference stays visible after the fact.
        return SignatureVerification::verified(SignatureMechanism::SharedToken);
    }

    /**
     * `v1,<base64>` entries, space-separated. Entries of an unsupported
     * version are dropped rather than compared, so a future `v2` scheme cannot
     * be accepted by a verifier that does not implement it.
     *
     * @return list<string>
     */
    private function parseSignatureHeader(string $header): array
    {
        $signatures = [];

        foreach (preg_split('/\s+/', trim($header), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $entry) {
            $parts = explode(',', $entry, 2);

            if (count($parts) !== 2 || $parts[0] !== self::SUPPORTED_VERSION || $parts[1] === '') {
                continue;
            }

            $signatures[] = $parts[1];
        }

        return $signatures;
    }

    private function keyFor(string $secret): string
    {
        $material = str_starts_with($secret, self::SECRET_PREFIX)
            ? substr($secret, strlen(self::SECRET_PREFIX))
            : $secret;

        $decoded = base64_decode($material, true);

        return $decoded === false ? $material : $decoded;
    }
}

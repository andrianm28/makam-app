<?php

declare(strict_types=1);
use App\Platform\Correlation\CorrelationContext;
use Sentry\Event;

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),

    // AGENTS.md §Observability: "Never place restricted data in logs,
    // Pulse, Horizon tags, or error trackers." This is the flag that
    // makes that true for Sentry's own default PII collection — must
    // stay false; the before_send scrubber below is a second layer,
    // not a substitute for this.
    'send_default_pii' => false,

    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.0),

    'environment' => env('APP_ENV'),

    'release' => env('SENTRY_RELEASE'),

    /*
    |--------------------------------------------------------------------------
    | before_send scrubber
    |--------------------------------------------------------------------------
    |
    | Per docs/superpowers/plans/2026-08-18-public-beta-release.md Lane E2's
    | original spec (observability-stack.md §3/§5): scrub NIK/KK (Indonesian
    | national/family ID numbers) and signed document-vault URLs before
    | transmission — AGENTS.md §Observability's "never place restricted data
    | in... error trackers" applies to this exact surface. Also attaches the
    | existing correlation ID as a tag so a Sentry error links back to its
    | outbox event / journal entry via the same id used everywhere else in
    | this codebase's tracing (AssignCorrelationId middleware).
    |
    */
    'before_send' => static function (Event $event): ?Event {
        $correlationId = app(CorrelationContext::class)->current();

        if ($correlationId !== null) {
            $event->setTag('correlation_id', $correlationId->value);
        }

        $event->setTag('image_digest', (string) env('APP_IMAGE_DIGEST', 'unknown'));

        // NIK: 16 consecutive digits. KK: same format, same scrub — this
        // codebase does not distinguish the two at the string-pattern
        // level, matching how docs/operations/observability-stack.md §5
        // itself does not either.
        $nikKkPattern = '/\b\d{16}\b/';

        // A DocumentVault signed URL — scrub the whole query string, not
        // just the signature param, since the path + expiry + signature
        // together are the sensitive artifact, not any one field alone.
        $signedUrlPattern = '#(https?://[^\s]+/vault/[^\s?]+)\?[^\s]+#';

        $scrub = static function (mixed $value) use ($nikKkPattern, $signedUrlPattern): mixed {
            if (! is_string($value)) {
                return $value;
            }

            $value = preg_replace($nikKkPattern, '[REDACTED-NIK-KK]', $value) ?? $value;
            $value = preg_replace($signedUrlPattern, '$1?[REDACTED-SIGNATURE]', $value) ?? $value;

            return $value;
        };

        if (($message = $event->getMessage()) !== null) {
            $event->setMessage($scrub($message));
        }

        return $event;
    },
];

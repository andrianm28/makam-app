<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

use App\Platform\Outbox\Exceptions\OutboxPayloadKeyNotAllowedException;

/**
 * AC7: "THE SYSTEM SHALL NOT include restricted data in an event —
 * references only, same rule as audit and notification payloads."
 *
 * ---------------------------------------------------------------------------
 * Denylist, not `MetadataAllowlist`'s allowlist — why this module makes the
 * opposite choice
 * ---------------------------------------------------------------------------
 * `App\Platform\Audit\MetadataAllowlist` uses an allowlist because
 * `audit_events.metadata` is a small shape THIS PLATFORM OWNS end to end —
 * four keys, all defined by `Audit::record()`'s own contract, extended only
 * by a deliberate, reviewed code change.
 *
 * `outbox_events.payload` is different in kind, not just size: it carries
 * the envelope's `data` field, whose shape is defined PER EVENT by whichever
 * domain module produces it (`event-catalog.md` names 23+ producers this
 * module has no authority over and, per AC3, must not restate). An
 * allowlist here would mean this module pre-declaring every field name
 * every future producer is allowed to use — which either becomes a second,
 * unauthorized copy of each event's schema (exactly what AC3 forbids) or
 * degenerates into allowing everything, which is not an allowlist at all.
 *
 * A narrow DENYLIST of key names strongly associated with restricted
 * content — drawn directly from `AGENTS.md` §Authorization and files ("Store
 * KTP, KK, death documents, payment proof, and work evidence privately")
 * and `platform-audit` requirements.md's own Negative criteria ("No KTP,
 * KK, death-certificate content, bank detail, credential, or full address")
 * — is the workable minimum: it does not need to know a producer's full
 * schema, only to catch the specific field NAMES this project has already
 * identified as restricted, checked recursively so a producer cannot evade
 * it by nesting.
 *
 * ---------------------------------------------------------------------------
 * What this does NOT do — read before treating this as complete DLP
 * ---------------------------------------------------------------------------
 * This is a key-NAME check only. It does not inspect values (a restricted
 * number stored under an innocuously-named key passes silently), and the
 * list below is a starting set, not exhaustive — extending it is meant to
 * be, same as `MetadataAllowlist`, a deliberate reviewed change, not a
 * substitute for producers themselves following "references only."
 */
final class PayloadClassification
{
    /**
     * @var list<string>
     */
    public const array DENYLISTED_KEYS = [
        'ktp', 'ktp_number', 'nik',
        'kk', 'kk_number',
        'bank_account', 'bank_account_number', 'bank_detail',
        'card_number', 'cvv',
        'password', 'credential', 'secret',
        'death_certificate', 'death_certificate_content',
        'full_address',
        'file_content', 'document_body',
    ];

    /**
     * @param  array<array-key, mixed>  $data
     */
    public static function assertSafe(array $data): void
    {
        self::assertNoDenylistedKeys($data);
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private static function assertNoDenylistedKeys(array $data, string $path = ''): void
    {
        foreach ($data as $key => $value) {
            $normalizedKey = mb_strtolower((string) $key);
            $currentPath = $path === '' ? (string) $key : "{$path}.{$key}";

            if (in_array($normalizedKey, self::DENYLISTED_KEYS, true)) {
                throw OutboxPayloadKeyNotAllowedException::forKey($currentPath);
            }

            if (is_array($value)) {
                self::assertNoDenylistedKeys($value, $currentPath);
            }
        }
    }
}

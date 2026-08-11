<?php

declare(strict_types=1);

namespace App\Platform\Audit;

use App\Platform\Audit\Exceptions\AuditMetadataKeyNotAllowedException;

/**
 * AC5: "THE SYSTEM SHALL NOT store restricted data in an audit
 * payload." design.md: "`metadata` accepts an allowlist. Restricted
 * classifications are rejected at write time rather than by reviewer
 * discipline — the same pattern as notification templates."
 * `tasks.md`: "Implement a metadata allowlist that rejects restricted
 * classifications at write time."
 *
 * Deliberately a minimal starter set. No consuming spec has told this
 * batch what metadata it needs yet (`tasks.md`'s still-open
 * reconciliation line), so this list covers only the common "what
 * changed, on which reference" shape — nothing speculative. Extending
 * it is meant to be a deliberate, reviewed code change: that review
 * step is the actual control keeping a KTP number, bank detail, or
 * other restricted field from being smuggled in through a casually
 * added key. Requirements.md's Negative criteria are explicit: "No
 * KTP, KK, death-certificate content, bank detail, credential, or full
 * address in an audit payload."
 */
final class MetadataAllowlist
{
    /**
     * @var list<string>
     */
    public const array ALLOWED_KEYS = [
        'reference_number',
        'previous_state',
        'new_state',
        'note',

        // Added by S3-T2 (platform-identity-and-access MFA batch,
        // `app/Platform/IdentityAccess/Mfa/**`). Both are non-secret,
        // non-identifying labels/counts — never a code, secret, or
        // recovery code value, per requirements.md's Negative criteria
        // ("No credential, TOTP secret, or recovery code in logs, error
        // trackers, or audit payloads"), which this addition was
        // specifically re-checked against before being added.
        //
        // 'method': which verification mechanism a challenge/recovery
        // attempt used — one of `Mfa\MfaVerificationMethod::KNOWN_METHODS`
        // ('totp' | 'recovery_code'), never the submitted code itself.
        'method',
        // 'recovery_codes_remaining': an integer count only (e.g. how many
        // unused recovery codes remain after a redemption), never a code
        // value or hash.
        'recovery_codes_remaining',

        // Added by Lane L1 Task 6 (platform-document-vault read-side batch,
        // `app/Platform/DocumentVault/**`). Non-secret, non-identifying, and
        // specifically re-checked against this lane's "restricted data never
        // leaves the module" constraint and requirements.md's Negative
        // criteria ("No KTP, KK, death-certificate content, bank detail,
        // credential, or full address in an audit payload") before being
        // added.
        //
        // 'purpose': WHY a restricted document was reached for — always one
        // of `App\Platform\DocumentVault\DocumentAccessPurpose`'s closed
        // list of cases ('VIEW' | 'DOWNLOAD' | 'UPDATE' | 'DELETE' |
        // 'GRANT'), written as `$purpose->value`. Never free text, never a
        // filename, never file content or a MIME type, never a storage path,
        // never a signed-URL token, and never anything identifying an
        // individual. The same closed list backs the `purpose` column's
        // PostgreSQL CHECK on `document_access_events`/`signed_url_grants`.
        'purpose',
    ];

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function assertAllowed(array $metadata): void
    {
        $rejected = array_diff(array_keys($metadata), self::ALLOWED_KEYS);

        if ($rejected !== []) {
            throw AuditMetadataKeyNotAllowedException::forKeys(array_values($rejected));
        }
    }
}

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

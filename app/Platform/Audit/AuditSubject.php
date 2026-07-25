<?php

declare(strict_types=1);

namespace App\Platform\Audit;

/**
 * AC5: "reference the subject record rather than copy its sensitive
 * contents." A plain value object identifying WHICH record an audit
 * event is about — never carrying any of that record's own field
 * values. See `Audit::record()`'s class-level doc block for why
 * `metadata` is the only place any subject detail may travel, and
 * even that is allowlisted (`MetadataAllowlist`).
 */
final class AuditSubject
{
    /**
     * @param  string  $type  A stable logical type name for the subject
     *                        record — e.g. the Eloquent model's morph alias or
     *                        fully-qualified class name. Caller's choice; this
     *                        batch does not mandate one scheme, because no
     *                        consuming spec has named one yet (`tasks.md`'s
     *                        still-open "reconcile with the 13 consuming specs"
     *                        line — out of scope for this batch).
     * @param  int|string  $id  The subject record's own primary key. Never
     *                          the record's content.
     * @param  int|string|null  $version  AC9: "on which version." An
     *                                    optimistic-lock/version marker if the subject record has
     *                                    one; null when the subject has no versioning concept.
     */
    public function __construct(
        public readonly string $type,
        public readonly int|string $id,
        public readonly int|string|null $version = null,
    ) {}
}

<?php

declare(strict_types=1);

namespace App\Platform\Outbox;

/**
 * The closed set of classifications named by `outbox-event-contract.md`'s
 * envelope: `"classification": "PUBLIC|INTERNAL|CONFIDENTIAL|RESTRICTED"`.
 *
 * Backed by a real Postgres CHECK constraint on
 * `outbox_events.classification` (see
 * `2026_07_26_140000_create_outbox_events_table.php`) as well as this PHP
 * enum — same "reject before the query is even built, not only at the
 * database" pattern as `App\Platform\Audit\AuditOutcome`.
 *
 * ---------------------------------------------------------------------------
 * Why `Outbox::record()` trusts this value rather than re-deriving it
 * (AC7 judgement call)
 * ---------------------------------------------------------------------------
 * AC7 forbids restricted DATA in a payload ("references only"), not the
 * RESTRICTED classification label itself — `document.accessed.v1` in
 * `event-catalog.md` is explicitly a "Sensitive event," and the contract
 * lists `RESTRICTED` as one of four legitimate values an event can declare,
 * not a forbidden one. This module has no way to inspect an arbitrary
 * domain payload and correctly infer its true sensitivity — that judgement
 * belongs to the producer, who knows what `data` actually contains.
 * `Outbox::record()` therefore takes the caller's declared classification at
 * face value (validated only for being one of these four values, via this
 * enum's type itself) and layers `PayloadClassification`'s denylist on top
 * as a second, independent check against smuggled restricted CONTENT
 * regardless of the declared label — see that class's own doc block.
 */
enum OutboxClassification: string
{
    case Public = 'PUBLIC';
    case Internal = 'INTERNAL';
    case Confidential = 'CONFIDENTIAL';
    case Restricted = 'RESTRICTED';
}

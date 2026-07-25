<?php

declare(strict_types=1);

namespace App\Platform\Audit;

/**
 * `platform-audit` design.md's Data section: "source (panel/API/job)."
 * A closed set because AC2 requires every audit event to carry one of
 * these three origins.
 *
 * If a fourth kind of caller (e.g. a future console/CLI command) needs
 * to record audit events, extend this enum deliberately — do not widen
 * `Audit::record()`'s `$source` parameter to accept an arbitrary
 * string instead. No consuming spec has named a fourth origin yet, so
 * none is invented here.
 */
enum AuditSource: string
{
    case Panel = 'panel';
    case Api = 'api';
    case Job = 'job';
}

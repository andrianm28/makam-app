<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate;

/**
 * The closed set of lifecycle states for an `agreements` row (one row per
 * agreement VERSION). Values mirror the `agreements.status` PostgreSQL
 * CHECK constraint in `2026_08_17_100000_create_agreements_table.php`.
 *
 * The plan pins the transition shapes: `accept()` is draft -> accepted
 * (AC2 binding), `supersede()` is the incumbent -> superseded. `active`
 * is the state an agreement settles into once its subject's commercial
 * flow completes (Lane 2's pre-need settlement is the first consumer);
 * no Lane-1 action promotes accepted -> active, and `supersede()`
 * deliberately accepts BOTH an accepted and an active incumbent so AC5
 * history preservation stays reachable before that seam lands — the same
 * "current -> superseded" allowance `Quote::supersede()` documents for
 * an accepted incumbent.
 */
enum AgreementStatus: string
{
    case Draft = 'draft';

    case Accepted = 'accepted';

    case Active = 'active';

    case Superseded = 'superseded';
}

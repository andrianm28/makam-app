<?php

declare(strict_types=1);

namespace App\Domain\PreNeed;

/**
 * The audit action names the paid Pre-Need flow records — the module-level
 * counterpart of `App\Domain\OrderWorkflow\OrderWorkflowAuditActions`.
 *
 * Deliberately NOT on `SensitiveActions::ACTIONS`: the seven allowed
 * actions are the admin surface's routine, audited paid-flow steps (the
 * same rationale as the plot-reservation and marketplace constants), and
 * the DENIAL is recorded with `AuditOutcome::Denied`, which is what makes
 * `PRENEED_GATE_DENIED` a security-relevant record — its outcome column
 * carries the refusal, not the action name.
 */
final class PreNeedAuditActions
{
    public const string PRENEED_PROPOSED = 'PRENEED_PROPOSED';

    public const string PRENEED_RESERVED = 'PRENEED_RESERVED';

    public const string PRENEED_QUOTED = 'PRENEED_QUOTED';

    public const string PRENEED_AGREEMENT_ACCEPTED = 'PRENEED_AGREEMENT_ACCEPTED';

    public const string PRENEED_SCHEDULED = 'PRENEED_SCHEDULED';

    public const string PRENEED_SETTLED = 'PRENEED_SETTLED';

    public const string PRENEED_ACTIVATED = 'PRENEED_ACTIVATED';

    public const string PRENEED_GATE_DENIED = 'PRENEED_GATE_DENIED';

    public const string PRENEED_CONSULTATION_REQUESTED = 'PRENEED_CONSULTATION_REQUESTED';
}

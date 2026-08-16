<?php

declare(strict_types=1);

namespace App\Domain\PreNeed;

use App\Domain\PreNeed\Exceptions\PreNeedGateClosedException;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\FeatureGate\ModeResolver;
use App\Platform\FeatureGate\Modes\PreNeedMode;

/**
 * The small helper that makes the plan's Task 3 gate shape literally true:
 *
 *     every paid action BEGINS with the gate check — the check FIRST, the
 *     denial audited as `PRENEED_GATE_DENIED` (outcome `denied`), THEN the
 *     uniform `PreNeedGateClosedException` thrown.
 *
 * `assertOpen()` is that whole sequence in one call, so no action can
 * reorder it into "throw, then audit" or forget the audit. It is static
 * and container-resolving (like `App\Platform\Audit\Audit` itself) rather
 * than injected: the check is a pure server-side read of
 * `ModeResolver::preNeedMode()` — the ONE place this codebase pairs
 * `G-LEGAL-01` with a mode value — and the audit write is `Audit::record()`.
 * A test opens or closes the gate by swapping the `GateRegistrySource`
 * container binding (`ModeResolverTest`'s in-memory pattern), which this
 * helper honours because it resolves the resolver from the container.
 *
 * The denial audit's subject is the GATE itself (`pre_need_gate` /
 * `G-LEGAL-01`) rather than the case: the denied thing is the paid flow,
 * and the helper is deliberately case-agnostic so the seven actions cannot
 * forget to hand it a subject. The row is written OUTSIDE any transaction
 * (the gate check runs before the action's transaction opens) — a lone
 * `INSERT` is atomic, and the state change it pairs with is "nothing was
 * changed", so there is no mutation to pair with. A reason is always
 * recorded: a gate denial without a readable justification is
 * indistinguishable from one nobody can review.
 */
final class PreNeedGate
{
    public static function assertOpen(int|string $actorReference, string $actorRole, AuditSource $source): void
    {
        if (app(ModeResolver::class)->preNeedMode() === PreNeedMode::PaymentEnabled) {
            return;
        }

        Audit::record(
            action: PreNeedAuditActions::PRENEED_GATE_DENIED,
            subject: new AuditSubject('pre_need_gate', 'G-LEGAL-01'),
            outcome: AuditOutcome::Denied,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $source,
            reason: 'G-LEGAL-01 (paid pre-need) is closed; the paid pre-need flow is denied.',
        );

        throw PreNeedGateClosedException::becauseLegalGateClosed();
    }
}

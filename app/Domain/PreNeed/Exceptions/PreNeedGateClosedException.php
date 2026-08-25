<?php

declare(strict_types=1);

namespace App\Domain\PreNeed\Exceptions;

use DomainException;

/**
 * The UNIFORM exception every one of the seven paid Pre-Need actions
 * throws while `G-LEGAL-01` is closed — `PreNeedMode::InterestOnly`.
 * One class for all seven, never an action-specific variant: the plan's
 * Task 3 requires the fail-closed behaviour to be provable in one place,
 * and `PreNeedGateClosedTest` asserts exactly that (the same exception,
 * every action, no state change, one `PRENEED_GATE_DENIED` audit per
 * attempt).
 *
 * Thrown by `App\Domain\PreNeed\PreNeedGate::assertOpen()` AFTER the
 * denial has been audited — the audit row is what makes the throw
 * reviewable rather than silent.
 */
final class PreNeedGateClosedException extends DomainException
{
    public static function becauseLegalGateClosed(): self
    {
        return new self(
            'The paid Pre-Need flow is closed: G-LEGAL-01 is not open. '.
            'Register interest instead; no payment, reservation, or contract can be created.'
        );
    }
}

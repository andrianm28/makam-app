<?php

declare(strict_types=1);

namespace App\Platform\Payment;

/**
 * WHY a payment-guard condition denied. Wave 1b ruling 1b-L3-01 requires
 * "an explicit `UnavailableUpstream` denial reason distinct from a genuine
 * domain denial" — this enum is that distinction, and it is deliberately
 * only two cases wide so the difference cannot blur.
 *
 * The distinction matters operationally, not just semantically:
 *
 * - `DomainDenied` is a real answer from a real record. "The payment gate is
 *   closed" is true, checked, and actionable — a human opens the gate (with
 *   activation evidence, `GateActivationRecorder`) and the same condition
 *   then passes.
 * - `UnavailableUpstream` is the ABSENCE of an answer. The record the
 *   condition reads does not exist anywhere in this repository, so the
 *   condition cannot be evaluated at all. It denies — fail-closed — but
 *   nobody can make it pass by changing data, only by building the missing
 *   upstream. Reporting one as the other would either send an operator
 *   hunting for a record that does not exist, or let a reviewer read
 *   "denied" as "the system checked and said no" when nothing was checked.
 *
 * Neither case is ever a bypass. `UnavailableUpstream` is a DENIAL: ruling
 * 1b-L3-01's whole point is that a guard which cannot truthfully evaluate a
 * condition must refuse, never assume.
 */
enum GuardDenialReason: string
{
    /**
     * The condition was genuinely evaluated against a real source and the
     * answer was no.
     */
    case DomainDenied = 'domain_denied';

    /**
     * The condition's truth source does not exist in this repository, so it
     * cannot be evaluated. Always accompanied by the missing upstream's name
     * (`ConditionDenial` enforces this at construction).
     */
    case UnavailableUpstream = 'unavailable_upstream';
}

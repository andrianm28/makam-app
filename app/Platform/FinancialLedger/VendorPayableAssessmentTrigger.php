<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

/**
 * Who is causing a vendor-payable assessment: a person, or a scheduled run.
 *
 * This is a closed list rather than a boolean flag because the answer selects a
 * different authorization path AND a different audit shape, and because
 * `assess(..., unattended: true)` at a call site says nothing about what that
 * means. A reader of `UnattendedAssessment` can look it up; a reader of `true`
 * has to guess.
 *
 * It carries no default of its own — `Actions\VendorPayable::assess()` defaults
 * to `HumanDecision`, deliberately the path that fails closed. The automated
 * path is never reached by omission.
 */
enum VendorPayableAssessmentTrigger: string
{
    /**
     * A person is recognising this liability, from a panel or an API call made
     * on their behalf. Authorised as that person, against their own vendor
     * grant, and audited with their own reference and role.
     */
    case HumanDecision = 'human_decision';

    /**
     * A scheduled or queued re-evaluation of recorded facts, with no human
     * behind it — the production path for a payable that was opened `held`
     * while its dispute window ran and becomes eligible when the window
     * elapses.
     *
     * Refused if the actor context shows a human IS present: see
     * `FinanceVendorPayableAuthorizer::authorizeUnattended()`.
     */
    case UnattendedAssessment = 'unattended_assessment';
}

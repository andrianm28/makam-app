<?php

declare(strict_types=1);

namespace App\Platform\FinancialLedger;

use InvalidArgumentException;

/**
 * The closed list of `vendor_payables.state` values — AC8's three states, and
 * the reason this class exists rather than a pair of booleans.
 *
 * ---------------------------------------------------------------------------
 * Three states, never two, never one
 * ---------------------------------------------------------------------------
 * `AGENTS.md` §Marketplace: "Paid does not mean completed." The spec's own
 * design.md puts it as "Three states, never one — this is the ledger
 * expression of that rule." The three are genuinely independent facts:
 *
 *  - the CUSTOMER paying is a fact about `journal_batches`, and lives nowhere
 *    on this table at all;
 *  - `held` -> `payable` is a fact about fulfilment evidence and the dispute
 *    window (`VendorPayableEligibility`), which paid state is only one third
 *    of;
 *  - `payable` -> `paid` is a fact about a `payouts` row with a recorded proof
 *    and an authorised approver.
 *
 * Collapsing any two of them — a "settled" flag, a `paid` boolean that means
 * "the customer paid", an `is_payable` derived from order status — is the
 * defect this module is written to make unexpressible.
 *
 * ---------------------------------------------------------------------------
 * Why this is not a native PHP enum
 * ---------------------------------------------------------------------------
 * Consistency with the closed lists this codebase already ships for exactly
 * this job — `ScopeEntityType`, `ScopeGrantLevel`, `ReauthenticationOutcome` —
 * all of which are string-constant classes with `KNOWN_*` + `assertKnown()`
 * because the value stored in the column is a plain string validated on save,
 * and a PostgreSQL CHECK constraint is the real authority (see this table's
 * migration). `AuditOutcome`/`AuditSource` are enums because they are passed
 * as arguments, never stored raw from caller input. This one is stored, so it
 * follows the stored-value precedent.
 */
final class VendorPayableState
{
    /**
     * We do not yet owe the vendor. The default and the safe one: any
     * eligibility condition that is unmet, unknown, or unrecorded leaves the
     * row here. A paid order with no accepted fulfilment evidence is `held`.
     */
    public const string HELD = 'held';

    /**
     * We owe the vendor. All three eligibility conditions held at assessment
     * time. Says nothing whatsoever about money having left the business.
     */
    public const string PAYABLE = 'payable';

    /**
     * We paid the vendor, and a `payouts` row records the proof reference and
     * the approver who authorised it. Reached only through
     * `Actions\ManualPayout::pay()`.
     */
    public const string PAID = 'paid';

    /**
     * @var list<string>
     */
    public const array KNOWN_STATES = [
        self::HELD,
        self::PAYABLE,
        self::PAID,
    ];

    public static function isKnown(string $state): bool
    {
        return in_array($state, self::KNOWN_STATES, true);
    }

    /**
     * @throws InvalidArgumentException when `$state` is not one of
     *                                  `self::KNOWN_STATES`.
     */
    public static function assertKnown(string $state): void
    {
        if (! self::isKnown($state)) {
            throw new InvalidArgumentException(
                "Unknown vendor payable state [{$state}]. Known states: ".implode(', ', self::KNOWN_STATES).'.'
            );
        }
    }
}

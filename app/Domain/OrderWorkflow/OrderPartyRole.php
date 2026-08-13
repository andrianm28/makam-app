<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

/**
 * The closed list backing `order_parties.role`.
 *
 * One case, deliberately. `docs/product/booking-wizard-fields.md` §Step 6
 * describes exactly one party — the *pemesan* — and lists "alternate
 * contact" as an OPTIONAL field of that same person rather than as a second
 * party. No source document in this repository names a second order party,
 * so no second case is invented here; adding one is a schema-and-product
 * change made against a document first.
 *
 * The enum exists at one case rather than the column being a bare literal
 * so that the value has a single definition, a PostgreSQL CHECK derived
 * from it, and an obvious place for the second role to land when a document
 * calls for it.
 */
enum OrderPartyRole: string
{
    /**
     * The person placing the order. `booking-wizard-fields.md` §Step 6,
     * "Data Pemesan". The label is the stakeholder's own product term and
     * is not translated — `AGENTS.md` forbids renaming a product label.
     */
    case PEMESAN = 'PEMESAN';
}

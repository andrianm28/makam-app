<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate;

/**
 * The closed set of agreement kinds an `agreements` row may carry —
 * `agreements.type` is a plain string column pinned to this vocabulary
 * (and to the certificates-and-agreements ownership line: this module
 * owns the agreements lifecycle; `pre-need-contracting` consumes it).
 *
 * Lane 1 ships the pre-need agreement type only — the sole consumer
 * named in the plan. Extend deliberately when a second kind has a real
 * producer.
 */
enum AgreementType: string
{
    case PreNeedAgreement = 'PRE_NEED_AGREEMENT';
}

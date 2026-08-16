<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate;

/**
 * The closed set of certificate kinds a `certificates` row may carry.
 * Each kind's eligibility rule lives in `CertificateEligibilityPolicy`
 * (`CERTIFICATE_ELIGIBILITY_RULES`).
 *
 * `OrderSettlement` is the Lane-1 rule's subject: a certificate issued
 * for a settled order (`Order::status() === OrderStatus::DIBAYAR`). The
 * pre-need certificate kind and its rule land with Lane 2
 * (`docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md`
 * Task 3), where the settled pre-need case exists; until then an unknown
 * kind has no rule and is honestly refused by the policy.
 */
enum CertificateType: string
{
    case OrderSettlement = 'ORDER_SETTLEMENT';
}

<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate;

/**
 * The closed set of certificate kinds a `certificates` row may carry.
 * Each kind's eligibility rule lives in `CertificateEligibilityPolicy`
 * (`CERTIFICATE_ELIGIBILITY_RULES`).
 *
 * `OrderSettlement` is the Lane-1 rule's subject: a certificate issued
 * for a settled order (`Order::status() === OrderStatus::DIBAYAR`).
 *
 * `PreNeedSettlement` is the Lane-2 rule's subject: a certificate issued
 * for a settled PRE-NEED case, added at the Lane-2 merge
 * (`docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md`
 * Task 3's deferred pre-need-eligibility follow-up). Its rule lives in
 * `CertificateEligibilityPolicy` alongside the order rule.
 */
enum CertificateType: string
{
    case OrderSettlement = 'ORDER_SETTLEMENT';

    case PreNeedSettlement = 'PRENEED_SETTLEMENT';
}

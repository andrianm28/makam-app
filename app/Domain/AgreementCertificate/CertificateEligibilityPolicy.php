<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\PreNeed\Models\PreNeedCase;
use App\Domain\PreNeed\PreNeedCaseStatus;
use Illuminate\Database\Eloquent\Model;

/**
 * AC3's eligibility machinery: per certificate type, a rule evaluated
 * against DOMAIN state — never against payment-status columns. The plan:
 * "rules evaluate domain state (e.g. order DIBAYAR via `Order::status()`
 * — NOT payment-status columns; a settled pre-need case). Never touches
 * `payment_state`/`paid_via` directly."
 *
 * `CERTIFICATE_ELIGIBILITY_RULES` maps a `CertificateType` value to its
 * rule. The order rule reads `Order::status()` — the domain status that
 * `RecordOrderStatusChange` writes behind `Order`'s write guard — and
 * nothing else; `paid_via` / `paid_source_ref` are never consulted, so
 * eligibility cannot be gamed through (or broken by) a payment-column
 * shortcut.
 *
 * The pre-need certificate rule ("a settled pre-need case") was deferred
 * out of Lane 1 because the pre-need case model did not exist on that
 * branch; it lands HERE at the Lane-2 merge (Task 3's recorded follow-up):
 * the rule reads the CASE's own `PreNeedCaseStatus` — never a payment
 * column — so a `DIBAYAR` pre-need order is not evidence of a settled
 * case, and vice versa.
 *
 * An unknown certificate type or a subject the rule does not recognise
 * evaluates to false: no rule, no certificate.
 */
final class CertificateEligibilityPolicy
{
    /**
     * @var array<string, callable(Model): bool>
     */
    private const array CERTIFICATE_ELIGIBILITY_RULES = [
        CertificateType::OrderSettlement->value => [self::class, 'orderSettlementRule'],
        CertificateType::PreNeedSettlement->value => [self::class, 'preNeedSettlementRule'],
    ];

    public function eligibleFor(string $certificateType, Model $subject): bool
    {
        $rule = self::CERTIFICATE_ELIGIBILITY_RULES[$certificateType] ?? null;

        if (! is_callable($rule)) {
            return false;
        }

        return (bool) $rule($subject);
    }

    private static function orderSettlementRule(Model $subject): bool
    {
        return $subject instanceof Order
            && $subject->status() === OrderStatus::DIBAYAR;
    }

    /**
     * The pre-need certificate rule: the subject must be a Pre-Need CASE
     * in the `settled` state. The rule reads the case's own status value —
     * the coordination aggregate `SettlePreNeed` writes under its row lock,
     * reachable only through the paid flow — never an order or payment
     * column, so a paid order without a settled case (or a settled case
     * whose order was later touched) stays honestly ineligible.
     */
    private static function preNeedSettlementRule(Model $subject): bool
    {
        return $subject instanceof PreNeedCase
            && PreNeedCaseStatus::tryFrom((string) $subject->status) === PreNeedCaseStatus::SETTLED;
    }
}

<?php

declare(strict_types=1);

namespace App\Domain\AgreementCertificate;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
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
 * The pre-need certificate rule ("a settled pre-need case") is
 * deliberately absent in Lane 1: the pre-need case model does not exist
 * on this branch, and the plan's staged dispatch lands it with Lane 2
 * (Task 3) as a recorded follow-up. Until then a pre-need certificate
 * kind has no rule in this map and is honestly refused — see
 * `CertificateType`'s doc block.
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
}

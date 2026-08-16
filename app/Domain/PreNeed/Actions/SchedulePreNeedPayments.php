<?php

declare(strict_types=1);

namespace App\Domain\PreNeed\Actions;

use App\Domain\PreNeed\Exceptions\IllegalPreNeedCaseTransitionException;
use App\Domain\PreNeed\Models\PreNeedCase;
use App\Domain\PreNeed\Models\PreNeedPaymentScheduleItem;
use App\Domain\PreNeed\PreNeedAuditActions;
use App\Domain\PreNeed\PreNeedCaseStatus;
use App\Domain\PreNeed\PreNeedGate;
use App\Domain\PreNeed\PreNeedInstallmentState;
use App\Domain\Quotation\Models\Quote;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\Correlation\CorrelationContext;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * The paid Pre-Need flow, step 5: `agreed -> scheduled` (AC6: the payment
 * schedule is "explicit and idempotent").
 *
 * The installments arrive as
 * `list<array{amount_minor: int, due_date: string}>`; each becomes a
 * `pre_need_payment_schedules` row numbered 1..n in the order given, all
 * denominated in the currency of the case's BOUND QUOTE (the one price
 * source of truth this lane trusts — never a caller-supplied currency, so
 * a schedule cannot be denominated in anything the customer was not
 * quoted). A case with no bound quote is refused honestly
 * (`IllegalPreNeedCaseTransitionException::missingQuote`).
 *
 * Idempotency: a re-run (or a concurrent duplicate, under the case-row
 * lock) finds existing schedule rows and returns the INCUMBENT case —
 * nothing inserted, no status write, no second audit row (an idempotent
 * return is not a creation; the same discipline `ReservePlot` documents).
 * The `(pre_need_case_id, installment_number)` unique pair is the database
 * backstop for anything this check misses.
 *
 * Each installment's payment-link OPENING is deliberately a separate,
 * later step (Task 4's admin surface opens per-installment sessions via
 * `OpenPaymentSession` on the pre-need order and links `payment_session_id`
 * here) — this action never opens a payment session itself.
 */
final readonly class SchedulePreNeedPayments
{
    public function __invoke(
        PreNeedCase $case,
        array $installments,
        int|string $actorReference,
        string $actorRole,
        AuditSource $auditSource = AuditSource::Panel,
    ): PreNeedCase {
        PreNeedGate::assertOpen($actorReference, $actorRole, $auditSource);

        $this->validateInstallments($installments);

        // The idempotent fast path, outside the transaction: an existing
        // schedule is the incumbent — the authoritative re-check runs
        // under the case-row lock inside `apply()`.
        if (PreNeedPaymentScheduleItem::query()
            ->where('pre_need_case_id', $case->getKey())
            ->exists()) {
            return $case;
        }

        return Audit::wrap(
            mutation: fn (): PreNeedCase => $this->apply($case, $installments),
            action: PreNeedAuditActions::PRENEED_SCHEDULED,
            subject: new AuditSubject('pre_need_case', $case->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actorReference,
            actorRole: $actorRole,
            source: $auditSource,
            correlationId: app(CorrelationContext::class)->current()?->value,
        );
    }

    /**
     * @param  list<array{amount_minor: int, due_date: string}>  $installments
     */
    private function apply(PreNeedCase $case, array $installments): PreNeedCase
    {
        $current = PreNeedCase::query()->lockForUpdate()->findOrFail($case->getKey());

        $current->status()->assertAllows(PreNeedCaseStatus::SCHEDULED);

        if (PreNeedPaymentScheduleItem::query()
            ->where('pre_need_case_id', $current->getKey())
            ->exists()) {
            // A concurrent duplicate won the race under the lock — the
            // incumbent is the schedule that exists.
            return $current;
        }

        $quote = $current->quote_id !== null
            ? Quote::query()->find($current->quote_id)
            : null;

        if (! $quote instanceof Quote) {
            throw IllegalPreNeedCaseTransitionException::missingQuote((string) $current->getKey());
        }

        $rows = [];

        foreach (array_values($installments) as $index => $installment) {
            $rows[] = [
                // `insert()` bypasses the model's `HasUuids`, so the id is
                // generated here.
                'id' => (string) Str::uuid(),
                'pre_need_case_id' => $current->getKey(),
                'installment_number' => $index + 1,
                'amount_minor' => $installment['amount_minor'],
                'currency' => $quote->currency,
                'due_date' => $installment['due_date'],
                'state' => PreNeedInstallmentState::PENDING->value,
                'payment_session_id' => null,
            ];
        }

        PreNeedPaymentScheduleItem::query()->insert($rows);

        $current->forceFill([
            'status' => PreNeedCaseStatus::SCHEDULED->value,
        ])->save();

        return $current;
    }

    /**
     * Reject before any transaction opens — the same discipline
     * `RecordOrderStatusChange`'s metadata allowlist check and `IssueQuote`'s
     * "reject before the transaction" use.
     *
     * @param  array<int, mixed>  $installments
     */
    private function validateInstallments(array $installments): void
    {
        if ($installments === []) {
            throw new InvalidArgumentException('A payment schedule must carry at least one installment.');
        }

        foreach ($installments as $index => $installment) {
            if (! is_array($installment)
                || ! isset($installment['amount_minor'])
                || ! is_int($installment['amount_minor'])
                || $installment['amount_minor'] < 1) {
                throw new InvalidArgumentException(
                    "Installment [{$index}] must carry a positive integer [amount_minor]."
                );
            }

            if (! isset($installment['due_date'])
                || ! is_string($installment['due_date'])
                || ! $this->isStrictYmdDate($installment['due_date'])) {
                throw new InvalidArgumentException(
                    "Installment [{$index}] must carry a strict [Y-m-d] [due_date]."
                );
            }
        }
    }

    /**
     * A `due_date` is stored into a `date` column, so the caller's string
     * must BE a `Y-m-d` calendar date: exact shape first, then a real
     * calendar check (February 31st has the right shape but is not a date).
     * `date_parse()` never throws, so a malformed input cannot crash the
     * action on a hostile path.
     */
    private function isStrictYmdDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $parsed = date_parse($value);

        return is_array($parsed)
            && $parsed['error_count'] === 0
            && $parsed['warning_count'] === 0;
    }
}

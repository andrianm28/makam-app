<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PreNeedCases\Pages;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\PreNeed\Models\PreNeedCase;
use App\Domain\PreNeed\Models\PreNeedPaymentScheduleItem;
use App\Domain\PreNeed\PreNeedCaseStatus;
use App\Domain\PreNeed\PreNeedInstallmentState;
use App\Filament\Admin\Resources\PreNeedCases\Actions\PreNeedCaseActions;
use App\Filament\Admin\Resources\PreNeedCases\PreNeedCaseResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

/**
 * The view page — maps every allowed outgoing edge of the record's current
 * status to one header action from the shared factory
 * (`Actions\PreNeedCaseActions`), plus one per-installment payment-link
 * action per pending schedule item (the money seam to `OpenPaymentSession`
 * on the pre-need order).
 *
 * One deliberate exception to the "edge -> action" mapping: the settlement
 * action is mounted ONLY when the pre-need order is actually `DIBAYAR`
 * (`SettlePreNeed`'s verification gate — the domain re-verifies the same
 * under the order row lock anyway; the page just does not offer a button
 * for an unverifiable settlement).
 *
 * This page never switches on the status itself — it delegates the whole
 * per-edge mapping to the factory, the P1 case-detail shape.
 *
 * @return array<Action>
 */
final class ViewPreNeedCase extends ViewRecord
{
    protected static string $resource = PreNeedCaseResource::class;

    protected function getHeaderActions(): array
    {
        /** @var PreNeedCase $record */
        $record = $this->getRecord();

        $actions = [];

        foreach ($record->status()->allowedNext() as $next) {
            $action = match ($next) {
                PreNeedCaseStatus::PROPOSAL => PreNeedCaseActions::propose($record),
                PreNeedCaseStatus::RESERVED => PreNeedCaseActions::reserve($record),
                PreNeedCaseStatus::QUOTED => PreNeedCaseActions::quote($record),
                PreNeedCaseStatus::AGREED => PreNeedCaseActions::acceptAgreement($record),
                PreNeedCaseStatus::SCHEDULED => PreNeedCaseActions::schedule($record),
                PreNeedCaseStatus::SETTLED => $this->orderIsPaid($record)
                    ? PreNeedCaseActions::settle($record)
                    : null,
                PreNeedCaseStatus::ACTIVATED => PreNeedCaseActions::activate($record),
                PreNeedCaseStatus::INTEREST => null,
            };

            if ($action !== null) {
                $actions[] = $action;
            }
        }

        // Per-installment payment links — one header action per PENDING
        // installment (an installment with a session or marked paid offers
        // nothing more to open).
        foreach (PreNeedPaymentScheduleItem::query()
            ->where('pre_need_case_id', $record->getKey())
            ->where('state', PreNeedInstallmentState::PENDING->value)
            ->orderBy('installment_number')
            ->get() as $installment) {
            $actions[] = PreNeedCaseActions::paymentLink($record, $installment);
        }

        return $actions;
    }

    /**
     * The settlement action's render precondition (see the class doc
     * block) — the pre-need order must already be paid.
     */
    private function orderIsPaid(PreNeedCase $record): bool
    {
        $order = $record->order();

        return $order instanceof Order && $order->status() === OrderStatus::DIBAYAR;
    }
}

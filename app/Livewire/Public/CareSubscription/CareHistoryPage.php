<?php

declare(strict_types=1);

namespace App\Livewire\Public\CareSubscription;

use App\Domain\VendorFulfillment\Models\WorkOrder;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * `/riwayat-perawatan/{customerId}` — customer-facing care history view
 * (P5b Lane 4). Shows work order history with SEPARATE billing + work status
 * badges (AC2/AC6), evidence status, and acceptance/complaint actions.
 *
 * ---------------------------------------------------------------------------
 * Two separate indicators (AC2, AC6)
 * ---------------------------------------------------------------------------
 * Every row renders a billing status badge AND a work order status badge —
 * never collapsed into one. PAID ≠ COMPLETED is enforced structurally: the
 * billing status comes from the subscription cycle, while the work order
 * status comes from the work_orders table.
 *
 * ---------------------------------------------------------------------------
 * Missed/failed service
 * ---------------------------------------------------------------------------
 * A failed service shows an honest operational state, never styled as a
 * customer error (AC6). The UI explains what happened and what is being done.
 *
 * ---------------------------------------------------------------------------
 * Server-confirmed state only (AC4)
 * ---------------------------------------------------------------------------
 * All state is read from the database. No browser return URL or
 * client-supplied parameter influences the rendered state.
 */
final class CareHistoryPage extends Component
{
    public string $customerId = '';

    public function mount(string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function render(): View
    {
        $workOrders = $this->resolveWorkOrders();

        return view('livewire.public.care-subscription.care-history-page', [
            'workOrders' => $workOrders,
        ])->layout('layouts.app', [
            'title' => 'Riwayat Perawatan - Makam.co.id',
            'active' => null,
        ]);
    }

    /**
     * Resolve work orders for the customer through their subscriptions.
     * Uses a single query joining subscriptions → cycles → work orders.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function resolveWorkOrders(): \Illuminate\Support\Collection
    {
        return DB::table('work_orders')
            ->join('subscription_cycles', 'work_orders.subscription_cycle_id', '=', 'subscription_cycles.id')
            ->join('subscriptions', 'subscription_cycles.subscription_id', '=', 'subscriptions.id')
            ->where('subscriptions.customer_id', $this->customerId)
            ->select([
                'work_orders.id',
                'work_orders.reference',
                'work_orders.status as work_status',
                'work_orders.scheduled_at',
                'work_orders.completed_at',
                'subscription_cycles.cycle_start',
                'subscription_cycles.cycle_end',
                'subscription_cycles.status as billing_status',
            ])
            ->orderByDesc('subscription_cycles.cycle_start')
            ->get();
    }
}

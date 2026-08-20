<?php

declare(strict_types=1);

namespace App\Livewire\Public\CareSubscription;

use App\Domain\VendorFulfillment\Actions\AcceptService;
use App\Domain\VendorFulfillment\Actions\FileComplaint;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Domain\VendorFulfillment\WorkOrderStatus;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
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
 *
 * ---------------------------------------------------------------------------
 * Write surface authorization — a route middleware gap this component
 * cannot close on its own
 * ---------------------------------------------------------------------------
 * `routes/web.php`'s `riwayat-perawatan.index` route carries no `auth`
 * middleware today (it predates this write surface, when the route's own
 * comment could truthfully say "read-only GETs; no write surface exists").
 * `$customerId` is therefore an untrusted URL segment, not a verified
 * identity. Every write method here re-derives authorization itself
 * (`isAuthorizedCustomer()`: the request must be authenticated AND
 * `auth()->id()` must equal the route's `$customerId`) rather than trusting
 * the segment, and `ownedWorkOrder()` re-checks that the target work order
 * actually belongs to that customer's own subscription before any action
 * runs — an IDOR backstop independent of which work orders happen to be
 * rendered in `$workOrders`. This is defense in depth, not a substitute for
 * the real fix: the route should carry `->middleware('auth')` (routes/web.php
 * is a single-writer file this batch does not touch — flagged in the task
 * report instead).
 */
final class CareHistoryPage extends Component
{
    public string $customerId = '';

    /**
     * The work order id whose inline accept/complaint form is expanded.
     * Only one row's form is open at a time.
     */
    public ?string $expandedWorkOrderId = null;

    /**
     * 'accept' | 'complain' | '' (no form expanded).
     */
    public string $expandedMode = '';

    /**
     * Bound to the rating <select> as a plain string (matching this
     * component's sibling public pages, e.g. `VisitationPage::$visitorCount`)
     * rather than a typed `?int` property — '' means "not rated", never
     * relying on Livewire's request-payload coercion for a nullable numeric.
     */
    public string $rating = '';

    public string $acceptanceNotes = '';

    public string $complaintText = '';

    public ?string $actionMessage = null;

    public string $actionIntent = 'success';

    public function mount(string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function render(): View
    {
        $workOrders = $this->resolveWorkOrders();

        return view('livewire.public.care-subscription.care-history-page', [
            'workOrders' => $workOrders,
            'canAct' => $this->isAuthorizedCustomer(),
        ])->layout('layouts.app', [
            'title' => 'Riwayat Perawatan - Makam.co.id',
            'active' => null,
        ]);
    }

    public function showAcceptForm(string $workOrderId): void
    {
        $this->beginAction($workOrderId, 'accept');
    }

    public function showComplaintForm(string $workOrderId): void
    {
        $this->beginAction($workOrderId, 'complain');
    }

    public function cancelAction(): void
    {
        $this->resetActionState();
    }

    /**
     * Records customer acceptance of a completed service
     * (`App\Domain\VendorFulfillment\Actions\AcceptService`).
     */
    public function acceptService(): void
    {
        $this->actionMessage = null;

        if (! $this->isAuthorizedCustomer()) {
            $this->addError('action', 'Anda harus masuk sebagai pelanggan ini untuk menerima layanan.');

            return;
        }

        $workOrder = $this->ownedWorkOrder($this->expandedWorkOrderId ?? '');

        if ($workOrder === null) {
            $this->addError('action', 'Pekerjaan tidak ditemukan.');

            return;
        }

        if ($workOrder->status !== WorkOrderStatus::Completed->value) {
            $this->addError('action', 'Layanan belum selesai sehingga belum dapat diterima.');

            return;
        }

        if (DB::table('service_acceptances')->where('work_order_id', $workOrder->getKey())->exists()) {
            $this->addError('action', 'Layanan ini sudah pernah diterima sebelumnya.');

            return;
        }

        $rating = $this->rating !== '' ? (int) $this->rating : null;

        if ($rating !== null && ($rating < 1 || $rating > 5)) {
            $this->addError('rating', 'Nilai kepuasan harus antara 1 dan 5.');

            return;
        }

        try {
            app(AcceptService::class)(
                $workOrder,
                $this->customerId,
                $rating,
                filled($this->acceptanceNotes) ? trim($this->acceptanceNotes) : null,
            );
        } catch (InvalidArgumentException $exception) {
            $this->addError('rating', $exception->getMessage());

            return;
        }

        $this->actionMessage = 'Terima kasih, penerimaan layanan telah dicatat.';
        $this->actionIntent = 'success';
        $this->resetActionState();
    }

    /**
     * Files a customer complaint about a work order
     * (`App\Domain\VendorFulfillment\Actions\FileComplaint`). Make-good
     * creation is a staff-triggered follow-up
     * (`App\Domain\VendorFulfillment\Actions\CreateMakeGood` runs with
     * `actorRole: 'system'` / `AuditSource::Job` — not a customer action),
     * so this page only ever files the complaint; it never calls
     * `CreateMakeGood` itself.
     */
    public function fileComplaint(): void
    {
        $this->actionMessage = null;

        if (! $this->isAuthorizedCustomer()) {
            $this->addError('action', 'Anda harus masuk sebagai pelanggan ini untuk mengajukan komplain.');

            return;
        }

        $this->validate([
            'complaintText' => ['required', 'string', 'min:10', 'max:2000'],
        ]);

        $workOrder = $this->ownedWorkOrder($this->expandedWorkOrderId ?? '');

        if ($workOrder === null) {
            $this->addError('action', 'Pekerjaan tidak ditemukan.');

            return;
        }

        app(FileComplaint::class)($workOrder, $this->customerId, trim($this->complaintText));

        $this->actionMessage = 'Komplain Anda telah dikirim. Tim kami akan meninjau dan menghubungi Anda.';
        $this->actionIntent = 'success';
        $this->resetActionState();
    }

    private function beginAction(string $workOrderId, string $mode): void
    {
        $this->resetActionState();
        $this->expandedWorkOrderId = $workOrderId;
        $this->expandedMode = $mode;
    }

    private function resetActionState(): void
    {
        $this->expandedWorkOrderId = null;
        $this->expandedMode = '';
        $this->rating = '';
        $this->acceptanceNotes = '';
        $this->complaintText = '';
        $this->resetErrorBag();
    }

    /**
     * A second, deeper gap this comparison exposes but cannot itself close:
     * `subscriptions.customer_id` (and `work_evidence.uploaded_by`) are
     * schema-typed as Postgres `uuid` columns, while `ActorContext
     * ::identityReference` (what `auth()->id()` ultimately is) is the
     * `users.id` BIGINT — never a UUID shape. The two can only be compared
     * as opaque strings, and in a real Postgres environment they can never
     * legitimately match: nothing in this codebase mints a UUID "customer"
     * identity tied to an authenticated user. This is pre-existing —
     * `App\Filament\Admin\Resources\Subscriptions\Actions
     * \CreateSubscriptionAction` already writes
     * `(string) $actor->identityReference` (the ADMIN's own bigint id, not
     * even a customer selection) into this same uuid column today — and
     * only goes unnoticed because the test suite runs on SQLite, which does
     * not enforce uuid column typing. Flagged in the task report as a
     * cross-lane finding, not fixed here: this equality check is the best
     * available reading of the existing (buggy) convention, not a claim
     * that it works end-to-end against Postgres yet.
     */
    private function isAuthorizedCustomer(): bool
    {
        return auth()->check() && (string) auth()->id() === $this->customerId;
    }

    /**
     * Re-checks ownership through the same subscription → cycle join
     * `resolveWorkOrders()` uses, independent of `$workOrderId`'s origin —
     * see class doc block's IDOR note.
     */
    private function ownedWorkOrder(string $workOrderId): ?WorkOrder
    {
        if ($workOrderId === '') {
            return null;
        }

        $owned = DB::table('work_orders')
            ->join('subscription_cycles', 'work_orders.subscription_cycle_id', '=', 'subscription_cycles.id')
            ->join('subscriptions', 'subscription_cycles.subscription_id', '=', 'subscriptions.id')
            ->where('work_orders.id', $workOrderId)
            ->where('subscriptions.customer_id', $this->customerId)
            ->exists();

        if (! $owned) {
            return null;
        }

        return WorkOrder::query()->find($workOrderId);
    }

    /**
     * Resolve work orders for the customer through their subscriptions.
     * Uses a single query joining subscriptions → cycles → work orders, plus
     * a per-row acceptance/complaint state so the view can decide which
     * actions to offer without an N+1 query per row.
     *
     * @return Collection<int, object>
     */
    private function resolveWorkOrders(): Collection
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
                DB::raw('(select count(*) from service_acceptances where service_acceptances.work_order_id = work_orders.id) as acceptance_count'),
                DB::raw('(select status from service_complaints where service_complaints.work_order_id = work_orders.id order by filed_at desc limit 1) as complaint_status'),
            ])
            ->orderByDesc('subscription_cycles.cycle_start')
            ->get();
    }
}

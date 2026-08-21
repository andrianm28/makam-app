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
use Illuminate\Support\Str;
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
                (int) $this->customerId,
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

        app(FileComplaint::class)($workOrder, (int) $this->customerId, trim($this->complaintText));

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
     * RESOLVED 22 Aug 2026 (`2026_08_22_100000_fix_customer_and_uploader_
     * identity_columns`): `subscriptions.customer_id` (and
     * `work_evidence.uploaded_by`, `service_acceptances.customer_id`,
     * `service_complaints.customer_id`) were schema-typed as Postgres
     * `uuid` columns, while `ActorContext::identityReference` (what
     * `auth()->id()` ultimately is) is the `users.id` BIGINT — an
     * unreviewed schema mistake, not a deliberate identity-architecture
     * decision (no `Customer` model exists anywhere in this codebase, none
     * of the four columns carried a foreign key or a design-rationale
     * comment). Fixed by re-typing all four columns to a real
     * `foreignId(...)->constrained('users')`, matching this codebase's own
     * established convention elsewhere (`booking_drafts.user_id`,
     * `order_parties.user_id`). `$this->customerId` is still a plain route
     * string (Livewire binds it that way), so `isNumericCustomerId()`
     * below validates it looks like a real bigint id before any query —
     * the honest "no history" empty state for a malformed segment, same
     * shape as the old `Str::isUuid()` guard this replaces, just checking
     * against the real column type now.
     */
    private function isAuthorizedCustomer(): bool
    {
        return auth()->check() && (string) auth()->id() === $this->customerId;
    }

    /**
     * `$this->customerId` is a route segment (always a string) that must
     * look like a real `users.id` bigint before it's safe to use in a
     * `subscriptions.customer_id` query — `ctype_digit()` accepts only
     * non-negative integer strings, refusing anything else (including a
     * negative sign or a decimal point) before it ever reaches the
     * database.
     */
    private function isNumericCustomerId(): bool
    {
        return $this->customerId !== '' && ctype_digit($this->customerId);
    }

    /**
     * Re-checks ownership through the same subscription → cycle join
     * `resolveWorkOrders()` uses, independent of `$workOrderId`'s origin —
     * see class doc block's IDOR note. `work_orders.id` is a real uuid
     * primary key (unaffected by the customer_id/uploaded_by fix above),
     * so `$workOrderId` is still checked with `Str::isUuid()`.
     */
    private function ownedWorkOrder(string $workOrderId): ?WorkOrder
    {
        if ($workOrderId === '' || ! Str::isUuid($workOrderId) || ! $this->isNumericCustomerId()) {
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
     * A non-numeric `$customerId` (see class doc block / `isNumericCustomerId()`)
     * returns an empty collection rather than querying — the honest "no
     * history" state, not a crash.
     *
     * @return Collection<int, object>
     */
    private function resolveWorkOrders(): Collection
    {
        if (! $this->isNumericCustomerId()) {
            return new Collection;
        }

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

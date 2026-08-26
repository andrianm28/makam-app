<?php

declare(strict_types=1);

namespace App\Domain\OrderWorkflow;

use App\Domain\OrderWorkflow\Actions\ExpireOrder;
use App\Domain\OrderWorkflow\Exceptions\IllegalOrderTransitionException;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Finds orders whose current quote has silently expired and writes
 * `KEDALUWARSA` for read-model honesty — the second half of Task 4's
 * ratified design (Q4, Q5) in
 * `docs/superpowers/plans/2026-08-12-platform-order-orchestration.md:592`:
 * "expiry is evaluated lazily and authoritatively at guard time... with a
 * scheduled job writing `KEDALUWARSA` only for read-model honesty."
 *
 * The lazy-guard half was already real: `Quote::accept()` and
 * `Quote::isAcceptedAndUnexpired()` (consumed by the Task 6 payment guard)
 * both re-check `expires_at` live, every time, regardless of what
 * `orders.status` currently says. This class is purely cosmetic — it makes
 * the ADMIN- and CUSTOMER-visible status honest — and nothing here is
 * trusted as a source of truth by any guard. Skipping a run, or running it
 * late, changes what a screen displays; it changes no financial decision.
 *
 * `KEDALUWARSA` is reachable from exactly three statuses
 * (`OrderTransition::ALLOWED`): `PENAWARAN_TERKIRIM` (quote issued,
 * awaiting buyer approval), `DISETUJUI_PEMESAN` (quote accepted, payment
 * opening not yet granted), and `MENUNGGU_PEMBAYARAN` (payment window
 * open, not yet paid). In all three, `Quote::currentFor($order)` — the
 * newest non-superseded version — is the version that governs whether the
 * order can still move forward, so "the order's current quote has passed
 * its `expires_at`" is the one condition this class checks, regardless of
 * whether that version is still `ISSUED` or already `ACCEPTED`.
 */
final readonly class QuoteExpiryScheduler
{
    /** @var list<OrderStatus> */
    private const array EXPIRABLE_STATUSES = [
        OrderStatus::PENAWARAN_TERKIRIM,
        OrderStatus::DISETUJUI_PEMESAN,
        OrderStatus::MENUNGGU_PEMBAYARAN,
    ];

    public function __construct(private ExpireOrder $expireOrder) {}

    /**
     * @return Collection<int, Order> the orders actually transitioned to
     *                                `KEDALUWARSA` by this run.
     */
    public function expireDueOrders(?CarbonInterface $now = null): Collection
    {
        $now ??= now();

        $statusValues = array_map(
            static fn (OrderStatus $status): string => $status->value,
            self::EXPIRABLE_STATUSES,
        );

        // At most one non-superseded quote exists per order at any time
        // (`Actions\IssueQuote` supersedes the incumbent before inserting a
        // newer row) — so "not SUPERSEDED" here already identifies each
        // candidate order's CURRENT version, the same one
        // `Quote::currentFor()` would return.
        $expiredQuotes = Quote::query()
            ->where('status', '!=', QuoteStatus::SUPERSEDED->value)
            ->where('expires_at', '<', $now)
            ->whereHas('order', function ($query) use ($statusValues): void {
                $query->whereIn('status', $statusValues);
            })
            ->with('order')
            ->get();

        $expired = new Collection;

        foreach ($expiredQuotes as $quote) {
            $order = $quote->order;

            if (! $order instanceof Order) {
                continue;
            }

            try {
                ($this->expireOrder)($order, 'system', 'system');
                $expired->push($order);
            } catch (IllegalOrderTransitionException) {
                // The order moved on between the query above and this
                // write (paid, cancelled, rejected, or re-quoted
                // concurrently) — nothing to do, and not an error. Makes
                // repeated runs safe, which is the whole point of a
                // read-model-honesty job.
                continue;
            }
        }

        return $expired;
    }
}

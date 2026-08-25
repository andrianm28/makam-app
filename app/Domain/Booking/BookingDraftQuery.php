<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Platform\FinancialLedger\Money;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * The read entry point for a booking draft — the single place the wizard's
 * Livewire layer reads a `BookingDraft` from. Mirrors
 * `App\Domain\CemeteryDirectory\CemeteryPublicQuery`'s role and doc-block
 * reasoning.
 *
 * No `Projection` value object here, unlike `App\Domain\GraveRegistry\
 * GraveRecordProjection`: a draft's owner reading their own draft is not
 * the field-level access-policy problem that class exists to solve.
 * Returning the Eloquent model directly is a deliberate YAGNI choice.
 *
 * ACCESS: from Step 6 a draft carries customer and deceased PII, so holding
 * the id is NOT sufficient to read it — an earlier version of this doc block
 * claimed the opposite and that claim was wrong. `find()` resolves by id
 * ALONE and performs no access check; it exists for callers that have
 * already established the caller's right to the draft. Every request-facing
 * caller must use `findBound()`, which additionally requires the session to
 * hold the draft's issued secret. See `BookingDraftBinding`. The one
 * exception is `App\Livewire\Public\Booking\BookingWizard::
 * resolveDraftById()`'s ownership rescue: it calls `find()` directly, by
 * design, because it establishes the caller's right itself — proven
 * authenticated ownership (`$candidate->user_id === auth()->id()`) — before
 * using what `find()` returns.
 */
final class BookingDraftQuery
{
    /**
     * One draft by id, or `null` when the id does not exist or is not a
     * UUID at all — the same "tampered value is a clean miss, never a 500"
     * discipline as `CemeteryPublicQuery::findPublishedById()`.
     */
    public static function find(string $draftId): ?BookingDraft
    {
        $draftId = trim($draftId);

        if ($draftId === '' || ! Str::isUuid($draftId)) {
            return null;
        }

        /** @var BookingDraft|null $draft */
        $draft = BookingDraft::query()->whereKey($draftId)->first();

        return $draft;
    }

    /**
     * One draft by id, but ONLY when the current session holds the secret it
     * was issued with. This is the entry point every request-facing caller
     * must use — `find()` alone answers "does this id exist", which is not
     * the same question as "may this caller see it".
     *
     * A draft that exists but is not bound to this session is reported as
     * `null`, exactly like one that does not exist: a caller probing ids
     * learns nothing from the difference.
     */
    public static function findBound(string $draftId): ?BookingDraft
    {
        $draft = self::find($draftId);

        if ($draft === null || ! BookingDraftBinding::isBound($draft)) {
            return null;
        }

        return $draft;
    }

    /**
     * A user's own open drafts — the ones without a matching order yet — for
     * `/akun/draft`'s "Lanjutkan" list. A subquery against `Order`, not a new
     * `BookingDraft::order()` inverse relation: `Order` already depends on
     * `BookingDraft` one-directionally, and an inverse relation would create
     * a cross-domain model cycle between `Domain\Booking` and
     * `Domain\OrderWorkflow`.
     */
    public static function openForUser(int $userId): Collection
    {
        return BookingDraft::query()
            ->where('user_id', $userId)
            ->whereNotIn('id', Order::query()->whereNotNull('booking_draft_id')->select('booking_draft_id'))
            ->orderByDesc('updated_at')
            ->get();
    }

    /**
     * Step 5's price presentation — computed from `ServiceCatalog`'s
     * current price versions, NEVER a persisted Quote row (AC8 is out of
     * scope for this batch; see this plan's Global Constraints). When any
     * selected service has no current price version, that line's price and
     * the overall total are `null` and `all_prices_available` is `false` —
     * an honest "harga belum tersedia" state, never a fabricated total that
     * silently excludes a line.
     *
     * @return array{lines: list<array{code: string, label: string, quantity: int, unit_price: ?int, line_total: ?int}>, total: ?int, all_prices_available: bool}
     */
    public static function summary(BookingDraft $draft): array
    {
        $lines = [];
        $total = 0;
        $allPricesAvailable = true;

        foreach ($draft->selected_services as $selection) {
            // A read path never throws on malformed stored data. Every write
            // goes through `SaveBookingDraftStep::validateServices()`, which
            // guarantees the `list<array{code, quantity}>` shape — but this
            // is a JSON column, so a row hand-edited in the database or
            // written by a different schema version must degrade gracefully
            // rather than raise a raw PHP error on a public summary screen.
            //
            // A row with no usable code is skipped (there is nothing to name
            // or price). A row with a usable code but an unusable quantity is
            // KEPT and rendered UNPRICED — quantity 1 is a guess, and pricing
            // a guess would fabricate a total, which this method's own
            // contract forbids.
            if (! is_array($selection) || ! isset($selection['code']) || ! is_scalar($selection['code'])) {
                continue;
            }

            $code = (string) $selection['code'];
            $rawQuantity = $selection['quantity'] ?? null;
            $quantityIsUsable = is_int($rawQuantity) && $rawQuantity >= 1;
            $quantity = $quantityIsUsable ? $rawQuantity : 1;

            $definition = ServiceDefinition::findByCode($code);
            $priceVersion = $quantityIsUsable ? $definition?->currentPriceVersion() : null;

            $unitPrice = $priceVersion !== null
                ? Money::fromDecimal((string) $priceVersion->amount)
                : null;
            $lineTotal = $unitPrice !== null
                ? self::multiplyMinorUnits($unitPrice, $quantity)
                : null;

            if ($unitPrice === null || $lineTotal === null) {
                $allPricesAvailable = false;
            } else {
                $nextTotal = self::addMinorUnits($total, $lineTotal);

                if ($nextTotal === null) {
                    $allPricesAvailable = false;
                } else {
                    $total = $nextTotal;
                }
            }

            $lines[] = [
                'code' => $code,
                'label' => $definition?->name ?? $code,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ];
        }

        return [
            'lines' => $lines,
            'total' => $allPricesAvailable && $lines !== [] ? $total : null,
            'all_prices_available' => $allPricesAvailable,
        ];
    }

    private static function multiplyMinorUnits(int $unitPrice, int $quantity): ?int
    {
        if (($unitPrice > 0 && $unitPrice > intdiv(PHP_INT_MAX, $quantity))
            || ($unitPrice < 0 && $unitPrice < intdiv(PHP_INT_MIN, $quantity))) {
            return null;
        }

        return $unitPrice * $quantity;
    }

    private static function addMinorUnits(int $left, int $right): ?int
    {
        if (($right > 0 && $left > PHP_INT_MAX - $right)
            || ($right < 0 && $left < PHP_INT_MIN - $right)) {
            return null;
        }

        return $left + $right;
    }
}

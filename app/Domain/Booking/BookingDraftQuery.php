<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Booking\Models\BookingDraft;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use Illuminate\Support\Str;

/**
 * The read entry point for a booking draft — the single place the wizard's
 * Livewire layer reads a `BookingDraft` from. Mirrors
 * `App\Domain\CemeteryDirectory\CemeteryPublicQuery`'s role and doc-block
 * reasoning.
 *
 * No `Projection` value object here, unlike `App\Domain\GraveRegistry\
 * GraveRecordProjection`: a draft's own owner reading their own draft is
 * not the access-policy problem that class exists to solve (nothing on a
 * `BookingDraft` is restricted from the person who holds its id). Returning
 * the Eloquent model directly is a deliberate YAGNI choice for this batch.
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
     * Step 5's price presentation — computed from `ServiceCatalog`'s
     * current price versions, NEVER a persisted Quote row (AC8 is out of
     * scope for this batch; see this plan's Global Constraints). When any
     * selected service has no current price version, that line's price and
     * the overall total are `null` and `all_prices_available` is `false` —
     * an honest "harga belum tersedia" state, never a fabricated total that
     * silently excludes a line.
     *
     * @return array{lines: list<array{code: string, label: string, quantity: int, unit_price: ?float, line_total: ?float}>, total: ?float, all_prices_available: bool}
     */
    public static function summary(BookingDraft $draft): array
    {
        $lines = [];
        $total = 0.0;
        $allPricesAvailable = true;

        foreach ($draft->selected_services as $selection) {
            $code = (string) $selection['code'];
            $quantity = (int) $selection['quantity'];

            $definition = ServiceDefinition::findByCode($code);
            $priceVersion = $definition?->currentPriceVersion();

            $unitPrice = $priceVersion !== null ? (float) $priceVersion->amount : null;
            $lineTotal = $unitPrice !== null ? $unitPrice * $quantity : null;

            if ($unitPrice === null) {
                $allPricesAvailable = false;
            } else {
                $total += $lineTotal;
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
}

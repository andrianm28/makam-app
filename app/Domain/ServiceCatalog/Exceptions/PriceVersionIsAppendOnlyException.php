<?php

declare(strict_types=1);

namespace App\Domain\ServiceCatalog\Exceptions;

use RuntimeException;

/**
 * Thrown by `Models\PriceVersion::booted()` — the application-level half of
 * append-only enforcement for `price_versions`. See that model's class-level
 * doc block for exactly what this does and does not guarantee (there is no
 * database-level enforcement; a raw `DB::table()` write still bypasses it).
 *
 * Both the model's own doc block and
 * `2026_07_26_180400_create_price_versions_table.php:55-56` ("never delete or
 * renumber a version after the fact") claimed this contract from the day the
 * table shipped, while the model defined no `booted()` of any kind — so
 * `$old->forceFill(['amount' => '1'])->save()` and `$old->delete()` both
 * simply worked. Added 09 Aug 2026 by the ServiceCatalog Superpowers
 * retrofit, mirroring the shape `App\Domain\Faq\Models\FaqArticleVersion`
 * established for the identical defect: a plain `RuntimeException` with a
 * named static factory per call site.
 *
 * `AGENTS.md` §Domain and financial invariants is why this matters more here
 * than for a FAQ body: an issued quote references a price version, and
 * `design.md` §Consumption boundary is explicit that the quote holds the OLD
 * snapshot. A historical amount that can be rewritten in place is not a
 * snapshot at all.
 */
final class PriceVersionIsAppendOnlyException extends RuntimeException
{
    /**
     * @param  list<string>  $dirtyColumns  the columns the rejected write
     *                                      would have changed.
     */
    public static function forUpdate(int|string|null $priceVersionId, array $dirtyColumns): self
    {
        $columns = $dirtyColumns === [] ? '(none)' : implode(', ', $dirtyColumns);

        return new self(
            "price_versions row [{$priceVersionId}] is append-only and cannot be updated ".
            "(attempted to change: {$columns}). The ONLY permitted update is the single ".
            'null -> non-null `superseded_at` stamp that '.
            'Actions\RecordServiceDefinitionPriceVersion applies when the NEXT version for the '.
            'same priceable is recorded. To change a price, record a new version.'
        );
    }

    public static function forDelete(int|string|null $priceVersionId): self
    {
        return new self(
            "price_versions row [{$priceVersionId}] is append-only and cannot be deleted; ".
            'a superseded version is the historical record an already-issued quote references. '.
            'To stop a price being current, record a new version — which supersedes it.'
        );
    }
}

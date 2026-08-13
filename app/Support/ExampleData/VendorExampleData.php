<?php

declare(strict_types=1);

namespace App\Support\ExampleData;

use App\Domain\Marketplace\ProductCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The ONE place the nine EXAMPLE marketplace vendors (vendor name,
 * base price, photo path) are generated for the `products` table.
 * The dummy-data migration and any future seeders materialize from
 * here, so a catalog change is a single edit — never interlocking
 * literal copies of vendor rows.
 *
 * ---------------------------------------------------------------------------
 * DETERMINISM AND HONESTY — read this before changing any rule below
 * ---------------------------------------------------------------------------
 * - Vendor names are GENERATED positionally from `ProductCode::KNOWN_CODES`
 *   ("UD"/"CV" prefix alternating with two words from a fixed pool) and
 *   therefore read as clearly-fictional fixtures, never as verified real
 *   business data — same discipline as `CemeteryExampleData`.
 * - `basePrice()` folds a stable per-code hash (`crc32`) into 500k
 *   increments (always >= 500_000). This is deterministic within a PHP
 *   version and across runs on the same version, but `crc32` output is
 *   NOT guaranteed stable across PHP versions: if a future PHP upgrade
 *   changes it, the seeded prices change. The seeded rows are EXAMPLE
 *   data — example data may change — but a version bump that silently
 *   alters prices is still a conscious cut, so re-seed and diff on any
 *   PHP upgrade (same class of tradeoff as the existing "example data
 *   may change" caveat in `CemeteryExampleData`).
 */
final class VendorExampleData
{
    private const array PREFIXES = ['UD', 'CV'];

    private const array WORDS = ['Berkah', 'Damai', 'Sentosa', 'Abadi', 'Amanah', 'Prima', 'Nusantara', 'Sejahtera'];

    /**
     * @return list<array{0: string, 1: string, 2: int, 3: string}>
     *                                                              Rows shaped [product_code, vendor_name, base_price_idr, photo_path],
     *                                                              one per `ProductCode::KNOWN_CODES` entry, in catalog order.
     */
    public static function vendors(): array
    {
        $rows = [];
        foreach (array_values(ProductCode::KNOWN_CODES) as $i => $code) {
            $rows[] = [
                $code,
                sprintf('%s %s %s', self::PREFIXES[$i % 2], self::WORDS[$i % 8], self::WORDS[($i + 3) % 8]),
                self::basePrice($code),
                self::photoPath($code),
            ];
        }

        return $rows;
    }

    public static function basePrice(string $code): int
    {
        // Deterministic per-code: a stable hash folded into 500k increments.
        $h = crc32($code);

        return 500_000 + ($h % 16) * 500_000;
    }

    private static function photoPath(string $code): string
    {
        return 'images/marketplace/'.Str::slug($code).'.svg';
    }

    public static function seed(): void
    {
        foreach (self::vendors() as [$code, $vendorName, $basePrice, $photoPath]) {
            DB::table('products')->where('code', $code)->update([
                'vendor_name' => $vendorName,
                'base_price_idr' => $basePrice,
                'photo_path' => $photoPath,
            ]);
        }
    }
}

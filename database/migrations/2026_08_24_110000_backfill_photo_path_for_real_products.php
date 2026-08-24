<?php

declare(strict_types=1);

use App\Domain\Marketplace\ProductCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Backfills `products.photo_path` for all 9 real, active catalogue products
 * (`App\Domain\Marketplace\ProductCode::KNOWN_CODES` — every code this
 * catalogue actually has, not a subset), using the SVG illustrations already
 * shipped at `public/images/marketplace/*.svg` — a clean, pre-existing 1:1
 * name match per code (`gravestone-granite.svg` for
 * `GRAVESTONE_GRANITE`/"Granit", `flower-board.svg` for
 * `FLOWER_BOARD`/"Karangan Bunga Papan", etc.), not invented for this
 * migration. Unlike the cemetery illustrations, these are genuinely
 * category-specific (a real granite headstone illustration for the granite
 * product), so there is no reuse/round-robin here — each code gets its own
 * matching file.
 *
 * Keyed by `code`, not `id` — matches this codebase's own established
 * convention for touching the full real product catalogue (see
 * `App\Console\Commands\PurgeExampleDataCommand`'s identical
 * `ProductCode::KNOWN_CODES`-keyed approach) rather than a fragile,
 * environment-specific numeric ID list.
 */
return new class extends Migration
{
    private const array PHOTO_BY_CODE = [
        ProductCode::FLOWER_BOARD => 'images/marketplace/flower-board.svg',
        ProductCode::FLOWER_PETAL_PACKAGE => 'images/marketplace/flower-petal-package.svg',
        ProductCode::GRAVESTONE_GRANITE => 'images/marketplace/gravestone-granite.svg',
        ProductCode::GRAVESTONE_MARBLE => 'images/marketplace/gravestone-marble.svg',
        ProductCode::GRAVESTONE_CALLIGRAPHY => 'images/marketplace/gravestone-calligraphy.svg',
        ProductCode::GRAVE_CARE_MONTHLY => 'images/marketplace/grave-care-monthly.svg',
        ProductCode::GRAVE_CARE_QUARTERLY => 'images/marketplace/grave-care-quarterly.svg',
        ProductCode::GRAVE_CARE_SEMIANNUAL => 'images/marketplace/grave-care-semiannual.svg',
        ProductCode::GRAVE_CARE_ANNUAL => 'images/marketplace/grave-care-annual.svg',
    ];

    public function up(): void
    {
        foreach (self::PHOTO_BY_CODE as $code => $photoPath) {
            DB::table('products')
                ->where('code', $code)
                ->update([
                    'photo_path' => $photoPath,
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        DB::table('products')
            ->whereIn('code', array_keys(self::PHOTO_BY_CODE))
            ->update([
                'photo_path' => null,
                'updated_at' => now(),
            ]);
    }
};

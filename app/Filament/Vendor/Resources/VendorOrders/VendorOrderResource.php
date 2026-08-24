<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorOrders;

use App\Domain\Marketplace\Models\VendorOrder;
use App\Filament\Vendor\Concerns\ScopesToCurrentVendor;
use App\Filament\Vendor\Resources\VendorOrders\Pages\EditVendorOrder;
use App\Filament\Vendor\Resources\VendorOrders\Pages\ListVendorOrders;
use App\Filament\Vendor\Resources\VendorOrders\Schemas\VendorOrderForm;
use App\Filament\Vendor\Resources\VendorOrders\Tables\VendorOrdersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * `/vendor/pesanan` — the vendor's incoming order work queue.
 *
 * Directory layout, the `Schemas/`+`Tables/`+`Pages/` split, and the
 * `getPages()` route shape follow `VendorListingResource`, which documents them
 * as verified against the installed `filament/filament` v5.7.3 rather than
 * assumed from an older major version.
 *
 * Record visibility comes from `ScopesToCurrentVendor`; see that trait and
 * `Domain\Marketplace\Access\CurrentVendorScope` for the mechanism and for why
 * it lives at the panel boundary instead of on the model.
 *
 * ---------------------------------------------------------------------------
 * Deliberately no create page
 * ---------------------------------------------------------------------------
 * `getPages()` registers `index` and `edit` only. A vendor never creates its
 * own orders: a `vendor_orders` row is produced by customer checkout, and its
 * `vendor_id`, `listing_id`, and customer identity are all decided there. A
 * create page would hand the vendor a form that invents an order — and, worse,
 * a write path that chooses its own `vendor_id` — so the route simply does not
 * exist. `Pages\ListVendorOrders` correspondingly registers no `CreateAction`.
 * Compare `VendorListingResource`, which does own a create page because a
 * listing genuinely originates with the vendor.
 *
 * Read-only history over these same rows lives on
 * `App\Filament\Vendor\Pages\TransactionHistory`; see that page's doc block for
 * why the two exist side by side.
 */
final class VendorOrderResource extends Resource
{
    use ScopesToCurrentVendor;

    protected static ?string $model = VendorOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingCart;

    /**
     * Without this the slug would be derived from the model name
     * (`vendor-orders`), which is not the documented route.
     */
    protected static ?string $slug = 'pesanan';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return VendorOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VendorOrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendorOrders::route('/'),
            'edit' => EditVendorOrder::route('/{record}/edit'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'pesanan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Pesanan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Pesanan';
    }
}

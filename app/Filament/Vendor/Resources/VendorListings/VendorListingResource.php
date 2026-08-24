<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorListings;

use App\Domain\Marketplace\Models\VendorListing;
use App\Filament\Vendor\Concerns\ScopesToCurrentVendor;
use App\Filament\Vendor\Resources\VendorListings\Pages\CreateVendorListing;
use App\Filament\Vendor\Resources\VendorListings\Pages\EditVendorListing;
use App\Filament\Vendor\Resources\VendorListings\Pages\ListVendorListings;
use App\Filament\Vendor\Resources\VendorListings\Schemas\VendorListingForm;
use App\Filament\Vendor\Resources\VendorListings\Tables\VendorListingsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * `/vendor/products` — the vendor's own catalogue listings.
 *
 * Directory layout, the `Schemas/`+`Tables/`+`Pages/` split, and the
 * `getPages()` route shape follow `App\Filament\Admin\Resources\FaqArticles\
 * FaqArticleResource`, which documents them as verified against the installed
 * `filament/filament` v5.7.3 rather than assumed from an older major version.
 *
 * Record visibility comes from `ScopesToCurrentVendor`; see that trait and
 * `Domain\Marketplace\Access\CurrentVendorScope` for the mechanism and for why
 * it lives at the panel boundary instead of on the model.
 */
final class VendorListingResource extends Resource
{
    use ScopesToCurrentVendor;

    protected static ?string $model = VendorListing::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShoppingBag;

    /**
     * Without this the slug would be derived from the model name
     * (`vendor-listings`), which is not the documented route.
     */
    protected static ?string $slug = 'produk';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return VendorListingForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VendorListingsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendorListings::route('/'),
            'create' => CreateVendorListing::route('/create'),
            'edit' => EditVendorListing::route('/{record}/edit'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'produk';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Produk';
    }

    public static function getNavigationLabel(): string
    {
        return 'Produk';
    }
}

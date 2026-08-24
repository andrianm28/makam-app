<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\ServiceAreas;

use App\Domain\Marketplace\Models\ServiceArea;
use App\Filament\Vendor\Concerns\ScopesToCurrentVendor;
use App\Filament\Vendor\Resources\ServiceAreas\Pages\CreateServiceArea;
use App\Filament\Vendor\Resources\ServiceAreas\Pages\EditServiceArea;
use App\Filament\Vendor\Resources\ServiceAreas\Pages\ListServiceAreas;
use App\Filament\Vendor\Resources\ServiceAreas\Schemas\ServiceAreaForm;
use App\Filament\Vendor\Resources\ServiceAreas\Tables\ServiceAreasTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * `/vendor/area-layanan` — the areas a vendor delivers to, and the delivery
 * fee for each.
 *
 * ---------------------------------------------------------------------------
 * This route is not one of the six in `information-architecture.md` §5
 * ---------------------------------------------------------------------------
 * That section lists `/vendor` as `/products`, `/orders`, `/calendar`,
 * `/transactions`, `/payouts`, `/profile`. `service-areas` is none of them: it
 * is an additional vendor-owned surface for the `service_areas` table, added
 * because the data exists and has no other vendor-facing editor. No claim is
 * made here that the IA document sanctions it — reconciling the two (either by
 * adding this route to §5 or by folding service areas into an existing screen)
 * is an open documentation question, not something this class settles.
 *
 * Directory layout, the `Schemas/`+`Tables/`+`Pages/` split, and the
 * `getPages()` route shape follow `VendorListingResource`, verified against the
 * installed `filament/filament` v5.7.3 rather than assumed from an older major
 * version.
 *
 * Record visibility comes from `ScopesToCurrentVendor`; see that trait and
 * `Domain\Marketplace\Access\CurrentVendorScope` for the mechanism and for why
 * it lives at the panel boundary instead of on the model.
 */
final class ServiceAreaResource extends Resource
{
    use ScopesToCurrentVendor;

    protected static ?string $model = ServiceArea::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    /**
     * Without this the slug would be derived from the model name
     * (`service-areas`), which is not the documented route.
     */
    protected static ?string $slug = 'area-layanan';

    protected static ?int $navigationSort = 70;

    public static function form(Schema $schema): Schema
    {
        return ServiceAreaForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServiceAreasTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceAreas::route('/'),
            'create' => CreateServiceArea::route('/create'),
            'edit' => EditServiceArea::route('/{record}/edit'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'area layanan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Area Layanan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Area Layanan';
    }
}

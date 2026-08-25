<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorAvailabilities;

use App\Domain\Marketplace\Models\VendorAvailability;
use App\Filament\Vendor\Concerns\ScopesToCurrentVendor;
use App\Filament\Vendor\Resources\VendorAvailabilities\Pages\CreateVendorAvailability;
use App\Filament\Vendor\Resources\VendorAvailabilities\Pages\EditVendorAvailability;
use App\Filament\Vendor\Resources\VendorAvailabilities\Pages\ListVendorAvailabilities;
use App\Filament\Vendor\Resources\VendorAvailabilities\Schemas\VendorAvailabilityForm;
use App\Filament\Vendor\Resources\VendorAvailabilities\Tables\VendorAvailabilitiesTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * `/vendor/kalender` — the vendor's own per-day availability schedule.
 *
 * Directory layout, the `Schemas/`+`Tables/`+`Pages/` split, and the
 * `getPages()` route shape follow the sibling `VendorListings\
 * VendorListingResource`, which in turn follows `App\Filament\Admin\Resources\
 * FaqArticles\FaqArticleResource` — verified against the installed
 * `filament/filament` v5.7.3 rather than assumed from an older major version.
 *
 * Record visibility comes from `ScopesToCurrentVendor`; see that trait and
 * `Domain\Marketplace\Access\CurrentVendorScope` for the mechanism and for why
 * it lives at the panel boundary instead of on the model.
 */
final class VendorAvailabilityResource extends Resource
{
    use ScopesToCurrentVendor;

    protected static ?string $model = VendorAvailability::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    /**
     * Without this the slug would be derived from the model name
     * (`vendor-availabilities`), which is not the documented route.
     */
    protected static ?string $slug = 'kalender';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return VendorAvailabilityForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return VendorAvailabilitiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVendorAvailabilities::route('/'),
            'create' => CreateVendorAvailability::route('/create'),
            'edit' => EditVendorAvailability::route('/{record}/edit'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'ketersediaan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Kalender';
    }

    public static function getNavigationLabel(): string
    {
        return 'Kalender';
    }
}

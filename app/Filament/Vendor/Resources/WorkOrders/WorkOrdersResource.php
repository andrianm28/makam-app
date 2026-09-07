<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\WorkOrders;

use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Filament\Vendor\Concerns\ScopesToCurrentVendor;
use App\Filament\Vendor\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Filament\Vendor\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Vendor\Resources\WorkOrders\Schemas\WorkOrderInfolist;
use App\Filament\Vendor\Resources\WorkOrders\Tables\WorkOrdersTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `/vendor/order-kerja` — the vendor's care work order queue.
 * Scoped to the current vendor via `ScopesToCurrentVendor`.
 */
final class WorkOrdersResource extends Resource
{
    use ScopesToCurrentVendor;

    protected static ?string $model = WorkOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $slug = 'order-kerja';

    protected static ?int $navigationSort = 40;

    protected static ?string $navigationLabel = 'Pekerjaan';

    public static function infolist(Schema $schema): Schema
    {
        return WorkOrderInfolist::configure($schema);
    }

    public static function getEloquentQuery(): Builder
    {
        // PERF-11: `WorkOrdersTable`'s `carePlan.name` column ran a per-row
        // query with no eager load. `ScopesToCurrentVendor::
        // getEloquentQuery()` still runs first (`applyVendorScope()` over
        // `parent::getEloquentQuery()`) so overriding here does not touch
        // the vendor-scoping guarantee that trait's own doc block describes.
        return self::applyVendorScope(parent::getEloquentQuery())->with(['carePlan']);
    }

    public static function table(Table $table): Table
    {
        return WorkOrdersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkOrders::route('/'),
            'view' => ViewWorkOrder::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'pesanan kerja';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Pekerjaan';
    }
}

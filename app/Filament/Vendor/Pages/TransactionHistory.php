<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Domain\Marketplace\Access\CurrentVendorScope;
use App\Domain\Marketplace\Models\VendorOrder;
use App\Filament\Vendor\Concerns\VendorScopedTablePage;
use App\Filament\Vendor\Support\VendorOrderStatusPresenter;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `/vendor/transactions` — the vendor's own order history, newest first.
 *
 * This is the same underlying rows as `/vendor/orders`, read with a different
 * intent: orders is a work queue the vendor acts on, this is a historical
 * record they read. Hence a read-only page with no row actions and no status
 * form, rather than a second set of pages on `VendorOrderResource`.
 */
final class TransactionHistory extends Page implements HasTable
{
    use VendorScopedTablePage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Riwayat Transaksi';

    protected static ?string $title = 'Riwayat Transaksi';

    protected static ?string $slug = 'transactions';

    protected static ?int $navigationSort = 50;

    /**
     * @return Builder<VendorOrder>
     */
    public static function vendorScopedQuery(): Builder
    {
        return VendorOrder::query()
            ->with('listing.product')
            ->whereIn('vendor_id', app(CurrentVendorScope::class)->grantedVendorIds());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::vendorScopedQuery())
            ->columns([
                TextColumn::make('uuid')
                    ->label('ID Pesanan')
                    ->limit(8)
                    ->copyable()
                    ->searchable(),

                TextColumn::make('created_at')
                    ->label('Tanggal')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('customer_name')
                    ->label('Pelanggan')
                    ->searchable(),

                TextColumn::make('listing.product.name')
                    ->label('Produk'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => VendorOrderStatusPresenter::color($state))
                    ->formatStateUsing(fn (string $state): string => VendorOrderStatusPresenter::label($state)),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(VendorOrderStatusPresenter::options()),
            ])
            ->emptyStateHeading('Belum ada transaksi')
            ->emptyStateDescription('Riwayat pesanan yang masuk ke toko Anda akan tercatat di sini.')
            ->emptyStateIcon(Heroicon::OutlinedClock)
            ->defaultSort('created_at', 'desc');
    }
}

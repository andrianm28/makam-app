<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Domain\Marketplace\Access\CurrentVendorScope;
use App\Filament\Vendor\Concerns\VendorScopedTablePage;
use App\Platform\FinancialLedger\Models\VendorPayable;
use App\Platform\FinancialLedger\VendorPayableState;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `/vendor/pencairan` — what the platform owes this vendor, and whether it has
 * been paid.
 *
 * ---------------------------------------------------------------------------
 * The scoping here is the single most consequential line in this panel
 * ---------------------------------------------------------------------------
 * `vendor_payables` rows are money owed, per vendor. An unscoped query on this
 * page hands every vendor a complete view of every other vendor's revenue,
 * payout timing, and outstanding balance — commercially sensitive data about
 * third parties, not merely a records leak. The previous version of this page
 * had no scoping at all.
 *
 * `VendorPayable` deliberately does NOT carry
 * `ScopeAssignmentGlobalScope`, and must not: it is read by the finance and
 * admin surfaces (`FinanceVendorPayableAuthorizer`,
 * `Actions\ManualPayout`, the reconciliation reports), whose whole job is to
 * see every vendor's payables. A deny-by-default global scope on that model
 * would blind them, since a finance actor holds no `vendor:` grants. So the
 * constraint lives here, at the one surface that must not see across vendors.
 *
 * Read-only by construction: no create, edit, or delete action, and no write
 * path. Payout state is decided by `FinancialLedger`'s Actions on the
 * platform side; a vendor observes it and never sets it.
 */
final class PayoutStatus extends Page implements HasTable
{
    use VendorScopedTablePage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $navigationLabel = 'Pencairan';

    protected static ?string $title = 'Status Pencairan';

    protected static ?string $slug = 'pencairan';

    protected static ?int $navigationSort = 60;

    /**
     * @return Builder<VendorPayable>
     */
    public static function vendorScopedQuery(): Builder
    {
        return VendorPayable::query()
            ->with('payout')
            ->whereIn('vendor_id', app(CurrentVendorScope::class)->grantedVendorIds());
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::vendorScopedQuery())
            ->columns([
                TextColumn::make('entity_ref')
                    ->label('Referensi')
                    ->searchable(),

                TextColumn::make('amount_minor')
                    ->label('Jumlah')
                    ->money('IDR', divideBy: 100)
                    ->sortable(),

                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        VendorPayableState::HELD => 'gray',
                        VendorPayableState::PAYABLE => 'info',
                        VendorPayableState::PAID => 'success',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        VendorPayableState::HELD => 'Ditahan',
                        VendorPayableState::PAYABLE => 'Dapat dicairkan',
                        VendorPayableState::PAID => 'Sudah dicairkan',
                        default => $state,
                    }),

                TextColumn::make('eligible_at')
                    ->label('Bebas pada')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('paid_at')
                    ->label('Dicairkan pada')
                    ->dateTime('d M Y H:i')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('payout.reference')
                    ->label('Referensi pencairan')
                    ->placeholder('—'),
            ])
            ->emptyStateHeading('Belum ada pencairan')
            ->emptyStateDescription('Kewajiban pembayaran akan muncul di sini setelah pesanan Anda selesai.')
            ->emptyStateIcon(Heroicon::OutlinedBanknotes)
            ->defaultSort('eligible_at', 'desc');
    }
}

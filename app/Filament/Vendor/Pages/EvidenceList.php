<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Domain\Marketplace\Access\CurrentVendorScope;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\VendorOrderEvidence;
use App\Filament\Vendor\Concerns\VendorScopedTablePage;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `/vendor/bukti` — every evidence file uploaded against this vendor's own
 * orders.
 *
 * ---------------------------------------------------------------------------
 * Scoped through the order, because the row itself does not know its vendor
 * ---------------------------------------------------------------------------
 * `vendor_order_evidences` carries `vendor_order_id` and no `vendor_id`, so
 * this is the one surface in the panel that cannot use a direct
 * `whereIn('vendor_id', ...)`. It constrains on the parent order instead.
 *
 * That still fails closed for an actor with no grant: the inner
 * `whereIn('vendor_id', [])` compiles to an always-false clause, so
 * `whereHas` matches no parent and therefore no evidence row. The closed
 * default survives the extra hop rather than being re-derived at it.
 *
 * ---------------------------------------------------------------------------
 * `file_path` is shown, downloads are not offered
 * ---------------------------------------------------------------------------
 * The plan's Task 6.2 asks for signed expiring download URLs. This page does
 * not issue them, and deliberately renders only the stored path rather than
 * linking it. `vendor_order_evidences.file_path` holds a QUARANTINE path (see
 * the plan's own schema note), and this repository's document-vault module is
 * the component that owns deciding when a quarantined object may be released
 * and issuing a URL for it. Minting a URL here would either bypass that
 * decision or duplicate it. Serving evidence downloads is left to a task that
 * routes through the vault rather than around it; a link that appeared to work
 * while skipping quarantine would be worse than no link.
 */
final class EvidenceList extends Page implements HasTable
{
    use VendorScopedTablePage;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static ?string $navigationLabel = 'Bukti';

    protected static ?string $title = 'Bukti Pekerjaan';

    protected static ?string $slug = 'bukti';

    protected static ?int $navigationSort = 40;

    /**
     * @return Builder<VendorOrderEvidence>
     */
    public static function vendorScopedQuery(): Builder
    {
        $grantedVendorIds = app(CurrentVendorScope::class)->grantedVendorIds();

        return VendorOrderEvidence::query()
            ->with('order')
            ->whereHas(
                'order',
                fn (Builder $order): Builder => $order->whereIn('vendor_id', $grantedVendorIds),
            );
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => self::vendorScopedQuery())
            ->columns([
                TextColumn::make('order.uuid')
                    ->label('ID Pesanan')
                    ->limit(8)
                    ->copyable()
                    ->searchable(),

                TextColumn::make('evidence_type')
                    ->label('Jenis bukti')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        EvidenceRequirement::PHOTO => 'Foto',
                        EvidenceRequirement::DOCUMENT => 'Dokumen',
                        default => $state,
                    }),

                TextColumn::make('file_path')
                    ->label('Berkas')
                    ->limit(48)
                    ->tooltip(fn (VendorOrderEvidence $record): string => (string) $record->file_path),

                TextColumn::make('uploaded_at')
                    ->label('Diunggah')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->emptyStateHeading('Belum ada bukti')
            ->emptyStateDescription('Bukti pengerjaan yang Anda unggah pada pesanan akan tampil di sini.')
            ->emptyStateIcon(Heroicon::OutlinedPhoto)
            ->defaultSort('uploaded_at', 'desc');
    }
}

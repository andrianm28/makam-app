<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\VendorListings\Schemas;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Product;
use App\Filament\Vendor\Schemas\VendorPicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Create/edit form for `VendorListingResource`.
 *
 * The `availability_mode` and `evidence_requirement` option lists are built
 * from `AvailabilityMode::KNOWN` / `EvidenceRequirement::KNOWN` rather than
 * from literal arrays. Those classes are the closed lists the model's own
 * `saving` hook validates against, so deriving the options here means a value
 * the form can offer is a value the model will accept, permanently — a
 * hand-copied list would drift the first time either closed list grows.
 *
 * `vendor_id` is NOT a plain input. It comes from `VendorPicker`, which
 * renders only for multi-grant actors, and the value is decided server-side by
 * `Concerns\StampsCurrentVendor` on create. On edit the field still renders
 * for a multi-grant actor with options limited to granted vendors — the
 * options list is what Filament's Select validates the submitted value against
 * (see `Pages\EditVendorListing`'s doc block), and the save is additionally
 * gated by `Concerns\GuardsCurrentVendorOnEdit`, which re-reads the grant
 * table.
 */
final class VendorListingForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                VendorPicker::make(),

                Select::make('product_id')
                    ->label('Produk katalog')
                    ->options(fn (): array => Product::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->required()
                    ->native(false)
                    ->searchable()
                    // The catalogue product a listing points at is its identity
                    // as far as pricing and cart lines are concerned. Repointing
                    // it after creation would silently rewrite the meaning of
                    // every order already placed against this listing.
                    ->disabledOn('edit'),

                TextInput::make('price_minor')
                    ->label('Harga (rupiah, dalam sen)')
                    ->helperText('Nilai dalam satuan terkecil rupiah, mengikuti kolom price_minor.')
                    ->numeric()
                    ->required()
                    ->minValue(1),

                Select::make('availability_mode')
                    ->label('Mode ketersediaan')
                    ->options(self::labelled(AvailabilityMode::KNOWN, [
                        AvailabilityMode::STOCKED => 'Stok tersedia',
                        AvailabilityMode::MADE_TO_ORDER => 'Dibuat setelah pesanan',
                        AvailabilityMode::SCHEDULED => 'Dijadwalkan',
                    ]))
                    ->required()
                    ->native(false),

                TextInput::make('stock_quantity')
                    ->label('Jumlah stok')
                    ->numeric()
                    ->minValue(0)
                    ->nullable(),

                TextInput::make('production_lead_time_days')
                    ->label('Waktu pengerjaan (hari)')
                    ->numeric()
                    ->minValue(0)
                    ->nullable(),

                Select::make('evidence_requirement')
                    ->label('Bukti yang wajib diunggah')
                    ->options(self::labelled(EvidenceRequirement::KNOWN, [
                        EvidenceRequirement::NONE => 'Tidak ada',
                        EvidenceRequirement::PHOTO => 'Foto',
                        EvidenceRequirement::DOCUMENT => 'Dokumen',
                    ]))
                    ->required()
                    ->native(false),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),

                Textarea::make('cancellation_policy')
                    ->label('Kebijakan pembatalan')
                    ->nullable()
                    ->columnSpanFull(),
            ]);
    }

    /**
     * Pairs a closed list with its Indonesian labels, and fails loudly if the
     * two ever disagree. A missing label would otherwise render as a blank
     * option the vendor cannot tell apart from its neighbours.
     *
     * @param  list<string>  $known
     * @param  array<string, string>  $labels
     * @return array<string, string>
     */
    private static function labelled(array $known, array $labels): array
    {
        $options = [];

        foreach ($known as $value) {
            $options[$value] = $labels[$value] ?? $value;
        }

        return $options;
    }
}

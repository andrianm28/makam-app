<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Resources\ServiceAreas\Schemas;

use App\Filament\Vendor\Schemas\VendorPicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Create/edit form for `ServiceAreaResource`.
 *
 * `area_code` is a free-text input on purpose. The migration
 * (`2026_08_12_100030_create_service_areas_table.php`) states that the column
 * is "a vendor-supplied free string, NOT a platform region taxonomy", and that
 * no closed list of Indonesian regions is invented for it because that would
 * be a rival source of truth. A Select here would have to be backed by such a
 * list, so the helper text explains the convention instead of a dropdown
 * pretending a taxonomy exists.
 *
 * `vendor_id` is NOT a plain input. It comes from `VendorPicker`, which renders
 * only for multi-grant actors, and the value is decided server-side by
 * `Concerns\StampsCurrentVendor` on create. On edit the field still renders
 * for a multi-grant actor but its options stay limited to granted vendors, and
 * Filament's Select derives an `in:` validation rule from those options — so a
 * forged `vendor_id` for a vendor the actor does NOT hold fails validation and
 * no write occurs; see `Pages\EditServiceArea`'s doc block.
 */
final class ServiceAreaForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                VendorPicker::make(),

                TextInput::make('area_code')
                    ->label('Kode area')
                    ->helperText('Kode bebas yang Anda tentukan sendiri. Platform tidak memakai daftar wilayah administratif, jadi gunakan konvensi internal Anda dan pakai kode yang sama secara konsisten.')
                    ->required()
                    ->maxLength(64),

                TextInput::make('area_label')
                    ->label('Nama area')
                    ->helperText('Nama yang mudah dikenali, misalnya nama kecamatan atau kawasan.')
                    ->required()
                    ->maxLength(255),

                TextInput::make('delivery_fee_minor')
                    ->label('Ongkos kirim (rupiah, dalam sen)')
                    ->helperText('Nilai dalam satuan terkecil rupiah, mengikuti kolom delivery_fee_minor.')
                    ->numeric()
                    ->minValue(0)
                    ->nullable(),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
            ]);
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductResource\Schemas;

use App\Domain\Marketplace\MarketplaceProductCategory;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\ProductCode;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Create/edit form for `ProductResource` — fields per the plan's Task 5:
 * `code` and `category` are the canonical closed lists and are disabled on
 * edit (never user-invented — AGENTS.md: "do not invent alternate labels");
 * `vendor_name`, `base_price_idr`, `photo_path` are editable; `price_version`
 * is deliberately NOT a field (it is the model's version counter, moved by
 * `Pages\EditProduct` when the base price changes — never by the admin).
 *
 * On CREATE, `code`/`category` are selects over the canonical lists (the
 * code options label themselves with the catalogue names from the live
 * seeded rows, falling back to the bare code when a row was removed) — so
 * even a new row can only carry an existing canonical code/category, and
 * the model's `saving` hook's `assertKnown()` checks always hold.
 *
 * `reason` is an edit-only, required field: `ProductAuditActions::UPDATED`
 * is on `SensitiveActions::ACTIONS`, so `Audit::record()` throws on a blank
 * reason — this field is the form boundary that prevents that path from
 * ever being reached. It is not a `products` column; `Pages\EditProduct`
 * reads it for the audit row and removes it from the model payload.
 */
final class ProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('code')
                    ->label('Kode produk')
                    ->options(fn (): array => self::codeOptions())
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->helperText(
                        'Kode kanonik dari katalog (ProductCode::KNOWN_CODES). Tidak dapat diubah pada produk '
                        .'yang sudah ada -- kode produk adalah master data, bukan konten bebas.'
                    ),

                Select::make('category')
                    ->label('Kategori')
                    ->options(fn (): array => array_combine(
                        MarketplaceProductCategory::KNOWN_KEYS,
                        array_map(
                            fn (string $key): string => MarketplaceProductCategory::label($key),
                            MarketplaceProductCategory::KNOWN_KEYS,
                        ),
                    ))
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->helperText('Tiga kategori kanonik katalog (MarketplaceProductCategory::KNOWN_KEYS).'),

                TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->maxLength(255),

                TextInput::make('vendor_name')
                    ->label('Nama vendor')
                    ->maxLength(255)
                    ->helperText(
                        'Vendor tunggal yang ter-denormalisasi pada baris ini; diganti menyeluruh oleh tabel '
                        .'vendor/listing sungguhan di batch mendatang.'
                    ),

                TextInput::make('base_price_idr')
                    ->label('Harga dasar (Rp)')
                    ->numeric()
                    ->minValue(0)
                    ->helperText(
                        'Mengubah harga dasar menaikkan versi harga otomatis -- setiap perubahan harga adalah '
                        .'pemotongan definisi produk baru (lihat kolom "Versi harga" pada tabel).'
                    ),

                TextInput::make('photo_path')
                    ->label('Path foto')
                    ->maxLength(255),

                Textarea::make('description')
                    ->label('Deskripsi')
                    ->required()
                    ->rows(4)
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),

                TextInput::make('sort_order')
                    ->label('Urutan tampil')
                    ->numeric()
                    ->required()
                    ->minValue(1),

                Textarea::make('reason')
                    ->label('Alasan perubahan')
                    ->required(fn (string $operation): bool => $operation === 'edit')
                    ->visible(fn (string $operation): bool => $operation === 'edit')
                    ->rows(2)
                    ->columnSpanFull()
                    ->helperText(
                        'Wajib saat menyimpan perubahan: tindakan audit PRODUCT_UPDATED termasuk daftar aksi '
                        .'sensitif, sehingga Audit::record() menolak alasan kosong.'
                    ),
            ]);
    }

    /**
     * The nine canonical codes, labelled with the catalogue names of their
     * live rows (so a re-created row after a delete still shows a readable
     * option, falling back to the bare code).
     *
     * @return array<string, string>
     */
    private static function codeOptions(): array
    {
        $names = Product::query()->pluck('name', 'code')->all();

        return array_combine(
            ProductCode::KNOWN_CODES,
            array_map(
                fn (string $code): string => $names[$code] ?? $code,
                ProductCode::KNOWN_CODES,
            ),
        );
    }
}

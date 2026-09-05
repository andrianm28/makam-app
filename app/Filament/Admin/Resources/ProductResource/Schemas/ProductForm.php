<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductResource\Schemas;

use App\Domain\Marketplace\MarketplaceProductCategory;
use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\ProductCode;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/**
 * Create/edit form for `ProductResource` — fields per the plan's Task 5:
 * `code` and `category` are the canonical closed lists and are disabled on
 * edit (never user-invented — AGENTS.md: "do not invent alternate labels");
 * `vendor_name`, `base_price_idr`, `photo_path` are editable; `price_version`
 * is deliberately NOT a field (it is the model's version counter, moved by
 * `Pages\EditProduct` when the base price changes — never by the admin).
 *
 * `photo_path` is a real `FileUpload`, not a free-text path: it is the
 * primary UX half of the go-live photo gate (`Product`'s `saving` hook
 * throws `ProductMustHavePhotoToActivateException` as the backstop), so
 * `required()` is conditioned on `is_active` via `Get` + the toggle's
 * `->live()` — an admin sees a real validation error, not a 500 from the
 * model layer, the moment they try to activate a photo-less product.
 * Uploads go to the `public` disk (`marketplace/products/`), unlike every
 * other `FileUpload` in this codebase (`CreateCertificateAction`,
 * `UploadEvidenceAction`), which upload to `local` and go through the
 * DocumentVault quarantine → scan → promote pipeline: those are private,
 * sensitive documents; a catalogue photo is public marketing content with
 * no such requirement, and `Product` is not a DocumentVault subject type.
 * The nine seeded rows' `photo_path` values instead point at committed
 * `public/images/marketplace/*.svg` files and are resolved via `asset()`
 * (see `2026_08_24_110000_backfill_photo_path_for_real_products.php`);
 * `MarketplacePresenter::photoUrl()` resolves BOTH conventions. An
 * admin-uploaded photo is only reachable at its `/storage/...` URL once
 * `php artisan storage:link` has run on the serving host — this repo has no
 * existing `public`-disk upload feature and no `storage:link` step wired
 * into any deployment script found in this batch, so that is flagged here
 * as a deployment prerequisite, not silently assumed.
 *
 * CONSEQUENCE of the disk mismatch above, verified by test failure before
 * being fixed here: because the seeded legacy paths do not live on the
 * `public` DISK (they live under `public/` the WEB ROOT, an unrelated
 * location), `FileUpload`'s own widget state reads every seeded product's
 * existing photo as absent. Both `required()` and `dehydrated()` are
 * therefore evaluated against the RECORD's real `photo_path` column
 * (`?Product $record`), not the field's own `$get()` state:
 * `dehydrated()` so an unrelated edit to a seeded product (e.g. changing
 * `vendor_name`) does not silently null out its real, working legacy photo
 * by omission; `required()` so editing one does not force a needless
 * re-upload just because the widget cannot see the legacy file. A fresh
 * upload (create, or replacing an existing photo) is unaffected — it lands
 * on the `public` disk for real, so both checks work exactly as Filament's
 * built-in ones would.
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

                FileUpload::make('photo_path')
                    ->label('Foto produk')
                    ->disk('public')
                    ->directory('marketplace/products')
                    ->image()
                    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                    ->maxSize(10240)
                    // Only overwrite `photo_path` when a NEW file was
                    // actually uploaded. The nine seeded rows' photo_path
                    // values point at public/images/marketplace/*.svg —
                    // real files, but not on THIS disk — so this field's
                    // own widget state reads as empty for them; without
                    // this, saving any unrelated edit to a seeded product
                    // would silently null out its real, working photo.
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    // Required on activation only when the record does not
                    // ALREADY have a photo — checked against the record's
                    // real column, not this field's widget state, for the
                    // same disk-mismatch reason: a seeded product's existing
                    // legacy photo must not force a re-upload on every
                    // unrelated edit just because the widget can't see it.
                    ->required(fn (Get $get, ?Product $record): bool => (bool) $get('is_active') && blank($record?->photo_path))
                    ->helperText(
                        'JPG/PNG/WebP maksimal 10 MB. Wajib diisi saat produk diaktifkan dan belum memiliki foto -- '
                        .'listing aktif tanpa foto adalah celah yang sama yang ditemukan pada katalog kompetitor.'
                    ),

                Textarea::make('description')
                    ->label('Deskripsi')
                    ->required()
                    ->rows(4)
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->live(),

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

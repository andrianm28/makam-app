<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LaunchCities\Schemas;

use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Create/edit form for `LaunchCityResource` — fields per the plan: code
 * (uppercase + unique + not-blank), label, active toggle, sort order.
 *
 * ---------------------------------------------------------------------------
 * Code immutability on edit
 * ---------------------------------------------------------------------------
 * `code` is `disabled()` whenever the operation is `edit`, the same
 * treatment `CemeteryForm` gives its `slug`: a launch city's code is the
 * identity other rows reference (`booking_drafts.city_code`,
 * `cemeteries.city`, every `LaunchCityQuery` consumer), so changing it in
 * place would silently orphan those references. A disabled field is
 * excluded from Filament's dehydrated form state, so the record's code is
 * never part of the update payload — the DB unique index
 * (`launch_cities.code`) backs the create path instead (`->unique()`).
 *
 * ---------------------------------------------------------------------------
 * Uppercase is validated, not normalized
 * ---------------------------------------------------------------------------
 * `LaunchCity::booted()`'s `saving` hook rejects any code that is not
 * already uppercase (`InvalidArgumentException`), so the form must never
 * submit a lowercase code — `->regex()` enforces the uppercase shape
 * before the model hook can throw, and the error surfaces as a normal
 * form validation message rather than a 500. (`LaunchCityCode::KNOWN_CODES`
 * is written uppercase, so this matches how the canonical five are
 * stored.)
 *
 * ---------------------------------------------------------------------------
 * Write path: the model, wrapped in `Audit::wrap` by the pages
 * ---------------------------------------------------------------------------
 * No launch-city write Domain Action exists — the design doc's "route
 * through domain Actions WHERE THEY EXIST" rule has nothing to route to
 * here, so the default model save is the write path and
 * `LaunchCity::booted()`'s `saving` hook (uppercase code assertion) still
 * fires on it. The audit pairing lives in `Pages\CreateLaunchCity` /
 * `Pages\EditLaunchCity`, which override the record-creation/update
 * handlers to wrap the save in `Audit::wrap`.
 */
final class LaunchCityForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->label('Kode')
                    ->required()
                    ->maxLength(255)
                    ->regex('/^[A-Z][A-Z0-9_]*$/')
                    ->unique(table: 'launch_cities', column: 'code', ignoreRecord: true)
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->helperText(
                        'Kode kota dalam huruf kapital (contoh: JAKARTA). Identitas permanen yang '
                        .'dipakai pemesanan tersimpan dan daftar publik -- tidak dapat diubah setelah '
                        .'kota dibuat.'
                    ),

                TextInput::make('label')
                    ->label('Nama kota')
                    ->required()
                    ->maxLength(255),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->helperText(
                        'Kota nonaktif tetap dikenali pemesanan tersimpan, tetapi tidak ditampilkan '
                        .'pada daftar kota publik.'
                    ),

                TextInput::make('sort_order')
                    ->label('Urutan')
                    ->numeric()
                    ->default(0)
                    ->minValue(0)
                    ->helperText(
                        'Gunakan aksi "Naikkan urutan"/"Turunkan urutan" pada tabel daftar untuk '
                        .'penataan ulang yang dijamin tanpa celah.'
                    ),
            ]);
    }
}

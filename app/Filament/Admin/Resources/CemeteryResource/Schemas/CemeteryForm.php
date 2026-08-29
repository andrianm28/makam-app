<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CemeteryResource\Schemas;

use App\Domain\CemeteryDirectory\CemeteryPublicationStatus;
use App\Domain\CemeteryDirectory\CemeteryType;
use App\Domain\CemeteryDirectory\LaunchCityCode;
use App\Domain\CemeteryDirectory\LaunchCityQuery;
use App\Domain\CemeteryDirectory\PlotTrackingMode;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Create/edit form for `CemeteryResource` — fields per the plan: name, slug,
 * type, city, address, latitude/longitude, facilities, price_min/price_max,
 * publication_status. Every closed-list field sources its options from the
 * canonical enum vocabularies (`CemeteryType::KNOWN_TYPES`,
 * `CemeteryPublicationStatus::KNOWN_STATUSES`) or, for `city`, the
 * table-backed `launch_cities` catalogue (`LaunchCityQuery::activeCities()`,
 * falling back to `LaunchCityCode::KNOWN_CODES`) — no label or value exists
 * here that one of those classes does not define (`AGENTS.md`: "do not
 * invent alternate labels").
 *
 * ---------------------------------------------------------------------------
 * Slug immutability on edit
 * ---------------------------------------------------------------------------
 * `slug` is `disabled()` whenever the operation is `edit`. A disabled
 * field is excluded from Filament's dehydrated form state, so the record's
 * slug is never part of the update payload and can never be changed by
 * submitting this form — the DB unique index (`cemeteries.slug`) backs the
 * create path instead (`->unique()`). This matters for the same reason
 * `FaqArticleForm` documents for its slug: changing a live cemetery's slug
 * would silently orphan public URLs that reference it.
 *
 * ---------------------------------------------------------------------------
 * Write path: the model, not a Domain Action
 * ---------------------------------------------------------------------------
 * Unlike the FAQ resource (which routes every write through
 * `CreateFaqArticleDraft`/`UpdateFaqArticleContent` because those Actions
 * exist), no cemetery write Action exists in `CemeteryDirectory` — the
 * design doc's "route through domain Actions WHERE THEY EXIST" rule has no
 * Action to route to here. The default model save is therefore the correct
 * path, and `Cemetery::booted()`'s `saving` hook (closed-list assertion for
 * type/publication_status/city) still fires on it — the admin cannot write
 * a value that hook would reject.
 *
 * `plot_tracking_mode` IS the exception to the paragraph above: it DOES
 * have a Domain Action (`SetCemeteryPlotTrackingMode`, guarded and
 * audited), so unlike every other field on this form it is `disabled()`
 * here — display-only, matching the `slug` convention above. Writing it
 * goes through `Actions\SwitchToGranularTrackingAction` on the edit page's
 * header instead.
 */
final class CemeteryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Nama')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('slug')
                    ->label('Slug')
                    ->required()
                    ->maxLength(255)
                    ->alphaDash()
                    ->unique(table: 'cemeteries', column: 'slug', ignoreRecord: true)
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->helperText(
                        'Identitas permanen makam pada URL publik. Tidak dapat diubah setelah '
                        .'makam dibuat.'
                    ),

                Select::make('type')
                    ->label('Jenis')
                    ->required()
                    ->native(false)
                    ->options(array_combine(
                        CemeteryType::KNOWN_TYPES,
                        [
                            'Tempat Pemakaman Umum (TPU)',
                            'Tempat Pemakaman Swasta/Khusus (TPS)',
                        ],
                    )),

                Select::make('city')
                    ->label('Kota')
                    ->required()
                    ->native(false)
                    ->options(self::cityOptions()),

                Textarea::make('address')
                    ->label('Alamat')
                    ->required()
                    ->rows(3)
                    ->maxLength(500)
                    ->columnSpanFull(),

                TextInput::make('latitude')
                    ->label('Latitude')
                    ->numeric()
                    ->nullable()
                    ->placeholder('-6.2000000'),

                TextInput::make('longitude')
                    ->label('Longitude')
                    ->numeric()
                    ->nullable()
                    ->placeholder('106.8000000'),

                Repeater::make('facilities')
                    ->label('Fasilitas')
                    // `simple()` keeps each item a plain string (the child's
                    // value is plucked on state hydration), matching the
                    // `cemeteries.facilities` JSON column's existing shape —
                    // the seed data stores `["Area Parkir", "Toilet Umum"]`,
                    // not an array of objects.
                    ->simple(TextInput::make('facilities')->label('Fasilitas'))
                    ->default([])
                    ->columnSpanFull(),

                TextInput::make('price_min')
                    ->label('Harga mulai (Rp)')
                    ->numeric()
                    ->nullable()
                    ->minValue(0),

                TextInput::make('price_max')
                    ->label('Harga maksimal (Rp)')
                    ->numeric()
                    ->nullable()
                    ->minValue(0),

                Select::make('publication_status')
                    ->label('Status')
                    ->required()
                    ->native(false)
                    ->options(array_combine(
                        CemeteryPublicationStatus::KNOWN_STATUSES,
                        ['Draf', 'Dipublikasikan', 'Tidak dipublikasikan'],
                    ))
                    ->columnSpanFull(),

                Select::make('plot_tracking_mode')
                    ->label('Pelacakan petak')
                    ->native(false)
                    ->default(PlotTrackingMode::AGGREGATE)
                    ->options(array_combine(
                        PlotTrackingMode::KNOWN_MODES,
                        ['Agregat (kuota per paket)', 'Granular (per petak)'],
                    ))
                    // Read-only display, same convention as `slug` above:
                    // disabled() excludes it from the dehydrated payload, so
                    // this form can never write plot_tracking_mode. The only
                    // sanctioned write path is
                    // App\Domain\CemeteryDirectory\Actions\
                    // SetCemeteryPlotTrackingMode, called from the "Aktifkan
                    // pelacakan granular" header action on the edit page.
                    ->disabled()
                    ->helperText(
                        'Klasifikasi permanen. Diubah lewat tombol "Aktifkan pelacakan '
                        .'granular" pada halaman ubah, bukan lewat form ini.'
                    )
                    ->columnSpanFull(),
            ]);
    }

    /**
     * City options from the ACTIVE `launch_cities` rows (the admin-
     * extendable catalogue, ordered by `sort_order`), falling back to the
     * canonical `LaunchCityCode::KNOWN_CODES` mapping when the table has
     * no active rows — the seed migration guarantees it does.
     *
     * Filament evaluates form definitions in a static context when it
     * compiles the schema into its blade view, so this is deliberately
     * `static` (no `$this`) — every other schema in this resource follows
     * the same convention.
     *
     * @return array<string, string>
     */
    private static function cityOptions(): array
    {
        $cities = LaunchCityQuery::activeCities();

        if ($cities !== []) {
            return array_combine(
                array_column($cities, 'code'),
                array_column($cities, 'label'),
            );
        }

        return array_combine(
            LaunchCityCode::KNOWN_CODES,
            array_map(
                static fn (string $code): string => ucfirst(strtolower($code)),
                LaunchCityCode::KNOWN_CODES,
            ),
        );
    }
}

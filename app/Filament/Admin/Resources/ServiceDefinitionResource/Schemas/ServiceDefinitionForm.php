<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceDefinitionResource\Schemas;

use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\ServiceCategory;
use App\Domain\ServiceCatalog\ServiceCode;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

/**
 * Create/edit form for `ServiceDefinitionResource`.
 *
 * `code` and `category` are canonical catalogue data and are drawn ONLY from
 * the canonical closed lists (`ServiceCode::KNOWN_CODES`,
 * `ServiceCategory::KNOWN_CATEGORIES`) — there is no free-text path, so no
 * code or category outside `docs/product/service-catalog.md` can ever be
 * entered here. `code` is additionally `->disabled()` on edit: once a
 * service is registered under a code, that code is immutable for the life of
 * the row. On create it is a required select, because a new row has no code
 * yet — but the only codes selectable are the 12 canonical ones, and the
 * `unique` rule refuses a code that is already registered (a field-keyed
 * error, per the spec's "unique collisions surface as field-keyed Filament
 * notifications").
 *
 * `fulfillment_owner` is nullable on purpose: the seed deliberately leaves
 * every row's owner unset (`2026_07_26_180700_...`'s own doc block), and an
 * admin may equally choose to record a service before its owner decision is
 * made. The select's options are `FulfillmentOwner::KNOWN_OWNERS` — the
 * closed list.
 *
 * The mandatory `reason` field exists because
 * `ServiceCatalogAuditActions::SERVICE_DEFINITION_CREATED` /
 * `SERVICE_DEFINITION_UPDATED` are on `SensitiveActions::ACTIONS`, so
 * `Audit::record()` REFUSES a blank justification at write time
 * (`AuditReasonRequiredException`). Requiring it here in the form turns that
 * backend refusal into a normal field-keyed validation error instead of a
 * mid-request exception — the page classes read it out of `$data` and pass
 * it through to the audit call; it is never persisted on the model.
 */
final class ServiceDefinitionForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('code')
                    ->label('Kode layanan')
                    ->options(array_combine(ServiceCode::KNOWN_CODES, ServiceCode::KNOWN_CODES))
                    ->required()
                    ->disabled(fn (string $operation): bool => $operation === 'edit')
                    ->unique(table: 'service_definitions', column: 'code', ignoreRecord: true)
                    ->helperText(
                        'Kode kanonik dari katalog layanan — tidak dapat diubah setelah layanan terdaftar, '
                        .'dan hanya kode dari katalog yang dapat dipilih.'
                    ),

                TextInput::make('name')
                    ->label('Nama layanan')
                    ->required()
                    ->maxLength(255),

                Select::make('category')
                    ->label('Kategori')
                    ->options(array_combine(ServiceCategory::KNOWN_CATEGORIES, ServiceCategory::KNOWN_CATEGORIES))
                    ->required()
                    ->native(false)
                    ->helperText('Kategori kanonik dari katalog layanan.'),

                Select::make('fulfillment_owner')
                    ->label('Pihak pemenuhan')
                    ->options(array_combine(FulfillmentOwner::KNOWN_OWNERS, FulfillmentOwner::KNOWN_OWNERS))
                    ->placeholder('Belum ditentukan')
                    ->native(false)
                    ->helperText(
                        'Siapa yang memenuhi layanan ini: platform, pengelola TPU, atau vendor. '
                        .'Kosongkan bila keputusan belum diambil.'
                    ),

                Toggle::make('requires_schedule')
                    ->label('Perlu penjadwalan'),

                Toggle::make('requires_manual_confirmation')
                    ->label('Perlu konfirmasi manual'),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true)
                    ->columnSpanFull(),

                Textarea::make('description')
                    ->label('Deskripsi')
                    ->rows(3)
                    ->maxLength(1000)
                    ->columnSpanFull(),

                Textarea::make('reason')
                    ->label(fn (string $operation): string => $operation === 'create'
                        ? 'Alasan pembuatan (wajib)'
                        : 'Alasan perubahan (wajib)')
                    ->rows(2)
                    ->required()
                    ->columnSpanFull()
                    ->helperText(
                        'Dicatat ke audit trail. Wajib diisi karena mengubah layanan katalog memengaruhi '
                        .'cara pesanan dipenuhi dan apa yang ditawarkan alur pemesanan publik.'
                    ),
            ]);
    }
}

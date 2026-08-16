<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Vendors\RelationManagers;

use App\Domain\Marketplace\AvailabilityMode;
use App\Domain\Marketplace\EvidenceRequirement;
use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorListing;
use App\Domain\Marketplace\VendorAuditActions;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Offer rows (`vendor_listings`) for `VendorResource` — one vendor's offer
 * of one catalogue product, carrying every field `products` does not:
 * availability mode, stock, production lead time, cancellation policy, and
 * evidence requirement.
 *
 * ---------------------------------------------------------------------------
 * Closed lists render from the canonical vocabularies — never hand-rolled
 * ---------------------------------------------------------------------------
 * The `availability_mode` and `evidence_requirement` selects build their
 * options from `AvailabilityMode::KNOWN` and `EvidenceRequirement::KNOWN`
 * with Indonesian labels, exactly like `CemeteryForm` sources its closed
 * lists (`AGENTS.md`: "do not invent alternate labels"). The model's own
 * `saving` hook re-asserts both values on every write, so the admin can
 * never save a value the hook would reject.
 *
 * ---------------------------------------------------------------------------
 * Write path + authorization (the `PackagesRelationManager` pattern)
 * ---------------------------------------------------------------------------
 * No `VendorListing` write Action exists, so Filament's relationship-save
 * path is used, with both actions carrying `->authorize(...)` and every
 * write wrapped in `Audit::wrap()` (`VendorAuditActions::LISTING_CREATED`
 * / `::LISTING_UPDATED`) so the row change and its `audit_events` entry
 * commit in the same transaction (AC4). Deletion is deliberately NOT
 * offered: the P2 spec scopes this manager to list + inline create/edit,
 * the same boundary `PackagesRelationManager` documents for packages.
 */
final class ListingsRelationManager extends RelationManager
{
    protected static string $relationship = 'listings';

    protected static ?string $title = 'Listing';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::actorMayManage();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('product_id')
                    ->label('Produk')
                    ->required()
                    ->native(false)
                    ->searchable()
                    ->relationship(
                        name: 'product',
                        titleAttribute: 'name',
                    )
                    ->columnSpanFull(),

                TextInput::make('price_minor')
                    ->label('Harga (Rp)')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->columnSpan(1),

                Select::make('availability_mode')
                    ->label('Mode ketersediaan')
                    ->required()
                    ->native(false)
                    ->options(array_combine(
                        AvailabilityMode::KNOWN,
                        [
                            'Tersedia (stok)',
                            'Pesan dulu (dibuat saat pesanan)',
                            'Terjadwal (kalender)',
                        ],
                    )),

                TextInput::make('stock_quantity')
                    ->label('Jumlah stok')
                    ->numeric()
                    ->minValue(0)
                    ->nullable()
                    ->helperText('Hanya bermakna untuk mode "Tersedia (stok)".'),

                TextInput::make('production_lead_time_days')
                    ->label('Waktu produksi (hari)')
                    ->numeric()
                    ->minValue(1)
                    ->nullable(),

                Select::make('evidence_requirement')
                    ->label('Bukti penyelesaian')
                    ->required()
                    ->native(false)
                    ->options(array_combine(
                        EvidenceRequirement::KNOWN,
                        [
                            'Tidak ada',
                            'Foto',
                            'Dokumen',
                        ],
                    )),

                Textarea::make('cancellation_policy')
                    ->label('Kebijakan pembatalan')
                    ->nullable()
                    ->rows(3)
                    ->maxLength(1000)
                    ->columnSpanFull(),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
            ]);
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('product.name')
                    ->label('Produk')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('price_minor')
                    ->label('Harga')
                    ->numeric(decimalPlaces: 0)
                    ->prefix('Rp ')
                    ->sortable(),

                TextColumn::make('availability_mode')
                    ->label('Ketersediaan')
                    ->badge()
                    ->color('info')
                    ->formatStateUsing(fn (string $state): string => self::availabilityLabel($state))
                    ->sortable(),

                TextColumn::make('stock_quantity')
                    ->label('Stok')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('evidence_requirement')
                    ->label('Bukti')
                    ->badge()
                    ->color('gray')
                    ->formatStateUsing(fn (string $state): string => self::evidenceLabel($state))
                    ->sortable(),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->using(function (array $data): Model {
                        $actor = app(ActorContext::class);

                        /** @var Vendor $owner */
                        $owner = $this->getOwnerRecord();

                        return Audit::wrap(
                            fn (): VendorListing => $owner->listings()->create($data),
                            action: VendorAuditActions::LISTING_CREATED,
                            subject: fn (VendorListing $saved): AuditSubject => new AuditSubject(
                                type: 'vendor_listing',
                                id: (string) $saved->getKey(),
                            ),
                            outcome: AuditOutcome::Allowed,
                            actorRef: $actor->identityReference,
                            actorRole: VendorResource::auditRoleFor($actor),
                            source: AuditSource::Panel,
                        );
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->using(function (Model $record, array $data): Model {
                        $actor = app(ActorContext::class);

                        return Audit::wrap(
                            fn (): Model => tap($record)->update($data),
                            action: VendorAuditActions::LISTING_UPDATED,
                            subject: new AuditSubject(
                                type: 'vendor_listing',
                                id: (string) $record->getKey(),
                            ),
                            outcome: AuditOutcome::Allowed,
                            actorRef: $actor->identityReference,
                            actorRole: VendorResource::auditRoleFor($actor),
                            source: AuditSource::Panel,
                        );
                    }),
            ]);
    }

    public static function availabilityLabel(string $state): string
    {
        return match ($state) {
            AvailabilityMode::STOCKED => 'Tersedia (stok)',
            AvailabilityMode::MADE_TO_ORDER => 'Pesan dulu',
            AvailabilityMode::SCHEDULED => 'Terjadwal',
            default => $state,
        };
    }

    public static function evidenceLabel(string $state): string
    {
        return match ($state) {
            EvidenceRequirement::NONE => 'Tidak ada',
            EvidenceRequirement::PHOTO => 'Foto',
            EvidenceRequirement::DOCUMENT => 'Dokumen',
            default => $state,
        };
    }

    private static function actorMayManage(): bool
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }

        return true;
    }
}

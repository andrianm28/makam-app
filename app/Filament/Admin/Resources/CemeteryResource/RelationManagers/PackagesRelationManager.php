<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CemeteryResource\RelationManagers;

use App\Domain\CemeteryCapability\CemeteryPackageAuditActions;
use App\Domain\CemeteryCapability\CemeteryPackageAvailabilityStatus;
use App\Domain\CemeteryCapability\Models\CemeteryPackage;
use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Filament\Admin\Resources\CemeteryResource;
use App\Livewire\Public\Directory\Support\CemeteryPresenter;
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
 * Inline package/class availability rows for `CemeteryResource` — the
 * `cemetery_packages` rows backing requirements.md AC6 ("present Makam
 * Tumpang availability explicitly at the location/package/class level").
 * Bounded scope per the plan: list + inline create/edit only — there is
 * deliberately NO DeleteAction, because deletion was never in that scope.
 *
 * The package `name` is deliberately a free-text operator string, NOT a
 * closed list — `CemeteryPackage`'s own doc block explains why this module
 * must not assert a competing service-type catalogue.
 *
 * ---------------------------------------------------------------------------
 * Package/class-level pricing — added 26 Aug 2026
 * ---------------------------------------------------------------------------
 * `price_min`/`price_max`/`price_source` are admin-editable here (mirroring
 * `CemeteryForm`'s own `price_min`/`price_max` fields for the cemetery-level
 * figure). `price_currency` is NOT exposed — it defaults to `IDR` at the
 * column level, matching `CemeteryForm`'s identical choice not to expose a
 * currency picker for a launch that only ever transacts in Rupiah.
 * `price_effective_at` is NEVER a form field — `CemeteryPackage::booted()`
 * stamps it automatically whenever a priced field changes, so an admin
 * cannot hand-enter a false "as of" date. This is the FIRST real admin
 * write path for package pricing; before this, `cemetery_packages` had no
 * price columns at all.
 *
 * `price_source` is left optional, not required: `CemeteryPresenter::
 * packagePriceAttribution()` already renders an honest "Sumber tidak
 * tercatat" fallback for a blank source (the same fallback the
 * cemetery-level figure has always had), so an admin who enters a min/max
 * without a source produces a visibly-unattributed figure on the public
 * page rather than a silently-fabricated one.
 *
 * ---------------------------------------------------------------------------
 * Filament 5 shape: instance methods, not statics
 * ---------------------------------------------------------------------------
 * Unlike `FaqArticleResource`'s static `form()`/`table()`, a Filament 5
 * `RelationManager` (verified against the installed v5.7.3) declares
 * `public function form(Schema $schema)` as an INSTANCE method
 * (`InteractsWithRelationshipTable`) and configures its table by overriding
 * `protected function makeTable()` — both called on the mounted component.
 *
 * ---------------------------------------------------------------------------
 * Write path: the model, wrapped in `Audit::wrap()` — not a Domain Action
 * ---------------------------------------------------------------------------
 * No `CemeteryPackage` write Action exists in `CemeteryCapability` — the
 * design doc's "route through domain Actions WHERE THEY EXIST" rule has
 * nothing to route to here, so Filament's relationship-save path is used.
 * `CemeteryPackage::booted()`'s `saving` hook (availability-status closed
 * list assertion) still fires on every write.
 *
 * Both the CreateAction and the EditAction carry `->using()` closures that
 * wrap the model write in `Audit::wrap()` (`CemeteryPackageAuditActions
 * ::CREATED` / `::UPDATED`), so the row change and its `audit_events`
 * entry commit in the same transaction (AC4) — a package create/edit is a
 * master-data write, so it must leave the same "who changed what, when"
 * record as the `cemetery` rows themselves.
 *
 * ---------------------------------------------------------------------------
 * Authorization
 * ---------------------------------------------------------------------------
 * The embedding Edit page already enforces the resource gate
 * (`CemeteryResource::getAuthorizationResponse()`), but this relation
 * manager is itself a Livewire component addressable over the wire, so it
 * carries its own two layers — the same hardening `PriceVersionsRelation
 * Manager` documents:
 *  - `canViewForRecord()` is overridden (the base implementation resolves a
 *    policy that does not exist and fails OPEN — verified against the
 *    installed Filament 5.7.3 source) to run the master-data authorizer's
 *    try/catch -> bool instead.
 *  - both actions carry `->authorize(...)` so mounting the create/edit
 *    modal refuses an unauthorized actor at the action boundary too.
 */
final class PackagesRelationManager extends RelationManager
{
    protected static string $relationship = 'packages';

    protected static ?string $title = 'Paket Makam';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::actorMayManage();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label('Nama paket')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('class_label')
                    ->label('Kelas (opsional)')
                    ->nullable()
                    ->maxLength(255)
                    ->helperText(
                        'Kosongkan untuk baris tingkat paket (tanpa rincian kelas).'
                    ),

                Select::make('availability_status')
                    ->label('Status ketersediaan')
                    ->required()
                    ->native(false)
                    ->options(array_combine(
                        CemeteryPackageAvailabilityStatus::KNOWN_STATUSES,
                        ['Tersedia', 'Terbatas', 'Tidak tersedia'],
                    )),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),

                TextInput::make('sort_order')
                    ->label('Urutan')
                    ->numeric()
                    ->default(1)
                    ->minValue(1),

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

                TextInput::make('price_source')
                    ->label('Sumber harga')
                    ->nullable()
                    ->maxLength(255)
                    ->helperText(
                        'Contoh: "Daftar harga pengelola, Agustus 2026". Ditampilkan sebagai atribusi '
                        .'wajib di halaman publik — kosongkan hanya jika sumber benar-benar belum tercatat.'
                    )
                    ->columnSpanFull(),

                Textarea::make('description')
                    ->label('Deskripsi')
                    ->nullable()
                    ->rows(3)
                    ->maxLength(500)
                    ->columnSpanFull(),
            ]);
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name')
                    ->label('Nama paket')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('class_label')
                    ->label('Kelas')
                    ->placeholder('Paket (tanpa kelas)')
                    ->sortable(),

                TextColumn::make('availability_status')
                    ->label('Ketersediaan')
                    ->badge()
                    ->color(fn (string $state): string => self::availabilityColor($state))
                    ->formatStateUsing(fn (string $state): string => self::availabilityLabel($state))
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),

                TextColumn::make('price_min')
                    ->label('Harga')
                    ->formatStateUsing(fn (CemeteryPackage $record): string => CemeteryPresenter::packagePriceRange($record) ?? 'Belum tersedia'),

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

                        /** @var Cemetery $owner */
                        $owner = $this->getOwnerRecord();

                        return Audit::wrap(
                            fn (): CemeteryPackage => $owner->packages()->create($data),
                            action: CemeteryPackageAuditActions::CREATED,
                            subject: fn (CemeteryPackage $saved): AuditSubject => new AuditSubject(
                                type: 'cemetery_package',
                                id: (string) $saved->getKey(),
                            ),
                            outcome: AuditOutcome::Allowed,
                            actorRef: $actor->identityReference,
                            actorRole: CemeteryResource::auditRoleFor($actor),
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
                            action: CemeteryPackageAuditActions::UPDATED,
                            subject: new AuditSubject(
                                type: 'cemetery_package',
                                id: (string) $record->getKey(),
                            ),
                            outcome: AuditOutcome::Allowed,
                            actorRef: $actor->identityReference,
                            actorRole: CemeteryResource::auditRoleFor($actor),
                            source: AuditSource::Panel,
                        );
                    }),
            ]);
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

    public static function availabilityColor(string $state): string
    {
        return match ($state) {
            CemeteryPackageAvailabilityStatus::AVAILABLE => 'success',
            CemeteryPackageAvailabilityStatus::LIMITED => 'warning',
            CemeteryPackageAvailabilityStatus::UNAVAILABLE => 'danger',
            default => 'gray',
        };
    }

    public static function availabilityLabel(string $state): string
    {
        return match ($state) {
            CemeteryPackageAvailabilityStatus::AVAILABLE => 'Tersedia',
            CemeteryPackageAvailabilityStatus::LIMITED => 'Terbatas',
            CemeteryPackageAvailabilityStatus::UNAVAILABLE => 'Tidak tersedia',
            default => $state,
        };
    }
}

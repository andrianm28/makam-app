<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ProductResource\RelationManagers;

use App\Domain\Marketplace\Models\Product;
use App\Domain\Marketplace\Models\ProductVariant;
use App\Domain\Marketplace\ProductVariantAuditActions;
use App\Filament\Admin\Resources\ProductResource\ProductResource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Inline variant rows for `ProductResource` — the `product_variants` rows
 * backed by the catalogue's "Required variant attributes where applicable"
 * block (Batu Nisan only; see `ProductVariant`'s class-level doc block).
 * Bounded scope per the plan: list + inline create/edit only — there is
 * deliberately NO DeleteAction, because deletion was never in that scope.
 *
 * ---------------------------------------------------------------------------
 * Filament 5 shape: instance methods, not statics
 * ---------------------------------------------------------------------------
 * Mirrors `CemeteryResource\RelationManagers\PackagesRelationManager`
 * exactly: `public function form(Schema $schema)` as an INSTANCE method and
 * `protected function makeTable()` — the verified v5.7.3 relation-manager
 * shape (see that class's own doc block for the full rationale).
 *
 * ---------------------------------------------------------------------------
 * Write path: the model, wrapped in `Audit::wrap()` — not a Domain Action
 * ---------------------------------------------------------------------------
 * No `ProductVariant` write Action exists in `Marketplace` — the design
 * doc's "route through domain Actions WHERE THEY EXIST" rule has nothing to
 * route to here, so Filament's relationship-save path is used.
 * `ProductVariant::booted()`'s `saving` hook (the owning product must
 * `hasVariantAxes()` — `ProductCode::requiresVariants()`) still fires on
 * every write, so an admin mounting this manager on a `FLOWER_*`/
 * `GRAVE_CARE_*` product (if the UI ever offers one) is refused with the
 * model's honest `InvalidArgumentException`, not a silent partial write.
 *
 * Both the CreateAction and the EditAction carry `->using()` closures that
 * wrap the model write in `Audit::wrap()` (`ProductVariantAuditActions
 * ::CREATED` / `::UPDATED`), so the row change and its `audit_events` entry
 * commit in the same transaction (AC4).
 *
 * ---------------------------------------------------------------------------
 * Authorization
 * ---------------------------------------------------------------------------
 * The embedding Edit page already enforces the resource gate
 * (`ProductResource::getAuthorizationResponse()`), but this relation
 * manager is itself a Livewire component addressable over the wire, so it
 * carries its own two layers — the same hardening `PackagesRelationManager`
 * documents:
 *  - `canViewForRecord()` is overridden (the base implementation resolves a
 *    policy that does not exist and fails OPEN — verified against the
 *    installed Filament 5.7.3 source) to run the master-data authorizer's
 *    try/catch -> bool instead.
 *  - both actions carry `->authorize(...)` so mounting the create/edit
 *    modal refuses an unauthorized actor at the action boundary too.
 */
final class VariantsRelationManager extends RelationManager
{
    protected static string $relationship = 'variants';

    protected static ?string $title = 'Varian Produk';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::actorMayManage();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('size')
                    ->label('Ukuran')
                    ->required()
                    ->maxLength(32),

                TextInput::make('material')
                    ->label('Bahan')
                    ->required()
                    ->maxLength(64),

                TextInput::make('color')
                    ->label('Warna')
                    ->required()
                    ->maxLength(32),

                TextInput::make('calligraphy_style')
                    ->label('Gaya kaligrafi')
                    ->nullable()
                    ->maxLength(64),

                Textarea::make('inscription_text_example')
                    ->label('Contoh teks inskripsi')
                    ->nullable()
                    ->rows(3)
                    ->maxLength(1000)
                    ->helperText(
                        'Contoh ilustratif untuk pratinjau — bukan teks inskripsi pesanan.'
                    )
                    ->columnSpanFull(),

                TextInput::make('preview_image_path')
                    ->label('Gambar pratinjau (path)')
                    ->nullable()
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('sort_order')
                    ->label('Urutan')
                    ->numeric()
                    ->default(0)
                    ->minValue(0),
            ]);
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('size')
                    ->label('Ukuran')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('material')
                    ->label('Bahan')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('color')
                    ->label('Warna')
                    ->sortable(),

                TextColumn::make('calligraphy_style')
                    ->label('Gaya kaligrafi')
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('preview_image_path')
                    ->label('Gambar pratinjau')
                    ->placeholder('—'),

                TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->using(function (array $data): Model {
                        $actor = app(ActorContext::class);

                        /** @var Product $owner */
                        $owner = $this->getOwnerRecord();

                        return Audit::wrap(
                            fn (): ProductVariant => $owner->variants()->create($data),
                            action: ProductVariantAuditActions::CREATED,
                            subject: fn (ProductVariant $saved): AuditSubject => new AuditSubject(
                                type: 'product_variant',
                                id: (string) $saved->getKey(),
                            ),
                            outcome: AuditOutcome::Allowed,
                            actorRef: $actor->identityReference,
                            actorRole: ProductResource::auditRoleFor($actor),
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
                            action: ProductVariantAuditActions::UPDATED,
                            subject: new AuditSubject(
                                type: 'product_variant',
                                id: (string) $record->getKey(),
                            ),
                            outcome: AuditOutcome::Allowed,
                            actorRef: $actor->identityReference,
                            actorRole: ProductResource::auditRoleFor($actor),
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
}

<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServicePackages\RelationManagers;

use App\Domain\ServiceCatalog\FulfillmentOwner;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Domain\ServiceCatalog\Models\ServicePackage;
use App\Domain\ServiceCatalog\Models\ServicePackageItem;
use App\Domain\ServiceCatalog\Models\ServicePackageVersion;
use App\Domain\ServiceCatalog\ServiceCatalogAuditActions;
use App\Domain\ServiceCatalog\ServicePackageItemType;
use App\Filament\Admin\Resources\ServicePackages\ServicePackageResource;
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
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Throwable;

/**
 * Items of one `ServicePackage`'s CURRENT DRAFT version, mounted on the
 * package's View page.
 *
 * ---------------------------------------------------------------------------
 * Why this resolves through the draft, not a real `items` relationship
 * ---------------------------------------------------------------------------
 * A package's items live on its VERSIONS (`ServicePackageVersion::items()`),
 * and a package has at most one open draft at a time (the domain enforces
 * it). The View page's owner record is the ServicePackage, so
 * `getRelationship()` — normally `$owner->{relationship}()` — is overridden
 * to resolve the current draft version's items instead: the honest
 * Filament-5 shape of "items of the SELECTED draft version" (the plan's
 * Produces line). When no draft exists, an empty query is returned so the
 * table renders empty rather than erroring.
 *
 * ---------------------------------------------------------------------------
 * Write path: plain model writes INSIDE `Audit::wrap()`
 * ---------------------------------------------------------------------------
 * There is deliberately no create/update Domain Action for an item of an
 * open draft (DefineServicePackage takes items at define-time;
 * ReviseServicePackageVersion copies them; item-by-item editing of an open
 * draft is exactly this relation manager's job). So Create/Edit here are
 * plain model writes wrapped in `Audit::wrap()` (`SERVICE_PACKAGE_ITEM_CREATED`
 * / `SERVICE_PACKAGE_ITEM_UPDATED`) — the same AC4 seam `PackagesRelationManager`
 * documents. The MODEL enforces the real rule: `ServicePackageItem::booted()`
 * re-checks the owning version's status on every save, so a write that
 * reaches a published version throws
 * `PublishedServicePackageVersionIsImmutableException` — caught here and
 * surfaced as an honest danger notification, never a 500.
 *
 * ---------------------------------------------------------------------------
 * Authorization
 * ---------------------------------------------------------------------------
 * Same two layers as `VersionsRelationManager`: `canViewForRecord()`
 * override (base fails OPEN against a nonexistent policy) and
 * `->authorize(...)` on every action. `isReadOnly()` is overridden to
 * `false` because on the View page Filament hides relationship-modifying
 * actions by default.
 */
final class VersionItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'Item Versi Draft';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::actorMayManage();
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function getRelationship(): Relation|Builder
    {
        /** @var ServicePackage $package */
        $package = $this->getOwnerRecord();

        $draft = $package->draftVersion();

        if ($draft === null) {
            return ServicePackageItem::query()->whereRaw('1 = 0');
        }

        return $draft->items();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('service_definition_id')
                    ->label('Layanan')
                    ->options(ServiceDefinition::query()->orderBy('name')->pluck('name', 'id'))
                    ->required()
                    ->searchable(),

                Select::make('item_type')
                    ->label('Jenis item')
                    ->options([
                        ServicePackageItemType::INCLUDED => 'Termasuk',
                        ServicePackageItemType::OPTIONAL => 'Opsional',
                        ServicePackageItemType::EXCLUDED => 'Tidak termasuk',
                    ])
                    ->required()
                    ->native(false),

                TextInput::make('quantity')
                    ->label('Jumlah')
                    ->numeric()
                    ->required()
                    ->minValue(0),

                TextInput::make('unit')
                    ->label('Satuan')
                    ->required()
                    ->maxLength(32),

                Select::make('fulfillment_owner')
                    ->label('Pihak pemenuhan')
                    ->options([
                        FulfillmentOwner::PLATFORM => 'Platform',
                        FulfillmentOwner::CEMETERY_OPERATOR => 'Pengelola TPU',
                        FulfillmentOwner::VENDOR => 'Vendor',
                    ])
                    ->required()
                    ->native(false),

                Toggle::make('requires_schedule_window')
                    ->label('Perlu jendela jadwal'),

                Toggle::make('evidence_required')
                    ->label('Perlu bukti'),

                Textarea::make('notes')
                    ->label('Catatan')
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->columns([
                TextColumn::make('serviceDefinition.name')
                    ->label('Layanan')
                    ->sortable(),

                TextColumn::make('item_type')
                    ->label('Jenis item')
                    ->badge()
                    ->color(fn (string $state): string => self::itemTypeColor($state))
                    ->formatStateUsing(fn (string $state): string => self::itemTypeLabel($state)),

                TextColumn::make('quantity')
                    ->label('Jumlah'),

                TextColumn::make('unit')
                    ->label('Satuan'),

                TextColumn::make('fulfillment_owner')
                    ->label('Pihak pemenuhan')
                    ->formatStateUsing(fn (string $state): string => self::fulfillmentOwnerLabel($state)),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Tambah item')
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->successNotificationTitle(null)
                    ->action(function (array $data): void {
                        $actor = app(ActorContext::class);

                        $draft = $this->draftVersion();

                        if ($draft === null) {
                            Notification::make()
                                ->title('Item tidak dapat ditambahkan.')
                                ->body('Tidak ada versi draft yang dapat diedit.')
                                ->danger()
                                ->send();

                            return;
                        }

                        try {
                            Audit::wrap(
                                fn (): ServicePackageItem => $draft->items()->create($data),
                                action: ServiceCatalogAuditActions::SERVICE_PACKAGE_ITEM_CREATED,
                                subject: fn (ServicePackageItem $item): AuditSubject => new AuditSubject(
                                    type: 'service_package_item',
                                    id: (string) $item->getKey(),
                                ),
                                outcome: AuditOutcome::Allowed,
                                actorRef: $actor->identityReference,
                                actorRole: ServicePackageResource::auditRoleFor($actor),
                                source: AuditSource::Panel,
                            );

                            Notification::make()
                                ->title('Item ditambahkan.')
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Item tidak dapat ditambahkan.')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ])
            ->recordActions([
                EditAction::make()
                    ->label('Ubah item')
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->successNotificationTitle(null)
                    ->action(function (ServicePackageItem $record, array $data): void {
                        $actor = app(ActorContext::class);

                        try {
                            Audit::wrap(
                                fn (): ServicePackageItem => tap($record)->update($data),
                                action: ServiceCatalogAuditActions::SERVICE_PACKAGE_ITEM_UPDATED,
                                subject: new AuditSubject(
                                    type: 'service_package_item',
                                    id: (string) $record->getKey(),
                                ),
                                outcome: AuditOutcome::Allowed,
                                actorRef: $actor->identityReference,
                                actorRole: ServicePackageResource::auditRoleFor($actor),
                                source: AuditSource::Panel,
                            );

                            Notification::make()
                                ->title('Item diperbarui.')
                                ->success()
                                ->send();
                        } catch (Throwable $exception) {
                            Notification::make()
                                ->title('Item tidak dapat diperbarui.')
                                ->body($exception->getMessage())
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    private function draftVersion(): ?ServicePackageVersion
    {
        /** @var ServicePackage $package */
        $package = $this->getOwnerRecord();

        return $package->draftVersion();
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

    public static function itemTypeLabel(string $type): string
    {
        return match ($type) {
            ServicePackageItemType::INCLUDED => 'Termasuk',
            ServicePackageItemType::OPTIONAL => 'Opsional',
            ServicePackageItemType::EXCLUDED => 'Tidak termasuk',
            default => $type,
        };
    }

    public static function itemTypeColor(string $type): string
    {
        return match ($type) {
            ServicePackageItemType::INCLUDED => 'success',
            ServicePackageItemType::OPTIONAL => 'warning',
            ServicePackageItemType::EXCLUDED => 'danger',
            default => 'gray',
        };
    }

    public static function fulfillmentOwnerLabel(string $owner): string
    {
        return match ($owner) {
            FulfillmentOwner::PLATFORM => 'Platform',
            FulfillmentOwner::CEMETERY_OPERATOR => 'Pengelola TPU',
            FulfillmentOwner::VENDOR => 'Vendor',
            default => $owner,
        };
    }
}

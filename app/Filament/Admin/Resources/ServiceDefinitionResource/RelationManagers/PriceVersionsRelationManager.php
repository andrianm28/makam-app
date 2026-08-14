<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceDefinitionResource\RelationManagers;

use App\Domain\ServiceCatalog\Actions\RecordServiceDefinitionPriceVersion;
use App\Domain\ServiceCatalog\Models\ServiceDefinition;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Price history for one `ServiceDefinition` — the append-only versioning
 * contract, exposed in the admin.
 *
 * ---------------------------------------------------------------------------
 * CREATE routes through `RecordServiceDefinitionPriceVersion`, never a raw
 * insert — the CRITICAL invariant
 * ---------------------------------------------------------------------------
 * `PriceVersion` is append-only: rows are created only by the Action, and
 * its `booted()` hooks throw on any update beyond stamping `superseded_at`
 * and on any delete. The header `CreateAction` here is wired with
 * `->using(...)` so its submission calls the Action (which closes out the
 * currently-current version and inserts the next `version_number` inside one
 * transaction, and records its own `SERVICE_DEFINITION_PRICE_VERSION_RECORDED`
 * audit row — a sensitive action, hence the mandatory `reason` field in the
 * modal). Filament's default create path (`$relationship->create($data)`)
 * would bypass the Action entirely and silently produce a duplicate
 * `version_number` or an un-superseded current row — that path is never
 * reachable here.
 *
 * There is deliberately no Edit or Delete row action: `PriceVersion`'s own
 * `booted()` guards would reject both, and exposing them would be UI
 * claiming an operation the append-only contract forbids.
 *
 * ---------------------------------------------------------------------------
 * Authorization
 * ---------------------------------------------------------------------------
 * The embedding Edit page already enforces the resource gate
 * (`ServiceDefinitionResource::getAuthorizationResponse()`), but this
 * relation manager is itself a Livewire component addressable over the
 * wire, so it carries its own two layers:
 *  - `canViewForRecord()` is overridden (the base implementation resolves a
 *    policy that does not exist and fails OPEN — verified against the
 *    installed Filament 5.7.3 source) to run the master-data authorizer's
 *    try/catch -> bool instead.
 *  - the CreateAction carries `->authorize(...)` so mounting the create
 *    modal refuses an unauthorized actor at the action boundary too.
 */
final class PriceVersionsRelationManager extends RelationManager
{
    protected static string $relationship = 'priceVersions';

    protected static ?string $title = 'Riwayat Harga';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }

        return true;
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('amount')
                    ->label('Harga (IDR)')
                    ->numeric()
                    ->required()
                    // The Action's own shape assertion is the domain boundary
                    // (`/^\d{1,10}(\.\d{1,2})?$/` — the decimal(12,2) column's
                    // exact domain). Mirroring it here turns a rejected amount
                    // into a field-keyed validation error instead of the
                    // Action's InvalidArgumentException.
                    ->regex('/^\d{1,10}(\.\d{1,2})?$/'),

                Textarea::make('reason')
                    ->label('Alasan perubahan harga (wajib)')
                    ->rows(2)
                    ->required(),
            ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('version_number', 'desc')
            ->columns([
                TextColumn::make('version_number')
                    ->label('Versi')
                    ->sortable(),

                TextColumn::make('amount')
                    ->label('Harga')
                    ->money('IDR')
                    ->sortable(),

                TextColumn::make('currency')
                    ->label('Mata uang'),

                TextColumn::make('source')
                    ->label('Sumber')
                    ->placeholder('—'),

                TextColumn::make('effective_from')
                    ->label('Berlaku sejak')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('superseded_at')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (?string $state): string => $state === null ? 'Berlaku' : 'Digantikan')
                    ->color(fn (?string $state): string => $state === null ? 'success' : 'gray'),

                TextColumn::make('recorded_by')
                    ->label('Dicatat oleh')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label('Catat harga baru')
                    ->modalHeading('Catat harga baru')
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->using(function (array $data): Model {
                        $actor = app(ActorContext::class);

                        /** @var ServiceDefinition $owner */
                        $owner = $this->getOwnerRecord();

                        return (new RecordServiceDefinitionPriceVersion)(
                            serviceDefinition: $owner,
                            amount: (string) $data['amount'],
                            actorReference: $actor->identityReference ?? 0,
                            reason: (string) $data['reason'],
                            currency: 'IDR',
                            source: null,
                            actorRole: $actor->roles[0] ?? ($actor->isAuthenticated() ? 'unresolved' : 'system'),
                            auditSource: AuditSource::Panel,
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

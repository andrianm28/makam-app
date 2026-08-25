<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CemeteryResource\RelationManagers;

use App\Domain\CemeteryDirectory\Models\Cemetery;
use App\Domain\PlotInventory\Actions\CreateCemeteryBlock;
use App\Domain\PlotInventory\Models\CemeteryBlock;
use App\Filament\Admin\Resources\CemeteryResource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Inline cemetery-block rows for `CemeteryResource` — the P3 plot-inventory
 * admin surface (`docs/superpowers/specs/2026-08-16-plot-inventory-
 * reservation-design.md` §4.1). Bound scope per the plan: list + inline
 * create only — there is deliberately NO edit and NO DeleteAction: the
 * block's `capacity` and plot rows are bulk-generated together by
 * `CreateCemeteryBlock` (the ONLY way a block enters the system), so an
 * inline edit would imply editing a contract that cannot be edited, and
 * `grave_plots.block_id` is `restrictOnDelete`.
 *
 * ---------------------------------------------------------------------------
 * Filament 5 shape: instance methods, not statics
 * ---------------------------------------------------------------------------
 * Same verified pattern as the sibling `PackagesRelationManager`: `form()`
 * is an INSTANCE method and the table is configured by overriding
 * `protected function makeTable()` — both called on the mounted component.
 *
 * ---------------------------------------------------------------------------
 * Write path: the Domain Action, which self-audits — never double-wrapped
 * ---------------------------------------------------------------------------
 * The CreateAction's `->using()` closure invokes
 * `app(CreateCemeteryBlock::class)` — the action opens its own
 * `Audit::wrap()` transaction (both `CEMETERY_BLOCK_CREATED` and
 * `GRAVE_PLOTS_GENERATED` commit with the writes). The relation manager
 * does NOT wrap the call in another `Audit::wrap()` (a deliberate contrast
 * to `PackagesRelationManager`, whose model-write path has no Domain Action
 * to route through and therefore wraps itself).
 *
 * The `is_active` toggle is real: `CreateCemeteryBlock` gained an optional
 * `$isActive` parameter (Task 2, disclosed) and the `->using()` closure
 * forwards the form's value.
 *
 * ---------------------------------------------------------------------------
 * Authorization
 * ---------------------------------------------------------------------------
 * The two layers the sibling relation managers document and share:
 * `canViewForRecord()` is overridden (the base implementation resolves a
 * policy that does not exist and fails OPEN — verified against the installed
 * Filament 5.7.3 source) to run the master-data authorizer's try/catch ->
 * bool, and the create action carries `->authorize(...)` so mounting the
 * create modal refuses an unauthorized actor at the action boundary too.
 */
final class BlocksRelationManager extends RelationManager
{
    protected static string $relationship = 'blocks';

    protected static ?string $title = 'Blok Makam';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::actorMayManage();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('code')
                    ->label('Kode blok')
                    ->required()
                    ->maxLength(32)
                    ->helperText('Disimpan otomatis sebagai huruf kapital, mis. BLOK-A.')
                    ->columnSpanFull(),

                TextInput::make('name')
                    ->label('Nama blok')
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),

                TextInput::make('capacity')
                    ->label('Kapasitas (jumlah plot)')
                    ->required()
                    ->numeric()
                    ->minValue(1)
                    ->helperText('Plot dibuat otomatis dengan slot 001..N.'),

                Select::make('cemetery_package_id')
                    ->label('Paket / Kelas (opsional)')
                    ->nullable()
                    ->native(false)
                    ->options(function (): array {
                        $owner = $this->getOwnerRecord();

                        if (! $owner instanceof Cemetery) {
                            return [];
                        }

                        return $owner->packages()->pluck('name', 'id')->all();
                    }),

                Toggle::make('is_active')
                    ->label('Aktif')
                    ->default(true),
            ]);
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->defaultSort('code')
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name')
                    ->label('Nama blok')
                    ->searchable(),

                TextColumn::make('capacity')
                    ->label('Kapasitas')
                    ->sortable(),

                TextColumn::make('plots_count')
                    ->label('Plot terdaftar')
                    ->counts('plots'),

                IconColumn::make('is_active')
                    ->label('Aktif')
                    ->boolean()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->using(function (array $data): CemeteryBlock {
                        $actor = app(ActorContext::class);

                        /** @var Cemetery $owner */
                        $owner = $this->getOwnerRecord();

                        return app(CreateCemeteryBlock::class)(
                            $owner,
                            (string) $data['code'],
                            (string) $data['name'],
                            (int) $data['capacity'],
                            $actor->identityReference ?? 0,
                            CemeteryResource::auditRoleFor($actor),
                            isset($data['cemetery_package_id']) && $data['cemetery_package_id'] !== null
                                ? (int) $data['cemetery_package_id']
                                : null,
                            isActive: (bool) ($data['is_active'] ?? true),
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

<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Vendors\RelationManagers;

use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorAvailability;
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
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Schedule rows (`vendor_availability`) for `VendorResource` — a vendor's
 * per-day calendar: how much capacity is open on a given date, or whether
 * the date is blocked outright. Gives `AvailabilityMode::SCHEDULED`
 * listings somewhere to book against.
 *
 * ---------------------------------------------------------------------------
 * Blocked days can never advertise capacity
 * ---------------------------------------------------------------------------
 * The migration enforces `(is_blocked = false) OR (capacity = 0)` at the
 * database (pgsql CHECK), so the form keeps the two fields honest: a day
 * marked blocked zeroes its capacity before save, and a capacity edit on a
 * blocked day is refused until the day is unblocked — the same
 * self-contradiction rule the migration's doc block states.
 *
 * ---------------------------------------------------------------------------
 * Write path + authorization (the `PackagesRelationManager` pattern)
 * ---------------------------------------------------------------------------
 * No `VendorAvailability` write Action exists, so Filament's
 * relationship-save path is used, with both actions carrying
 * `->authorize(...)` and every write wrapped in `Audit::wrap()`
 * (`VendorAuditActions::AVAILABILITY_CREATED` / `::AVAILABILITY_UPDATED`)
 * so the row change and its `audit_events` entry commit in the same
 * transaction (AC4). Deletion is deliberately NOT offered — the P2 spec
 * scopes this manager to list + inline create/edit, and a day row is a
 * booking ledger, not free-form content.
 */
final class AvailabilityRelationManager extends RelationManager
{
    protected static string $relationship = 'availability';

    protected static ?string $title = 'Jadwal Ketersediaan';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::actorMayManage();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                DatePicker::make('available_date')
                    ->label('Tanggal')
                    ->required(),

                TextInput::make('capacity')
                    ->label('Kapasitas')
                    ->numeric()
                    ->minValue(0)
                    ->default(0)
                    ->disabled(fn (?VendorAvailability $record): bool => $record?->is_blocked ?? false),

                Toggle::make('is_blocked')
                    ->label('Tanggal diblokir')
                    ->live()
                    ->afterStateUpdated(function (callable $set, bool $state): void {
                        // A blocked day can never advertise capacity.
                        if ($state) {
                            $set('capacity', 0);
                        }
                    })
                    ->helperText(
                        'Hari yang diblokir tidak menerima layanan; kapasitasnya '
                        .'otomatis dianggap 0.'
                    ),
            ]);
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->defaultSort('available_date')
            ->columns([
                TextColumn::make('available_date')
                    ->label('Tanggal')
                    ->date()
                    ->sortable(),

                TextColumn::make('capacity')
                    ->label('Kapasitas')
                    ->numeric()
                    ->sortable(),

                IconColumn::make('is_blocked')
                    ->label('Diblokir')
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
                            fn (): VendorAvailability => $owner->availability()->create($data),
                            action: VendorAuditActions::AVAILABILITY_CREATED,
                            subject: fn (VendorAvailability $saved): AuditSubject => new AuditSubject(
                                type: 'vendor_availability',
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
                            action: VendorAuditActions::AVAILABILITY_UPDATED,
                            subject: new AuditSubject(
                                type: 'vendor_availability',
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

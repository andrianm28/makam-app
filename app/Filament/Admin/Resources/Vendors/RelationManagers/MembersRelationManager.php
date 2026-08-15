<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Vendors\RelationManagers;

use App\Domain\Marketplace\Models\Vendor;
use App\Domain\Marketplace\Models\VendorUser;
use App\Domain\Marketplace\VendorAuditActions;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/**
 * Membership rows (`vendor_users`) for `VendorResource` — add/revoke only.
 *
 * `vendor_users` is MEMBERSHIP METADATA ONLY (the migration's own doc
 * block): `scope_assignments` with `entity_type = 'vendor'` is the single
 * authority on whether an actor may act for a vendor, and this table must
 * never be read as one. This relation manager only records which actors a
 * vendor's panel operations concern — adding a member here grants nothing
 * by itself.
 *
 * ---------------------------------------------------------------------------
 * Add = create, revoke = set `revoked_at`. There is deliberately NO delete
 * ---------------------------------------------------------------------------
 * A membership row is a durable record: revoking sets `revoked_at` and
 * leaves the row in place (the same soft-state discipline the identity
 * module applies to role assignments). The table lists ACTIVE members only
 * (`revoked_at IS NULL`); the list shrinks when a member is revoked, the
 * row does not disappear from the database.
 *
 * ---------------------------------------------------------------------------
 * Authorization + write path (the `PackagesRelationManager` pattern)
 * ---------------------------------------------------------------------------
 * `canViewForRecord()` is overridden to run the master-data authorizer's
 * try/catch -> bool (the base implementation fails OPEN without a policy),
 * and both actions carry `->authorize(...)`. Every write is wrapped in
 * `Audit::wrap()` (`VendorAuditActions::MEMBER_ADDED` / `::MEMBER_REVOKED`),
 * so the row change and its `audit_events` entry commit in the same
 * transaction (AC4).
 */
final class MembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    protected static ?string $title = 'Anggota';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::actorMayManage();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('actor_identifier')
                    ->label('Akun anggota')
                    ->required()
                    ->maxLength(255)
                    ->helperText(
                        'Identitas akun yang dicatat sebagai anggota vendor (mis. ID pengguna). '
                        .'Catatan keanggotaan; otorisasi tetap ditentukan scope_assignments.'
                    )
                    ->unique(
                        table: 'vendor_users',
                        column: 'actor_identifier',
                        modifyRuleUsing: fn (Unique $rule): Unique => $rule->where(
                            'vendor_id',
                            $this->getOwnerRecord()->getKey(),
                        ),
                    ),
            ]);
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            // Active members only — a revoked member is a historical row,
            // not a current listing.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('revoked_at'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('actor_identifier')
                    ->label('Akun anggota')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->label('Ditambahkan')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color('success')
                    // Computed presentation only — the table query above
                    // guarantees every listed row is an active member.
                    ->getStateUsing(fn (): string => 'active')
                    ->formatStateUsing(fn (string $state): string => $state === 'active' ? 'Aktif' : $state),
            ])
            ->headerActions([
                CreateAction::make('add')
                    ->label('Tambah anggota')
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->using(function (array $data): Model {
                        $actor = app(ActorContext::class);

                        /** @var Vendor $owner */
                        $owner = $this->getOwnerRecord();

                        return Audit::wrap(
                            fn (): VendorUser => $owner->members()->create($data),
                            action: VendorAuditActions::MEMBER_ADDED,
                            subject: fn (VendorUser $saved): AuditSubject => new AuditSubject(
                                type: 'vendor_user',
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
                Action::make('revoke')
                    ->label('Cabut keanggotaan')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cabut keanggotaan?')
                    ->modalDescription(
                        'Anggota tidak lagi terdaftar pada vendor ini. Baris '
                        .'keanggotaan tetap tersimpan sebagai riwayat.'
                    )
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->action(function (VendorUser $record): void {
                        $actor = app(ActorContext::class);

                        Audit::wrap(
                            fn (): bool => $record->update(['revoked_at' => now()]),
                            action: VendorAuditActions::MEMBER_REVOKED,
                            subject: new AuditSubject(
                                type: 'vendor_user',
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

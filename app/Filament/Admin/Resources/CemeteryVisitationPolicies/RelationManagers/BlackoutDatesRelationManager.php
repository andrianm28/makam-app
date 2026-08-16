<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CemeteryVisitationPolicies\RelationManagers;

use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Domain\Visitation\Models\VisitationBlackoutDate;
use App\Domain\Visitation\VisitationAuditActions;
use App\Filament\Admin\Resources\CemeteryVisitationPolicies\CemeteryVisitationPolicyResource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * The blackout dates of one visitation policy, rendered inline on the
 * policy's edit page — the dates the policy is closed, each with the
 * visitor-visible reason the public calendar surfaces.
 *
 * ---------------------------------------------------------------------------
 * Required reason, audited writes
 * ---------------------------------------------------------------------------
 * The create form makes `reason` required AND the model's `saving` guard
 * re-asserts non-blank on the same write, so a blackout date can never
 * exist without its reason. Create and delete both commit through
 * `Audit::wrap` (`VISITATION_BLACKOUT_CREATED` / `VISITATION_BLACKOUT_
 * DELETED`) in `->using()`, so the row change and its audit record can
 * never be separated; the model guard's `InvalidArgumentException`
 * (unreachable from the form, present for non-form writers) surfaces as a
 * danger notification instead of a 500.
 *
 * Authorization: the sibling relation managers' two layers —
 * `canViewForRecord()` overridden to run the master-data authorizer (the
 * base implementation would resolve a policy that does not exist and fail
 * OPEN), and the two actions carry `->authorize(...)` so an unauthorized
 * actor is refused at the action boundary too.
 */
final class BlackoutDatesRelationManager extends RelationManager
{
    protected static string $relationship = 'blackoutDates';

    protected static ?string $title = 'Tanggal Tutup (Blackout)';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return self::actorMayManage();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            DatePicker::make('date')
                ->label('Tanggal')
                ->required()
                ->helperText('Makam tutup pada tanggal ini.'),
            Textarea::make('reason')
                ->label('Alasan')
                ->required()
                ->maxLength(500)
                ->rows(3)
                ->helperText('Alasan ini ditampilkan kepada pengunjung di kalender publik.')
                ->columnSpanFull(),
        ]);
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->defaultSort('date')
            ->columns([
                TextColumn::make('date')
                    ->label('Tanggal')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('reason')
                    ->label('Alasan')
                    ->searchable()
                    ->wrap(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->using(function (array $data): VisitationBlackoutDate {
                        $actor = app(ActorContext::class);

                        /** @var CemeteryVisitationPolicy $owner */
                        $owner = $this->getOwnerRecord();

                        return Audit::wrap(
                            mutation: fn (): VisitationBlackoutDate => $owner->blackoutDates()->create($data),
                            action: VisitationAuditActions::VISITATION_BLACKOUT_CREATED,
                            subject: fn (VisitationBlackoutDate $blackout): AuditSubject => new AuditSubject(
                                'visitation_blackout_date',
                                (string) $blackout->getKey(),
                            ),
                            outcome: AuditOutcome::Allowed,
                            actorRef: $actor->identityReference,
                            actorRole: CemeteryVisitationPolicyResource::auditRoleFor($actor),
                            source: AuditSource::Panel,
                            reason: sprintf('Tanggal tutup ditambahkan: %s.', $data['date']),
                        );
                    }),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->requiresConfirmation()
                    ->modalHeading('Hapus tanggal tutup?')
                    ->modalDescription('Tanggal akan kembali tersedia untuk kunjungan. Penghapusan dicatat di audit.')
                    ->using(function (VisitationBlackoutDate $record): VisitationBlackoutDate {
                        $actor = app(ActorContext::class);

                        Audit::wrap(
                            mutation: fn (): bool => (bool) $record->delete(),
                            action: VisitationAuditActions::VISITATION_BLACKOUT_DELETED,
                            subject: new AuditSubject('visitation_blackout_date', (string) $record->getKey()),
                            outcome: AuditOutcome::Allowed,
                            actorRef: $actor->identityReference,
                            actorRole: CemeteryVisitationPolicyResource::auditRoleFor($actor),
                            source: AuditSource::Panel,
                            reason: sprintf('Tanggal tutup dihapus: %s.', $record->date->toDateString()),
                        );

                        return $record;
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

<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MemorialProfiles\RelationManagers;

use App\Domain\Memorial\Actions\GrantMemorialEditor;
use App\Domain\Memorial\Models\MemorialEditor;
use App\Filament\Admin\Resources\MemorialProfiles\MemorialProfileResource;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * `memorial_editors` for `MemorialProfileResource` — grant/revoke only
 * (`.kiro/specs/memorial-and-qr/requirements.md` AC1: editor access
 * requires authority/consent evidence; the plan's Task 4 brief: "grant
 * with a required consent_evidence_ref text field (document-vault ref),
 * revoke").
 *
 * The `add` action runs `GrantMemorialEditor`, which refuses a blank
 * `consent_evidence_ref` BEFORE any row is written — the form's `required`
 * rule is the boundary, the action's `MemorialConsentMissingException` is
 * the backstop (tested both ways). The `revoke` action mutates the row
 * (`revoked_at` set, row kept — the audit trail stays intact).
 *
 * The table lists ACTIVE editors only; revoked rows are historical.
 */
final class EditorsRelationManager extends RelationManager
{
    protected static string $relationship = 'editors';

    protected static ?string $title = 'Editor keluarga';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return MemorialProfileResource::canAccess();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('actor_id')
                    ->label('Referensi akun editor')
                    ->required()
                    ->maxLength(255)
                    ->helperText(
                        'Referensi identitas keluarga yang diberi akses (mis. ID pengguna).'
                    ),
                TextInput::make('consent_evidence_ref')
                    ->label('Referensi bukti persetujuan (vault dokumen)')
                    ->required()
                    ->maxLength(255)
                    ->helperText(
                        'ID dokumen vault (documents.id) yang merekam persetujuan/otoritas. '
                        .'Wajib diisi — tanpa bukti, pemberian akses ditolak (AC1).'
                    ),
            ]);
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->whereNull('revoked_at'))
            ->defaultSort('granted_at', 'desc')
            ->columns([
                TextColumn::make('actor_id')
                    ->label('Referensi akun')
                    ->searchable(),

                TextColumn::make('consent_evidence_ref')
                    ->label('Bukti persetujuan')
                    ->copyable()
                    ->limit(24),

                TextColumn::make('granted_at')
                    ->label('Diberi akses')
                    ->dateTime()
                    ->sortable(),
            ])
            ->headerActions([
                CreateAction::make('add')
                    ->label('Beri akses editor')
                    ->authorize(fn (): bool => MemorialProfileResource::canAccess())
                    ->using(function (array $data): Model {
                        $actor = app(ActorContext::class);

                        return app(GrantMemorialEditor::class)(
                            $this->getOwnerRecord(),
                            (string) $data['actor_id'],
                            (string) $data['consent_evidence_ref'],
                            $actor->identityReference ?? 'anonymous',
                            MemorialProfileResource::auditRoleFor($actor),
                        );
                    }),
            ])
            ->recordActions([
                Action::make('revoke')
                    ->label('Cabut akses')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->modalHeading('Cabut akses editor?')
                    ->modalDescription(
                        'Keluarga ini tidak lagi dapat mengelola memorial. '
                        .'Baris riwayat tetap tersimpan.'
                    )
                    ->authorize(fn (): bool => MemorialProfileResource::canAccess())
                    ->action(function (MemorialEditor $record): void {
                        $record->revoke();
                    }),
            ]);
    }
}

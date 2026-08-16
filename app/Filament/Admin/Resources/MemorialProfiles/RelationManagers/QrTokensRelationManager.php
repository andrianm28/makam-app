<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MemorialProfiles\RelationManagers;

use App\Domain\Memorial\Actions\RotateMemorialQrToken;
use App\Domain\Memorial\MemorialQrImage;
use App\Domain\Memorial\Models\MemorialQrToken;
use App\Filament\Admin\Resources\MemorialProfiles\MemorialProfileResource;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * `memorial_qr_tokens` for `MemorialProfileResource` — issue/rotate + the
 * QR image display (`.kiro/specs/memorial-and-qr/requirements.md` AC4/AC5;
 * the plan's Task 4 brief).
 *
 * `rotate` runs `RotateMemorialQrToken` (revoke-in-place + mint, audited,
 * outboxed) — the partial-unique active-token index guarantees one active
 * token per profile, so rotation is the ONLY issuance path here. The QR
 * SVG renders inline for the active token only (the token URL is
 * `route('memorial.show', $token)` — the scan target).
 */
final class QrTokensRelationManager extends RelationManager
{
    protected static string $relationship = 'qrTokens';

    protected static ?string $title = 'Kode QR';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return MemorialProfileResource::canAccess();
    }

    protected function makeTable(): Table
    {
        return parent::makeTable()
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('qr_image')
                    ->label('Kode QR')
                    ->state(fn (MemorialQrToken $record): ?string => $record->isActive()
                        ? app(MemorialQrImage::class)->svg(route('memorial.show', ['token' => $record->token]))
                        : null)
                    ->formatStateUsing(fn (?string $state): HtmlString|string => $state !== null
                        ? new HtmlString($state)
                        : '—'),

                TextColumn::make('token')
                    ->label('Token')
                    ->copyable()
                    ->wrap()
                    ->fontFamily('mono'),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->getStateUsing(fn (MemorialQrToken $record): string => $record->isActive() ? 'active' : 'revoked')
                    ->formatStateUsing(fn (string $state): string => $state === 'active' ? 'Aktif' : 'Dicabut')
                    ->color(fn (string $state): string => $state === 'active' ? 'success' : 'gray'),

                TextColumn::make('created_at')
                    ->label('Diterbitkan')
                    ->dateTime()
                    ->sortable(),

                TextColumn::make('revoked_at')
                    ->label('Dicabut')
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->headerActions([
                Action::make('rotate')
                    ->label('Terbitkan kode QR baru')
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->modalHeading('Terbitkan kode QR baru?')
                    ->modalDescription(
                        'Kode QR yang lama langsung berhenti berlaku; '
                        .'kode yang sudah dicetak tidak lagi membuka halaman ini.'
                    )
                    ->authorize(fn (): bool => MemorialProfileResource::canAccess())
                    ->action(function (): void {
                        $actor = app(ActorContext::class);

                        app(RotateMemorialQrToken::class)(
                            $this->getOwnerRecord(),
                            $actor->identityReference ?? 'anonymous',
                            MemorialProfileResource::auditRoleFor($actor),
                        );
                    }),
            ]);
    }
}

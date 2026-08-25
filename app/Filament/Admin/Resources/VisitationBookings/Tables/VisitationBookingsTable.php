<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VisitationBookings\Tables;

use App\Domain\Visitation\Actions\ChangeVisitationBookingStatus;
use App\Domain\Visitation\Models\VisitationBooking;
use App\Domain\Visitation\VisitationBookingStatus;
use App\Filament\Admin\Resources\VisitationBookings\VisitationBookingsResource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use InvalidArgumentException;

/**
 * List-page table for `VisitationBookingsResource`: reference, cemetery,
 * visit date, visitor count, contact, and the status badge, filterable by
 * status and cemetery. Row actions: confirm / cancel / no-show, all via
 * `ChangeVisitationBookingStatus` (audit `VISITATION_STATUS_CHANGED` +
 * outbox `visit.booking_confirmed.v1` on confirm).
 *
 * ---------------------------------------------------------------------------
 * The three actions — one matrix, three enforcement layers
 * ---------------------------------------------------------------------------
 * Each action's allowed from-states come from
 * `ChangeVisitationBookingStatus::allowedFrom()` — the SAME matrix the
 * action itself enforces — and are used BOTH by the `->visible()`
 * closures (render-time meaning) and by the run-time re-read in
 * `transitionStatus()` (wire-call enforcement), so the two cannot drift
 * (finding I2).
 *
 * 1. `->authorize(...)` — the render/mount gate (master-data authorizer).
 * 2. `->visible(...)` — per-record status meaning. NOT a security
 *    property: Filament's mount re-checks authorization but not
 *    visibility, so a hidden action is still wire-addressable.
 * 3. `transitionStatus()`'s `fresh()` re-read + matrix check — the
 *    enforcement: a wire call against a record whose status moved since
 *    the page rendered is refused with a danger notification and no
 *    write, and the domain action re-asserts the matrix against the
 *    database's current status regardless (an `InvalidArgumentException`
 *    there — e.g. a race between the re-read and the write — also lands
 *    as a danger notification, never a 500).
 *
 * Scoping: the actions' records come from
 * `VisitationBookingsResource::getEloquentQuery()`, so a cemetery-granted
 * operator physically cannot mount an action on another cemetery's
 * booking.
 */
final class VisitationBookingsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')
                    ->label('Referensi')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('cemetery.name')
                    ->label('Makam')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('visit_date')
                    ->label('Tanggal kunjungan')
                    ->date('d M Y')
                    ->sortable(),

                TextColumn::make('visitor_count')
                    ->label('Pengunjung')
                    ->sortable(),

                TextColumn::make('contact_phone')
                    ->label('Kontak')
                    ->description(fn (VisitationBooking $record): ?string => $record->contact_email),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => self::statusColor($state))
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(array_combine(
                        VisitationBookingStatus::KNOWN_STATUSES,
                        array_map(
                            fn (string $state): string => self::statusLabel($state),
                            VisitationBookingStatus::KNOWN_STATUSES,
                        ),
                    )),

                SelectFilter::make('cemetery')
                    ->label('Makam')
                    ->relationship('cemetery', 'name'),
            ])
            ->recordActions([
                Action::make('confirm')
                    ->label('Konfirmasi')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Konfirmasi kunjungan')
                    ->modalDescription('Kunjungan dikonfirmasi dan permintaan serta kejadian konfirmasi dicatat di audit.')
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->visible(fn (VisitationBooking $record): bool => in_array(
                        (string) $record->status,
                        ChangeVisitationBookingStatus::allowedFrom(VisitationBookingStatus::CONFIRMED),
                        true,
                    ))
                    ->action(function (VisitationBooking $record): void {
                        self::transitionStatus($record, VisitationBookingStatus::CONFIRMED);
                    }),

                Action::make('cancel')
                    ->label('Batalkan')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Batalkan kunjungan')
                    ->modalDescription('Permintaan kunjungan ini dibatalkan. Pembatalan dicatat di audit.')
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->visible(fn (VisitationBooking $record): bool => in_array(
                        (string) $record->status,
                        ChangeVisitationBookingStatus::allowedFrom(VisitationBookingStatus::CANCELLED),
                        true,
                    ))
                    ->action(function (VisitationBooking $record): void {
                        self::transitionStatus($record, VisitationBookingStatus::CANCELLED);
                    }),

                Action::make('no_show')
                    ->label('Tandai Tidak Hadir')
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalHeading('Tandai tidak hadir')
                    ->modalDescription('Pengunjung tidak hadir pada tanggal kunjungan. Perubahan dicatat di audit.')
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->visible(fn (VisitationBooking $record): bool => in_array(
                        (string) $record->status,
                        ChangeVisitationBookingStatus::allowedFrom(VisitationBookingStatus::NO_SHOW),
                        true,
                    ))
                    ->action(function (VisitationBooking $record): void {
                        self::transitionStatus($record, VisitationBookingStatus::NO_SHOW);
                    }),
            ]);
    }

    public static function statusColor(string $state): string
    {
        return match ($state) {
            VisitationBookingStatus::REQUESTED => 'warning',
            VisitationBookingStatus::CONFIRMED => 'success',
            VisitationBookingStatus::CANCELLED => 'gray',
            VisitationBookingStatus::NO_SHOW => 'gray',
            default => 'gray',
        };
    }

    public static function statusLabel(string $state): string
    {
        return match ($state) {
            VisitationBookingStatus::REQUESTED => 'Diminta',
            VisitationBookingStatus::CONFIRMED => 'Dikonfirmasi',
            VisitationBookingStatus::CANCELLED => 'Dibatalkan',
            VisitationBookingStatus::NO_SHOW => 'Tidak hadir',
            default => $state,
        };
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

    /**
     * The shared write path for the three transitions. The run path
     * re-reads the record (`fresh()`) BEFORE the write and refuses when
     * its CURRENT status is not in the target's from-set — `->visible()`
     * is not re-checked by Filament's mount, so a wire call against a
     * stale view would otherwise transition a booking whose real status
     * moved (finding I2). The domain action then re-asserts the same
     * matrix against the database inside its own transaction; an
     * `InvalidArgumentException` there surfaces as a danger notification,
     * never a 500.
     */
    private static function transitionStatus(VisitationBooking $record, string $to): void
    {
        $fresh = $record->fresh() ?? $record;
        $from = (string) $fresh->status;

        if (! in_array($from, ChangeVisitationBookingStatus::allowedFrom($to), true)) {
            Notification::make()
                ->title('Status kunjungan tidak dapat diubah.')
                ->body('Status permintaan saat ini tidak mengizinkan tindakan ini; tidak ada perubahan yang ditulis.')
                ->danger()
                ->send();

            return;
        }

        try {
            $actor = app(ActorContext::class);

            app(ChangeVisitationBookingStatus::class)(
                $fresh,
                $to,
                $actor->identityReference ?? 0,
                VisitationBookingsResource::auditRoleFor($actor),
                reason: sprintf(
                    'Operator: status permintaan %s %s → %s.',
                    $fresh->reference,
                    $from,
                    $to,
                ),
            );
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title('Status kunjungan tidak dapat diubah.')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(sprintf('Kunjungan %s.', VisitationBookingsTable::statusLabel($to)))
            ->success()
            ->send();
    }
}

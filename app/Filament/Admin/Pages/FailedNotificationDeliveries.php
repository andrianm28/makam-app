<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Platform\Notification\DeliveryState;
use App\Platform\Notification\Models\NotificationDelivery;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * `/admin/notifikasi-gagal` — NOTIF-06
 * (`docs/superpowers/plans/2026-09-07-batchm8b-notification-completeness.md`).
 *
 * Before this page, a permanently-failed `notification_deliveries` row was
 * completely invisible: no operator surface, no alert anywhere in the
 * admin panel, nothing. `SpineWatchdogCommand`'s new fourth signal alerts
 * on the same condition into the error-tracking pipeline; this page is
 * where an operator who received (or is chasing down) that alert actually
 * looks. Read-only by design — retrying a delivery stays
 * `Jobs\RetryFailedDeliveryJob`'s job alone: `Actions\DispatchNotification`
 * is documented as the ONE write API for `notification_deliveries`
 * (AC9, enforced at the connection level by
 * `NotificationDeliveryWriteGuard`), and a page-triggered manual retry
 * would be a second, undocumented write path alongside it. This page never
 * writes to that table.
 *
 * `failure_message` is deliberately NOT rendered as a raw column — see
 * `delivery-state-chip.blade.php`'s own doc block: it "may carry a
 * provider-controlled error code and must not reach the UI verbatim." The
 * state column below reuses `DeliveryState::presentation()` exactly the
 * way that partial does, rather than adding a second, looser rendering of
 * the same restricted field.
 *
 * A plain `Filament\Pages\Page`, not a Resource — same reasoning
 * `InAppNotifications`' own doc block gives: a read-only list with no
 * create/edit/delete is not a CRUD surface a Resource would model.
 */
class FailedNotificationDeliveries extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'Notifikasi Gagal';

    protected static ?string $title = 'Notifikasi Gagal';

    protected static ?string $slug = 'notifikasi-gagal';

    /**
     * Terminal-bad states this page surfaces — FAILED (retry exhausted) and
     * UNAVAILABLE (never attempted, e.g. the WA gate closed, a missing
     * template version, or NOTIF-07's LOG-channel-only outcome). Both are
     * "invisible today" per the finding; QUEUED/SENT/DELIVERED are healthy
     * or in-flight states with no operator action to take, so they are
     * deliberately excluded from the default view (the state filter below
     * still lets an operator widen it).
     *
     * @var list<string>
     */
    private const array TERMINAL_BAD_STATES = [
        DeliveryState::Failed->value,
        DeliveryState::Unavailable->value,
    ];

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    /**
     * @return Builder<NotificationDelivery>
     */
    protected function getTableQuery(): Builder
    {
        return NotificationDelivery::query()->whereIn('state', self::TERMINAL_BAD_STATES);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->getTableQuery())
            ->columns([
                TextColumn::make('event_id')
                    ->label('ID Event')
                    ->limit(8)
                    ->copyable()
                    ->searchable(),

                TextColumn::make('recipient_ref')
                    ->label('Referensi Penerima')
                    ->searchable(),

                TextColumn::make('channel')
                    ->label('Kanal')
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'EMAIL' => 'Email',
                        'WA' => 'WhatsApp',
                        default => $state,
                    }),

                TextColumn::make('state')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (NotificationDelivery $record): string => $record->state
                        ->presentation($record->failure_message)['label'])
                    ->color(fn (NotificationDelivery $record): string => match ($record->state
                        ->presentation($record->failure_message)['intent']) {
                        'danger' => 'danger',
                        'neutral' => 'gray',
                        default => 'warning',
                    }),

                TextColumn::make('attempt_count')
                    ->label('Percobaan'),

                TextColumn::make('created_at')
                    ->label('Dibuat')
                    ->dateTime('d M Y H:i')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Terakhir Diperbarui')
                    ->dateTime('d M Y H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('state')
                    ->label('Status')
                    ->options([
                        DeliveryState::Failed->value => 'Gagal mengirim',
                        DeliveryState::Unavailable->value => 'Tidak tersedia',
                    ]),
                SelectFilter::make('channel')
                    ->label('Kanal')
                    ->options([
                        'EMAIL' => 'Email',
                        'WA' => 'WhatsApp',
                    ]),
            ])
            ->emptyStateHeading('Tidak ada notifikasi gagal')
            ->emptyStateDescription('Belum ada pengiriman notifikasi yang gagal atau tidak tersedia.')
            ->emptyStateIcon(Heroicon::OutlinedExclamationTriangle)
            ->defaultSort('updated_at', 'desc');
    }
}

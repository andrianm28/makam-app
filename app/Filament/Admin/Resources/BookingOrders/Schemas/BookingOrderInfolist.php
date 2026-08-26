<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Schemas;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderDocument;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\Models\QuoteLine;
use App\Domain\Quotation\QuoteStatus;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderProductTypeLabel;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderStatusBadge;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\RepeatableEntry\TableColumn;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

/**
 * Read-only view-page schema for `BookingOrderResource` — the six sections
 * the plan's Task 4 brief names (Ringkasan, Pemesan & Almarhum, Penawaran,
 * Dokumen, Riwayat Status, Otorisasi Pembayaran) plus the P3 'Reservasi'
 * section for the order's active plot reservation (Task 5).
 *
 * ---------------------------------------------------------------------------
 * Relationship-name verification (brief's `orderDocuments` did not exist)
 * ---------------------------------------------------------------------------
 * The brief assumed an `orderDocuments` relation on `Order`; the real model
 * (`App\Domain\OrderWorkflow\Models\Order`) defines only `bookingDraft()`
 * and `statusEvents()` — there is no documents relation. Rather than
 * widening the shared, append-only-guarded `Order` model from this lane
 * (the parallel Lane A owns that file), the Dokumen section reads
 * `OrderDocument` rows for the record directly through a `->state()` closure
 * and renders `document.original_filename` per attachment. Same shape the
 * Otorisasi Pembayaran section uses for `ScopeAssignment` rows — a read
 * that needs no model change.
 *
 * `statusEvents` and `bookingDraft` below ARE real relations, resolved by
 * name exactly as Filament resolves any dotted path.
 */
final class BookingOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Ringkasan')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reference')->label('Referensi'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => BookingOrderStatusBadge::color(OrderStatus::from($state)))
                            ->formatStateUsing(fn (string $state): string => BookingOrderStatusBadge::label(OrderStatus::from($state))),

                        TextEntry::make('product_type')
                            ->label('Jenis Layanan')
                            ->formatStateUsing(fn (string $state): string => BookingOrderProductTypeLabel::label(ProductType::from($state))),

                        TextEntry::make('created_at')->label('Dibuat')->dateTime(),
                    ]),

                Section::make('Pemesan & Almarhum')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('bookingDraft.customer_full_name')
                            ->label('Nama Pemesan')
                            ->placeholder('Tidak ada draft terkait.'),
                        TextEntry::make('bookingDraft.customer_mobile')
                            ->label('Telepon Pemesan')
                            ->placeholder('Tidak ada draft terkait.'),
                        TextEntry::make('bookingDraft.customer_email')
                            ->label('Email Pemesan')
                            ->placeholder('Tidak ada draft terkait.'),
                        TextEntry::make('bookingDraft.deceased_full_name')
                            ->label('Nama Almarhum/Almarhumah')
                            ->placeholder('Tidak ada draft terkait.'),
                        TextEntry::make('bookingDraft.deceased_date_of_death')
                            ->label('Tanggal Wafat')
                            ->date()
                            ->placeholder('Tidak ada draft terkait.'),
                    ]),

                Section::make('Penawaran')
                    ->columns(2)
                    ->schema([
                        RepeatableEntry::make('quoteLines')
                            ->label('Rincian penawaran')
                            ->columnSpanFull()
                            ->state(fn (Order $record): Collection => self::quoteLines($record))
                            ->placeholder('Belum ada penawaran.')
                            // UI-audit fix (26 Aug 2026): a real table layout
                            // (`RepeatableEntry::table()`, confirmed against
                            // the installed `filament/filament` v5.7.3 —
                            // `vendor/filament/infolists/src/Components/
                            // RepeatableEntry.php`'s `toEmbeddedTableHtml()`)
                            // instead of the default one-stacked-card-per-line
                            // layout, which read as vertically heavy at 1440px
                            // desktop width. `TableColumn` entries below are
                            // positional, not label-matched — they map onto
                            // the `->schema([...])` components in the same
                            // order, so that array's order must stay in sync
                            // with this one.
                            ->table([
                                TableColumn::make('Layanan'),
                                TableColumn::make('Jumlah')->alignEnd(),
                                TableColumn::make('Harga satuan')->alignEnd(),
                                TableColumn::make('Subtotal')->alignEnd(),
                            ])
                            ->schema([
                                TextEntry::make('description')->label('Layanan'),
                                TextEntry::make('quantity')->label('Jumlah'),
                                TextEntry::make('unit_amount_minor')
                                    ->label('Harga satuan')
                                    ->formatStateUsing(fn (int $state): string => self::moneyString($state)),
                                TextEntry::make('line_total_minor')
                                    ->label('Subtotal')
                                    ->formatStateUsing(fn (int $state): string => self::moneyString($state)),
                            ]),

                        TextEntry::make('quote')
                            ->label('Total Penawaran')
                            ->columnSpanFull()
                            ->state(function (Order $record): string {
                                $quote = Quote::currentFor($record);

                                if ($quote === null) {
                                    return 'Belum ada penawaran';
                                }

                                return self::moneyString($quote->totalMinor()->toMinorInt()).' · '.self::quoteStatusLabel(QuoteStatus::from($quote->status));
                            }),
                    ]),

                Section::make('Dokumen')
                    ->columns(2)
                    ->schema([
                        RepeatableEntry::make('orderDocuments')
                            ->label('Dokumen terlampir')
                            ->state(fn (Order $record): Collection => OrderDocument::query()
                                ->where('order_id', $record->getKey())
                                ->with('document')
                                ->orderByDesc('attached_at')
                                ->get())
                            ->placeholder('Belum ada dokumen terlampir.')
                            ->schema([
                                TextEntry::make('document.original_filename')->label('Berkas'),
                                TextEntry::make('document.document_kind')->label('Jenis'),
                                TextEntry::make('attached_at')->label('Dilampirkan')->dateTime(),
                            ]),
                    ]),

                Section::make('Riwayat Status')
                    ->columns(2)
                    ->schema([
                        RepeatableEntry::make('statusEvents')
                            ->label('Riwayat transisi')
                            ->schema([
                                TextEntry::make('to_status')
                                    ->label('Status')
                                    ->badge()
                                    ->color(fn (string $state): string => BookingOrderStatusBadge::color(OrderStatus::from($state)))
                                    ->formatStateUsing(fn (string $state): string => BookingOrderStatusBadge::label(OrderStatus::from($state))),
                                TextEntry::make('actor_ref')->label('Aktor'),
                                TextEntry::make('occurred_at')->label('Waktu')->dateTime(),
                            ]),
                    ]),

                Section::make('Otorisasi Pembayaran')
                    ->columns(2)
                    ->schema([
                        RepeatableEntry::make('paymentAuthorizations')
                            ->label('Grant aktif')
                            ->state(fn (Order $record): Collection => ScopeAssignment::query()
                                ->where('entity_type', ScopeEntityType::ORDER)
                                ->where('entity_id', (string) $record->getKey())
                                ->whereNull('revoked_at')
                                ->orderByDesc('created_at')
                                ->get())
                            ->placeholder('Belum ada grant pembayaran aktif.')
                            ->schema([
                                TextEntry::make('actor_identifier')->label('Aktor'),
                                TextEntry::make('created_at')->label('Diberikan')->dateTime(),
                            ]),
                    ]),

                Section::make('Reservasi')
                    ->columns(2)
                    ->schema([
                        RepeatableEntry::make('activeReservation')
                            ->label('Reservasi plot aktif')
                            ->state(function (Order $record): array {
                                $reservation = self::activeReservation($record);

                                return $reservation === null ? [] : [$reservation];
                            })
                            ->placeholder('Belum ada reservasi aktif.')
                            ->schema([
                                TextEntry::make('plot')
                                    ->label('Plot')
                                    ->state(fn (PlotReservation $reservation): string => "{$reservation->plot->block->code} — {$reservation->plot->slot}"),
                                TextEntry::make('cemetery')
                                    ->label('Lokasi')
                                    ->state(fn (PlotReservation $reservation): string => (string) ($reservation->plot->block->cemetery?->name ?? '—')),
                                TextEntry::make('state')
                                    ->label('Status Reservasi')
                                    ->badge()
                                    ->color(fn (string $state): string => self::stateColor($state))
                                    ->formatStateUsing(fn (string $state): string => self::stateLabel($state)),
                                TextEntry::make('reserved_by_ref')->label('Direservasikan oleh'),
                                TextEntry::make('reserved_at')->label('Direservasikan pada')->dateTime(),
                                TextEntry::make('confirmed_at')
                                    ->label('Dikonfirmasi pada')
                                    ->dateTime()
                                    ->placeholder('Belum dikonfirmasi.'),
                            ]),
                    ]),
            ]);
    }

    /**
     * The order's active reservation with its plot → block → cemetery chain
     * eager-loaded, or null when the order holds none.
     */
    private static function activeReservation(Order $record): ?PlotReservation
    {
        return PlotReservation::activeForOrder($record)?->loadMissing('plot.block.cemetery');
    }

    /**
     * The current quote's frozen line-item snapshot (`Quote::currentFor()`
     * -> `lines`, the real `hasMany` on `QuoteLine`) — or an empty collection
     * when the order has never been quoted, so the `RepeatableEntry`'s own
     * placeholder renders instead of an error.
     *
     * @return Collection<int, QuoteLine>
     */
    private static function quoteLines(Order $record): Collection
    {
        $quote = Quote::currentFor($record);

        return $quote?->lines ?? new Collection;
    }

    private static function moneyString(int $amountMinor): string
    {
        return 'Rp '.number_format($amountMinor / 100, 0, ',', '.');
    }

    /**
     * Same mapping `App\Filament\Admin\Resources\PreNeedCases\Schemas\
     * PreNeedCaseInfolist::quoteStatusLabel()` already uses for the
     * identical `QuoteStatus` enum — reused verbatim here rather than
     * invented, so the two admin surfaces never drift on the same
     * catalogue's Indonesian wording (UI audit fix, 26 Aug 2026: this
     * "Total Penawaran" line was concatenating the raw enum, e.g.
     * "· ISSUED", straight into the rendered string).
     */
    private static function quoteStatusLabel(QuoteStatus $status): string
    {
        return match ($status) {
            QuoteStatus::ISSUED => 'Diterbitkan',
            QuoteStatus::ACCEPTED => 'Diterima',
            QuoteStatus::SUPERSEDED => 'Digantikan',
        };
    }

    private static function stateColor(string $state): string
    {
        return match ($state) {
            PlotReservationState::HELD => 'warning',
            PlotReservationState::CONFIRMED => 'success',
            default => 'gray',
        };
    }

    private static function stateLabel(string $state): string
    {
        return match ($state) {
            PlotReservationState::HELD => 'Ditahan',
            PlotReservationState::CONFIRMED => 'Dikonfirmasi',
            PlotReservationState::RELEASED => 'Dilepas',
            PlotReservationState::EXPIRED => 'Kedaluwarsa',
        };
    }
}

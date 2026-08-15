<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Schemas;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\Models\OrderDocument;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\Quotation\Models\Quote;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderStatusBadge;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

/**
 * Read-only view-page schema for `BookingOrderResource` — the six sections
 * the plan's Task 4 brief names: Ringkasan, Pemesan & Almarhum, Penawaran,
 * Dokumen, Riwayat Status, and Otorisasi Pembayaran.
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

                        TextEntry::make('product_type')->label('Jenis Layanan'),

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
                        TextEntry::make('quote')
                            ->label('Total Penawaran')
                            ->columnSpanFull()
                            ->state(function (Order $record): string {
                                $quote = Quote::currentFor($record);

                                if ($quote === null) {
                                    return 'Belum ada penawaran';
                                }

                                $totalRupiah = $quote->totalMinor()->toMinorInt() / 100;

                                return 'Rp '.number_format($totalRupiah, 0, ',', '.').' · '.$quote->status;
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
            ]);
    }
}

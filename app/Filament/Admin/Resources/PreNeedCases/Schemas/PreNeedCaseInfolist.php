<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PreNeedCases\Schemas;

use App\Domain\AgreementCertificate\CertificateEligibilityPolicy;
use App\Domain\AgreementCertificate\CertificateType;
use App\Domain\AgreementCertificate\Models\Agreement;
use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PreNeed\Models\PreNeedCase;
use App\Domain\PreNeed\Models\PreNeedPaymentScheduleItem;
use App\Domain\PreNeed\PreNeedCaseStatus;
use App\Domain\Quotation\Models\Quote;
use App\Domain\Quotation\QuoteStatus;
use App\Filament\Admin\Resources\PreNeedCases\PreNeedCaseStatusBadge;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Collection;

/**
 * Read-only view-page schema for `PreNeedCaseResource` — the case detail
 * sections the plan's Task 4 brief names: proposal (cemetery/package),
 * reservation (plot ref), quote (total/status via `Quote::currentFor`),
 * agreement (AC4 fields), schedule (installments; the per-installment
 * payment-link actions are the header actions `ViewPreNeedCase` mounts,
 * one per pending installment), and eligibility (the certificate rule's
 * state for this case).
 *
 * Every entry resolves its state through a `->state()` closure against the
 * DOMAIN records — the Resource never writes anything; the paid-flow
 * Actions and `OpenPaymentSession` are the only mutation paths.
 */
final class PreNeedCaseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Ringkasan')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('interest.id')->label('Referensi Minat'),
                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => PreNeedCaseStatusBadge::color(PreNeedCaseStatus::from($state)))
                            ->formatStateUsing(fn (string $state): string => PreNeedCaseStatusBadge::label(PreNeedCaseStatus::from($state))),
                        TextEntry::make('interest.bookingDraft.customer_full_name')
                            ->label('Pemohon')
                            ->placeholder('Tidak ada draft terkait.'),
                        TextEntry::make('created_at')->label('Dibuat')->dateTime(),
                    ]),

                Section::make('Usulan (Proposal)')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('cemetery.name')
                            ->label('Lokasi (TPU)')
                            ->placeholder('Belum ada proposal.'),
                        TextEntry::make('package.name')
                            ->label('Paket')
                            ->placeholder('Tanpa paket tertentu.'),
                    ]),

                Section::make('Reservasi')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reservation')
                            ->label('Plot')
                            ->state(function (PreNeedCase $record): string {
                                $reservation = self::reservation($record);

                                if ($reservation === null) {
                                    return 'Belum ada reservasi';
                                }

                                $plot = $reservation->plot;

                                return $plot !== null
                                    ? "{$plot->block->code} — {$plot->slot}"
                                    : 'Plot tidak tersedia';
                            }),
                        TextEntry::make('reservationActor')
                            ->label('Direservasikan')
                            ->state(function (PreNeedCase $record): string {
                                $reservation = self::reservation($record);

                                if ($reservation === null || $reservation->reserved_at === null) {
                                    return '—';
                                }

                                return ($reservation->reserved_by_ref ?? '—').' · '.$reservation->reserved_at->format('d/m/Y H:i');
                            }),
                    ]),

                Section::make('Penawaran')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('quoteTotal')
                            ->label('Total Penawaran')
                            ->state(fn (PreNeedCase $record): string => self::quoteSummary($record)),
                        TextEntry::make('quoteStatus')
                            ->label('Status Penawaran')
                            ->state(function (PreNeedCase $record): string {
                                $quote = self::currentQuote($record);

                                return $quote === null ? 'Belum ada penawaran' : self::quoteStatusLabel(QuoteStatus::from($quote->status));
                            }),
                    ]),

                Section::make('Kesepakatan')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('agreement_id')
                            ->label('Referensi Kesepakatan')
                            ->placeholder('Belum ada kesepakatan.'),
                        TextEntry::make('accepted_by_ref')
                            ->label('Diterima oleh')
                            ->placeholder('—'),
                        TextEntry::make('accepted_quote_id')
                            ->label('Penawaran yang Disepakati')
                            ->placeholder('—'),
                        TextEntry::make('agreementPriceGuarantee')
                            ->label('Jaminan Harga')
                            ->placeholder('—')
                            ->state(fn (PreNeedCase $record): ?string => self::agreement($record)?->price_guarantee),
                        TextEntry::make('agreementCancellationRefund')
                            ->label('Pengembalian Pembatalan')
                            ->placeholder('—')
                            ->state(fn (PreNeedCase $record): ?string => self::agreement($record)?->cancellation_refund),
                        TextEntry::make('agreementTransferability')
                            ->label('Dapat Dialihkan')
                            ->placeholder('—')
                            ->state(fn (PreNeedCase $record): ?string => self::agreement($record)?->transferability),
                        TextEntry::make('agreementTerm')
                            ->label('Jangka Waktu')
                            ->placeholder('—')
                            ->state(fn (PreNeedCase $record): ?string => self::agreement($record)?->term),
                        TextEntry::make('agreementIncludedServices')
                            ->label('Layanan Termasuk')
                            ->placeholder('—')
                            ->state(fn (PreNeedCase $record): ?string => self::agreement($record)?->included_services),
                        TextEntry::make('agreementResponsibleEntity')
                            ->label('Pihak yang Bertanggung Jawab')
                            ->placeholder('—')
                            ->state(fn (PreNeedCase $record): ?string => self::agreement($record)?->responsible_entity),
                    ]),

                Section::make('Jadwal Pembayaran')
                    ->columns(2)
                    ->schema([
                        RepeatableEntry::make('scheduleItems')
                            ->label('Cicilan')
                            ->state(fn (PreNeedCase $record): Collection => PreNeedPaymentScheduleItem::query()
                                ->where('pre_need_case_id', $record->getKey())
                                ->orderBy('installment_number')
                                ->get())
                            ->placeholder('Belum ada jadwal pembayaran. Tautan pembayaran per cicilan tersedia di aksi halaman ini.')
                            ->schema([
                                TextEntry::make('installment_number')->label('Cicilan Ke-'),
                                TextEntry::make('amount')
                                    ->label('Jumlah')
                                    ->state(fn (PreNeedPaymentScheduleItem $item): string => 'Rp '.number_format($item->amount_minor / 100, 0, ',', '.')),
                                TextEntry::make('currency')->label('Mata Uang'),
                                TextEntry::make('due_date')->label('Jatuh Tempo')->date(),
                                TextEntry::make('state')->label('Status')->badge(),
                                TextEntry::make('payment_session_id')
                                    ->label('Sesi Pembayaran')
                                    ->placeholder('Belum dibuka'),
                            ]),
                    ]),

                Section::make('Eligibilitas Sertifikat')
                    ->schema([
                        TextEntry::make('certificateEligibility')
                            ->label('Sertifikat Penyelesaian')
                            ->badge()
                            ->color(fn (string $state): string => $state === '1' ? 'success' : 'gray')
                            ->state(function (PreNeedCase $record): string {
                                $eligible = app(CertificateEligibilityPolicy::class)
                                    ->eligibleFor(CertificateType::PreNeedSettlement->value, $record);

                                return $eligible ? '1' : '0';
                            })
                            ->formatStateUsing(fn (string $state): string => $state === '1'
                                ? 'Eligible — kasus telah diselesaikan.'
                                : 'Belum eligible — kasus harus diselesaikan (settled) terlebih dahulu.'),
                    ]),
            ]);
    }

    /**
     * The order behind the record, resolved through the submit-time chain —
     * the same resolution the quote and settlement seams use.
     */
    private static function order(PreNeedCase $record): ?Order
    {
        return $record->order();
    }

    private static function currentQuote(PreNeedCase $record): ?Quote
    {
        $order = self::order($record);

        return $order === null ? null : Quote::currentFor($order);
    }

    private static function quoteSummary(PreNeedCase $record): string
    {
        $quote = self::currentQuote($record);

        if ($quote === null) {
            return 'Belum ada penawaran';
        }

        $totalRupiah = $quote->totalMinor()->toMinorInt() / 100;

        return 'Rp '.number_format($totalRupiah, 0, ',', '.').' · versi '.$quote->version_number;
    }

    private static function reservation(PreNeedCase $record): ?PlotReservation
    {
        if ($record->plot_reservation_id === null) {
            return null;
        }

        return PlotReservation::query()
            ->with('plot.block')
            ->find($record->plot_reservation_id);
    }

    private static function agreement(PreNeedCase $record): ?Agreement
    {
        if ($record->agreement_id === null) {
            return null;
        }

        return Agreement::query()->where('reference', $record->agreement_id)->first();
    }

    private static function quoteStatusLabel(QuoteStatus $status): string
    {
        return match ($status) {
            QuoteStatus::ISSUED => 'Diterbitkan',
            QuoteStatus::ACCEPTED => 'Diterima',
            QuoteStatus::SUPERSEDED => 'Digantikan',
        };
    }
}

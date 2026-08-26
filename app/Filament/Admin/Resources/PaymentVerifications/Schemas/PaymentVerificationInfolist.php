<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentVerifications\Schemas;

use App\Platform\FinancialLedger\Money;
use App\Platform\Payment\PaymentVerificationStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * View-page read-only schema for `PaymentVerificationsResource` — every real
 * column `PaymentVerification` carries: submission (reference, linked
 * order, stated amount, payment method, payment reference, instructions),
 * decision (status, submitted_at, decided_at, decided_reason,
 * decided_by_actor_ref), and the proof document reference.
 *
 * `order_id`/`amount_minor`/`currency` (PAY-02) are shown here so the admin
 * can cross-check the customer's stated amount against the real bank
 * statement BEFORE deciding — see `VerifyManualPayment`'s own doc block for
 * why the amount is confirmed here, never re-entered at decision time.
 *
 * `status` renders as plain text, same reasoning as
 * `Tables\PaymentVerificationsTable` — no badge/colour, no invented
 * `StatusIntent` entry for a deliberately separate state machine.
 *
 * `proof_document_id` is shown as a bare id, NOT a preview/download link.
 * Building a document viewer here would pull in the Document Vault's own
 * authorization surface (a quarantine/scan/promote lifecycle with its own
 * access rules) into a resource this task scopes as read-only browsing of
 * `payment_verifications` alone — out of bounds for this task. Showing the
 * id lets staff cross-reference the record without granting this page any
 * new capability to serve file content.
 */
final class PaymentVerificationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Pengajuan')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reference')->label('Referensi'),

                        TextEntry::make('marketplaceOrder.order_number')
                            ->label('Pesanan Marketplace')
                            ->placeholder('—'),

                        TextEntry::make('amount_minor')
                            ->label('Jumlah Ditransfer (Klaim Pelanggan)')
                            ->placeholder('—')
                            ->formatStateUsing(fn (?int $state): string => $state === null
                                ? '—'
                                : (new Money($state))->format()),

                        TextEntry::make('currency')->label('Mata Uang')->placeholder('—'),

                        TextEntry::make('payment_method')->label('Metode Pembayaran'),

                        TextEntry::make('payment_reference')->label('Referensi Pembayaran'),

                        TextEntry::make('submitted_at')
                            ->label('Waktu Pengajuan')
                            ->dateTime('d M Y H:i:s'),

                        TextEntry::make('instructions')
                            ->label('Instruksi')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('proof_document_id')
                            ->label('ID Dokumen Bukti')
                            ->placeholder('—'),
                    ]),

                Section::make('Keputusan')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('status')
                            ->label('Status')
                            ->formatStateUsing(fn (string $state): string => PaymentVerificationStatus::from($state)->value),

                        TextEntry::make('decided_at')
                            ->label('Waktu Keputusan')
                            ->dateTime('d M Y H:i:s')
                            ->placeholder('—'),

                        TextEntry::make('decided_by_actor_ref')
                            ->label('Diputuskan Oleh')
                            ->placeholder('—'),

                        TextEntry::make('decided_reason')
                            ->label('Alasan Keputusan')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}

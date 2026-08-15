<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RenewalOrders\Schemas;

use App\Domain\Renewal\Models\Renewal;
use App\Filament\Admin\Resources\RenewalOrders\RenewalStatusBadge;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class RenewalOrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Ringkasan')
                    ->schema([
                        TextEntry::make('reference')
                            ->label('Referensi'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->color(fn (string $state): string => RenewalStatusBadge::color($state))
                            ->formatStateUsing(fn (string $state): string => RenewalStatusBadge::label($state)),

                        TextEntry::make('source')
                            ->label('Sumber'),

                        TextEntry::make('created_at')
                            ->label('Dibuat')
                            ->dateTime(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Makam')
                    ->schema([
                        TextEntry::make('graveRecord.deceased_name')
                            ->label('Almarhum'),

                        TextEntry::make('graveRecord.block')
                            ->label('Blok'),

                        TextEntry::make('graveRecord.due_date')
                            ->label('Jatuh Tempo Berikutnya')
                            ->date(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Perpanjangan')
                    ->schema([
                        TextEntry::make('target_due_period')
                            ->label('Periode yang Diperpanjang')
                            ->date(),

                        TextEntry::make('settled_at')
                            ->label('Selesai Dibayar')
                            ->dateTime()
                            ->placeholder('Belum dibayar'),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),

                Section::make('Penawaran')
                    ->schema([
                        TextEntry::make('quote')
                            ->label('Penawaran Terakhir')
                            ->state(fn (Renewal $record): string => self::quoteFor($record))
                            ->columnSpanFull(),
                    ])
                    ->columnSpanFull(),

                Section::make('Bukti Pembayaran Eksternal')
                    ->schema([
                        TextEntry::make('externalMarking.evidence_reference')
                            ->label('Bukti')
                            ->placeholder('Tidak ada'),

                        TextEntry::make('externalMarking.marked_by_actor_ref')
                            ->label('Dicatat Oleh'),

                        TextEntry::make('externalMarking.reason')
                            ->label('Alasan'),

                        TextEntry::make('externalMarking.marked_at')
                            ->label('Dicatat Pada')
                            ->dateTime(),
                    ])
                    ->columns(2)
                    ->columnSpanFull(),
            ]);
    }

    private static function quoteFor(Renewal $record): string
    {
        $quote = $record->quotes
            ->sortByDesc('created_at')
            ->first();

        if ($quote === null) {
            return 'Belum ada penawaran';
        }

        $state = match (true) {
            $quote->accepted_at !== null => 'disetujui',
            $quote->expires_at?->isPast() === true => 'kedaluwarsa',
            default => 'menunggu persetujuan',
        };

        return 'Rp '.number_format($quote->amount_minor / 100, 0, ',', '.').' · '.$state;
    }
}

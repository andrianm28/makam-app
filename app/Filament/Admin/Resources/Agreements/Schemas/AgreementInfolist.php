<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Agreements\Schemas;

use App\Filament\Admin\Resources\Agreements\Tables\AgreementsTable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * View-page read-only schema for `AgreementsResource` — the AC4 display
 * fields (the six explicit approved strings), the AC2 acceptance binding
 * (who accepted, which quote, which exact agreement version), and the
 * subject.
 */
final class AgreementInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Perjanjian')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reference')->label('Nomor dokumen'),

                        TextEntry::make('type')
                            ->label('Tipe')
                            ->formatStateUsing(fn (string $state): string => AgreementsTable::typeLabel($state)),

                        TextEntry::make('version_number')->label('Versi'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => AgreementsTable::statusLabel($state))
                            ->color(fn (string $state): string => AgreementsTable::statusColor($state)),

                        TextEntry::make('subject_type')
                            ->label('Subjek')
                            ->formatStateUsing(fn (string $state): string => class_basename($state)),

                        TextEntry::make('subject_id')->label('ID subjek'),
                    ]),

                Section::make('Penerimaan (AC2)')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('accepted_by_ref')
                            ->label('Diterima oleh')
                            ->placeholder('Belum diterima'),

                        TextEntry::make('accepted_quote_id')
                            ->label('Penawaran yang diterima')
                            ->placeholder('—'),

                        TextEntry::make('accepted_agreement_version_id')
                            ->label('Versi perjanjian yang diikat')
                            ->placeholder('—'),
                    ]),

                Section::make('Ketentuan (AC4)')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('price_guarantee')
                            ->label('Jaminan harga')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('cancellation_refund')
                            ->label('Pembatalan & pengembalian dana')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('transferability')
                            ->label('Pengalihan')
                            ->placeholder('—')
                            ->columnSpanFull(),

                        TextEntry::make('term')
                            ->label('Jangka waktu')
                            ->placeholder('—'),

                        TextEntry::make('responsible_entity')
                            ->label('Entitas penanggung jawab')
                            ->placeholder('—'),

                        TextEntry::make('included_services')
                            ->label('Layanan yang termasuk')
                            ->placeholder('—')
                            ->columnSpanFull(),
                    ]),
            ]);
    }
}

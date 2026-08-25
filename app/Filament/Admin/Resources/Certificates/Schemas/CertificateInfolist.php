<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Certificates\Schemas;

use App\Filament\Admin\Resources\Certificates\Tables\CertificatesTable;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * View-page read-only schema for `CertificatesResource` — the certificate's
 * status information: the document number, type, version, status badge, the
 * subject, the issuance trail, and (admin-only) the vault document
 * reference. The PUBLIC status view (`CertificateStatusPage`) deliberately
 * renders none of the restricted references — this panel is the
 * back-office surface.
 */
final class CertificateInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Section::make('Sertifikat')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('reference')->label('Nomor dokumen'),

                        TextEntry::make('type')
                            ->label('Tipe')
                            ->formatStateUsing(fn (string $state): string => CertificatesTable::typeLabel($state)),

                        TextEntry::make('version_number')->label('Versi'),

                        TextEntry::make('status')
                            ->label('Status')
                            ->badge()
                            ->formatStateUsing(fn (string $state): string => CertificatesTable::statusLabel($state))
                            ->color(fn (string $state): string => CertificatesTable::statusColor($state)),

                        TextEntry::make('subject_type')
                            ->label('Subjek')
                            ->formatStateUsing(fn (string $state): string => class_basename($state)),

                        TextEntry::make('subject_id')->label('ID subjek'),
                    ]),

                Section::make('Penerbitan')
                    ->columns(2)
                    ->schema([
                        TextEntry::make('issued_by_ref')->label('Diterbitkan oleh'),

                        TextEntry::make('issued_by_role')->label('Peran penerbit'),

                        TextEntry::make('effective_at')->label('Mulai berlaku')->dateTime(),

                        TextEntry::make('document_id')
                            ->label('Dokumen vault')
                            ->placeholder('Tanpa dokumen vault'),
                    ]),
            ]);
    }
}

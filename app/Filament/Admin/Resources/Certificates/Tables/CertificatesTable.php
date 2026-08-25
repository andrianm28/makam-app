<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Certificates\Tables;

use App\Domain\AgreementCertificate\CertificateStatus;
use App\Domain\AgreementCertificate\CertificateType;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * List table for `CertificatesResource` — reference, type, status badge,
 * version, and the subject (the fully-qualified owner class basename + its
 * id — the no-morph-map convention).
 */
final class CertificatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label('Nomor dokumen')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->label('Tipe')
                    ->formatStateUsing(
                        fn (string $state): string => self::typeLabel($state),
                    ),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(
                        fn (string $state): string => self::statusLabel($state),
                    )
                    ->color(fn (string $state): string => self::statusColor($state)),

                TextColumn::make('version_number')->label('Versi'),

                TextColumn::make('subject_type')
                    ->label('Subjek')
                    ->formatStateUsing(fn (string $state): string => class_basename($state)),

                TextColumn::make('subject_id')
                    ->label('ID subjek')
                    ->placeholder('—'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function typeLabel(string $type): string
    {
        return match ($type) {
            CertificateType::OrderSettlement->value => 'Penyelesaian Pesanan',
            default => $type,
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            CertificateStatus::Issued->value => 'Terbit',
            CertificateStatus::Revoked->value => 'Dicabut',
            CertificateStatus::Replaced->value => 'Diganti',
            default => 'Draf',
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            CertificateStatus::Issued->value => 'success',
            CertificateStatus::Revoked->value => 'danger',
            CertificateStatus::Replaced->value => 'gray',
            default => 'warning',
        };
    }
}

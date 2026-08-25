<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Agreements\Tables;

use App\Domain\AgreementCertificate\AgreementStatus;
use App\Domain\AgreementCertificate\AgreementType;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * List table for `AgreementsResource` — reference, type, status badge,
 * version, and the subject (the fully-qualified owner class basename + its
 * id). One row per agreement VERSION, so a lineage renders as sibling rows
 * (AC5).
 */
final class AgreementsTable
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
                    ->formatStateUsing(fn (string $state): string => self::typeLabel($state)),

                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::statusLabel($state))
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
            AgreementType::PreNeedAgreement->value => 'Perjanjian Pra-Need',
            default => $type,
        };
    }

    public static function statusLabel(string $status): string
    {
        return match ($status) {
            AgreementStatus::Accepted->value => 'Diterima',
            AgreementStatus::Active->value => 'Aktif',
            AgreementStatus::Superseded->value => 'Disupersesi',
            default => 'Draf',
        };
    }

    public static function statusColor(string $status): string
    {
        return match ($status) {
            AgreementStatus::Accepted->value => 'success',
            AgreementStatus::Active->value => 'success',
            AgreementStatus::Superseded->value => 'gray',
            default => 'warning',
        };
    }
}

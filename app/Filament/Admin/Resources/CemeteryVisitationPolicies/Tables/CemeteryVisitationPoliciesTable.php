<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CemeteryVisitationPolicies\Tables;

use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Support\Design\IndonesianDate;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * List-page table for `CemeteryVisitationPolicyResource`: cemetery, the
 * open-weekday summary, and daily capacity.
 */
final class CemeteryVisitationPoliciesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('cemetery.name')
                    ->label('Makam')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('operating_hours')
                    ->label('Jam kunjungan')
                    ->formatStateUsing(fn (mixed $hours): string => self::hoursSummary($hours)),

                TextColumn::make('daily_capacity')
                    ->label('Kapasitas harian')
                    ->sortable(),
            ])
            ->defaultSort('cemetery.name');
    }

    /**
     * "Setiap hari 08.00–17.00" when all seven days share one time pair,
     * otherwise the day-by-day lines joined with ", " — the same
     * `IndonesianDate` vocabulary the public page renders.
     *
     * @param  mixed  $hours  The model's `operating_hours` attribute.
     */
    public static function hoursSummary(mixed $hours): string
    {
        if (! is_array($hours)) {
            return 'Belum diatur';
        }

        $lines = [];
        $uniformPair = null;
        $uniform = true;

        foreach (CemeteryVisitationPolicy::WEEKDAY_KEYS as $key) {
            $entry = $hours[$key] ?? null;

            if ($entry === null) {
                $uniform = false;
                $lines[] = IndonesianDate::weekdayLine($key, null);

                continue;
            }

            $pair = ['open' => (string) $entry['open'], 'close' => (string) $entry['close']];

            if ($uniformPair === null) {
                $uniformPair = $pair;
            } elseif ($pair !== $uniformPair) {
                $uniform = false;
            }

            $lines[] = IndonesianDate::weekdayLine($key, $pair);
        }

        if ($uniform && $uniformPair !== null) {
            return sprintf(
                'Setiap hari %s–%s',
                IndonesianDate::clock((string) $uniformPair[0]),
                IndonesianDate::clock((string) $uniformPair[1]),
            );
        }

        return implode(', ', $lines);
    }
}

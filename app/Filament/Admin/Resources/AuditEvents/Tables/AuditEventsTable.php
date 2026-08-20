<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuditEvents\Tables;

use App\Platform\Audit\AuditOutcome;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * List table for `AuditEventsResource` — one row per `audit_events` record,
 * newest first. Read-only: no row action opens an edit form, because this
 * table backs an append-only model (`AuditEvent`'s own class-level doc
 * block) that has none.
 *
 * Filters cover the four dimensions ADM-100's brief names explicitly: by
 * action, by actor, by date range, by outcome. `action` and `actor` are free
 * text (`SensitiveActions::ACTIONS` covers only the mandatory-reason subset,
 * and no closed list of every action this platform can write exists — see
 * `RoleBasedAuditReadAuthorizer`'s own doc block for why one is not invented
 * here), so both filter as a partial match rather than a `SelectFilter`
 * drawn from an incomplete option list.
 */
final class AuditEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('occurred_at')
                    ->label('Waktu')
                    ->dateTime('d M Y H:i:s')
                    ->sortable(),

                TextColumn::make('action')
                    ->label('Aksi')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('outcome')
                    ->label('Hasil')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => self::outcomeLabel($state))
                    ->color(fn (string $state): string => self::outcomeColor($state)),

                TextColumn::make('actor_ref')
                    ->label('Aktor')
                    ->placeholder('—')
                    ->searchable(),

                TextColumn::make('actor_role')
                    ->label('Peran aktor')
                    ->searchable(),

                TextColumn::make('source')
                    ->label('Sumber'),

                TextColumn::make('subject_type')
                    ->label('Subjek')
                    ->formatStateUsing(fn (string $state): string => class_basename($state)),

                TextColumn::make('subject_id')
                    ->label('ID subjek'),

                TextColumn::make('reason')
                    ->label('Alasan')
                    ->placeholder('—')
                    ->limit(40)
                    ->tooltip(fn (?string $state): ?string => $state),

                TextColumn::make('correlation_id')
                    ->label('ID korelasi')
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Filter::make('action')
                    ->schema([
                        TextInput::make('action')
                            ->label('Aksi'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['action'] ?? ''));

                        return $value === ''
                            ? $query
                            : $query->where('action', 'like', '%'.$value.'%');
                    }),

                Filter::make('actor_ref')
                    ->label('Aktor')
                    ->schema([
                        TextInput::make('actor_ref')
                            ->label('Referensi aktor'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['actor_ref'] ?? ''));

                        return $value === ''
                            ? $query
                            : $query->where('actor_ref', 'like', '%'.$value.'%');
                    }),

                SelectFilter::make('outcome')
                    ->label('Hasil')
                    ->options([
                        AuditOutcome::Allowed->value => self::outcomeLabel(AuditOutcome::Allowed->value),
                        AuditOutcome::Denied->value => self::outcomeLabel(AuditOutcome::Denied->value),
                        AuditOutcome::Failed->value => self::outcomeLabel(AuditOutcome::Failed->value),
                    ]),

                Filter::make('occurred_at')
                    ->label('Rentang waktu')
                    ->schema([
                        DatePicker::make('occurred_from')->label('Dari tanggal'),
                        DatePicker::make('occurred_until')->label('Sampai tanggal'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        return $query
                            ->when(
                                $data['occurred_from'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at', '>=', $date),
                            )
                            ->when(
                                $data['occurred_until'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->whereDate('occurred_at', '<=', $date),
                            );
                    }),
            ])
            ->defaultSort('occurred_at', 'desc');
    }

    public static function outcomeLabel(string $outcome): string
    {
        return match ($outcome) {
            AuditOutcome::Allowed->value => 'Diizinkan',
            AuditOutcome::Denied->value => 'Ditolak',
            AuditOutcome::Failed->value => 'Gagal',
            default => $outcome,
        };
    }

    public static function outcomeColor(string $outcome): string
    {
        return match ($outcome) {
            AuditOutcome::Allowed->value => 'success',
            AuditOutcome::Denied->value => 'danger',
            AuditOutcome::Failed->value => 'warning',
            default => 'gray',
        };
    }
}

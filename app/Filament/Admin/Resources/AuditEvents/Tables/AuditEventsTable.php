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
use Illuminate\Support\Carbon;

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

                        // PERF-10: a leading `%` wildcard (`'%'.$value.'%'`)
                        // cannot use a b-tree index at all — Postgres has no
                        // way to seek to a start point when the match can
                        // begin anywhere in the string, so this forced a
                        // sequential scan of `audit_events` on every filter
                        // application. `action` values are dot-namespaced
                        // (`cemetery.capability_changed`, `booking.hold_
                        // extended`, ...), so a prefix match still covers the
                        // real "find events under this namespace" use case
                        // while staying index-usable.
                        return $value === ''
                            ? $query
                            : $query->where('action', 'like', $value.'%');
                    }),

                Filter::make('actor_ref')
                    ->label('Aktor')
                    ->schema([
                        TextInput::make('actor_ref')
                            ->label('Referensi aktor'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $value = trim((string) ($data['actor_ref'] ?? ''));

                        // PERF-10 note: unlike the `action` filter above,
                        // this one is deliberately LEFT as a leading-
                        // wildcard LIKE, not switched to a prefix match.
                        // `actor_ref` values are opaque identity references
                        // (a numeric user id, or a string like
                        // 'actor-alpha') with no shared, meaningful prefix
                        // vocabulary the way `action` has its dot-namespace
                        // ('booking.', 'cemetery.', ...) — an operator
                        // searching "which events involve this actor"
                        // legitimately types a fragment they remember, not
                        // necessarily the leading characters (see
                        // `AuditEventsTableTest::
                        // test_filtering_by_actor_narrows_the_table()`,
                        // which searches 'alpha' and expects it to match
                        // 'actor-alpha' — a prefix match would silently stop
                        // finding that row). The finding's own two cited
                        // line numbers (this table's original 83/118) are
                        // the `action` LIKE and the `occurred_at`
                        // `whereDate()` — both fixed above/below. This
                        // filter still forces a sequential scan on a
                        // non-empty search; that trade-off is accepted here
                        // rather than silently changing what an operator's
                        // search finds.
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
                        // PERF-10: `whereDate('occurred_at', ...)` wraps the
                        // indexed timestamp column in `DATE(occurred_at)`
                        // before comparing, and Postgres cannot use a plain
                        // b-tree index on `occurred_at` (see this table's
                        // `audit_events_occurred_at_index`) through a
                        // function applied to the column — it forces a
                        // sequential scan instead. A half-open range on the
                        // raw timestamp is index-usable and selects the
                        // exact same calendar-day rows: `[start-of-day,
                        // start-of-next-day)`.
                        return $query
                            ->when(
                                $data['occurred_from'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->where(
                                    'occurred_at',
                                    '>=',
                                    Carbon::parse($date)->startOfDay(),
                                ),
                            )
                            ->when(
                                $data['occurred_until'] ?? null,
                                fn (Builder $query, string $date): Builder => $query->where(
                                    'occurred_at',
                                    '<',
                                    Carbon::parse($date)->addDay()->startOfDay(),
                                ),
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

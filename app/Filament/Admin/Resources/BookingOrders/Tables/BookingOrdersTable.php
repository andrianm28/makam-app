<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BookingOrders\Tables;

use App\Domain\OrderWorkflow\Models\Order;
use App\Domain\OrderWorkflow\OrderStatus;
use App\Domain\OrderWorkflow\ProductType;
use App\Domain\PlotReservation\Models\PlotReservation;
use App\Domain\PlotReservation\PlotReservationState;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderProductTypeLabel;
use App\Filament\Admin\Resources\BookingOrders\BookingOrderStatusBadge;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The shared, panel-agnostic order list — a plain static builder with no
 * panel, resource or authorization coupling of any kind, which is what lets
 * BOTH `App\Filament\Admin\Resources\BookingOrders\BookingOrderResource`
 * (`/admin`, unscoped) and `App\Filament\Operator\Resources\CemeteryOrders
 * \CemeteryOrderResource` (`/operator`, cemetery-scoped) call it verbatim.
 * This is the codebase's first cross-panel reuse of a table builder — there
 * was no prior precedent for it — so the rule is stated explicitly: nothing
 * in this class may consult the current panel, the current actor, or a
 * Resource class. Row visibility is each Resource's `getEloquentQuery()`'s
 * job, and it alone.
 *
 * ---------------------------------------------------------------------------
 * Eager loading lives here, not in a Resource
 * ---------------------------------------------------------------------------
 * `modifyQueryUsing` carries every relation the columns read.
 * `BookingOrderResource::getEloquentQuery()` happens to eager-load
 * `bookingDraft` as well, but `CemeteryOrderResource`'s query comes from
 * `ScopesToCurrentCemetery` and does not — so putting the loads here is what
 * makes the two panels behave identically, and is what satisfies the
 * roadmap's explicit "no N+1" requirement for the plot column.
 *
 * ---------------------------------------------------------------------------
 * The cemetery filter's OPTIONS are unscoped, on both panels
 * ---------------------------------------------------------------------------
 * Filament builds a relationship filter's option list from the terminal
 * relation (`SelectFilter::getRelationshipQuery()`), without the outer
 * query's scope, so on `/operator` the dropdown lists every cemetery rather
 * than only granted ones. That is deliberate and not a leak: cemetery names
 * are already public directory data (the public cemetery directory serves
 * them to unauthenticated guests), and choosing a non-granted cemetery
 * returns zero rows because `CemeteryOrderResource::applyCemeteryScope()`
 * still applies to the outer query. Recorded so a reviewer does not read it
 * as an oversight.
 */
final class BookingOrdersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with([
                    'bookingDraft.cemetery',
                    'bookingDraft.cemeteryPackage',
                    'plotReservations.plot.block',
                ])
                ->orderByDesc('created_at'))
            ->columns([
                TextColumn::make('reference')->label('Referensi')->searchable(),
                TextColumn::make('bookingDraft.customer_full_name')->label('Pemesan')->searchable(),
                TextColumn::make('bookingDraft.cemetery.name')
                    ->label('Makam')
                    ->placeholder('—')
                    ->searchable(),
                TextColumn::make('bookingDraft.cemeteryPackage.name')
                    ->label('Paket')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('product_type')
                    ->label('Jenis Layanan')
                    ->formatStateUsing(fn (string $state): string => BookingOrderProductTypeLabel::label(ProductType::from($state)))
                    ->toggleable(),
                TextColumn::make('plot')
                    ->label('Plot')
                    ->placeholder('Belum direservasi')
                    ->state(fn (Order $record): ?string => self::plotLabel($record))
                    ->description(fn (Order $record): ?string => self::plotStateLabel($record)),
                TextColumn::make('status')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => BookingOrderStatusBadge::color(OrderStatus::from($state)))
                    ->formatStateUsing(fn (string $state): string => BookingOrderStatusBadge::label(OrderStatus::from($state))),
                TextColumn::make('created_at')->label('Diajukan')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label('Status')
                    ->options(collect(OrderStatus::cases())->mapWithKeys(
                        fn (OrderStatus $status): array => [$status->value => BookingOrderStatusBadge::label($status)]
                    )->all()),

                SelectFilter::make('cemetery')
                    ->label('Makam')
                    // Dot-notation nesting IS supported: Filament walks each
                    // hop in `HasRelationship::getRelationship()` to build
                    // the options, and hands the dotted name straight to
                    // Eloquent's nested-path-aware `whereHas()` to apply the
                    // filter. Verified against filament/filament v5.7.3.
                    ->relationship('bookingDraft.cemetery', 'name'),

                Filter::make('has_reserved_plot')
                    ->label('Punya plot direservasi')
                    ->query(fn (Builder $query): Builder => self::whereHasActiveReservation($query)),
            ])
            ->recordActions([
                ViewAction::make()->label('Lihat'),
            ]);
    }

    /**
     * The active reservation's plot, read from the ALREADY-EAGER-LOADED
     * chain — never through `PlotReservation::activeForOrder()`, which
     * would be one query per rendered row.
     *
     * `?->` chained rather than assumed: the plot/block FKs make a missing
     * relation unlikely, but this renders on the operator's landing page, so
     * a missing relation degrades to the same '—' placeholder the other
     * columns in this file already use, rather than fataling the whole
     * dashboard. Deliberately distinct from the "no active reservation"
     * case (`null`, which reads as "Belum direservasi") — an active
     * reservation with a dangling plot/block is a data inconsistency, not
     * an unreserved order.
     */
    private static function plotLabel(Order $record): ?string
    {
        $reservation = PlotReservation::incumbentOf($record->plotReservations->first());

        if ($reservation === null) {
            return null;
        }

        return $reservation->plot?->block !== null
            ? "{$reservation->plot->block->code} — {$reservation->plot->slot}"
            : '—';
    }

    private static function plotStateLabel(Order $record): ?string
    {
        $reservation = PlotReservation::incumbentOf($record->plotReservations->first());

        return match ($reservation?->state) {
            PlotReservationState::HELD => 'Ditahan',
            PlotReservationState::CONFIRMED => 'Dikonfirmasi',
            default => null,
        };
    }

    /**
     * "The order's reservation chain HEAD is active" as SQL.
     *
     * A naive `whereHas('plotReservations', state IN (held, confirmed))`
     * would be WRONG: the chain is append-only, so a released reservation
     * still leaves its original `held` row in the table and the naive
     * predicate would match it. The correct reading — the same one
     * `PlotReservation::incumbentOf()` applies in PHP — is "an active row
     * exists with no newer row in the same chain", where "newer" is the
     * `created_at DESC, id DESC` ordering the model already uses.
     *
     * The row-value comparison is PostgreSQL syntax, which is fine: this
     * app is PostgreSQL-only by policy (`docs/operations/local-test-recipe.md`
     * forbids SQLite even for tests). It interpolates no caller input, so
     * there is no injection surface.
     *
     * @param  Builder<Order>  $query
     * @return Builder<Order>
     */
    private static function whereHasActiveReservation(Builder $query): Builder
    {
        return $query->whereHas('plotReservations', function (Builder $chain): void {
            $chain
                ->whereIn('plot_reservations.state', PlotReservationState::ACTIVE_STATES)
                ->whereNotExists(function ($newer): void {
                    $newer->selectRaw('1')
                        ->from('plot_reservations as newer_reservations')
                        ->whereColumn('newer_reservations.order_id', 'plot_reservations.order_id')
                        ->whereRaw(
                            '(newer_reservations.created_at, newer_reservations.id) '
                            .'> (plot_reservations.created_at, plot_reservations.id)'
                        );
                });
        });
    }
}

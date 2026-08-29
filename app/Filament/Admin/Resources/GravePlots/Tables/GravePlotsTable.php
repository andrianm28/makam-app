<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GravePlots\Tables;

use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotState;
use App\Filament\Admin\Pages\PasswordReauthentication;
use App\Filament\Admin\Resources\GravePlots\GravePlotsResource;
use App\Filament\Shared\PlotInventory\PlotStateOverrides;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use App\Support\Design\StatusIntent;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * List-page table for `GravePlotsResource`: cemetery (via block), block
 * code, slot, plot state (Indonesian badge), and package/class link.
 * Filterable by cemetery, block, and state. Row actions: the three audited
 * state overrides.
 *
 * ---------------------------------------------------------------------------
 * The three state-override actions
 * ---------------------------------------------------------------------------
 * - 'Tandai Terisi' (`markOccupied`) — visible for available/reserved/
 *   maintenance plots, writes `PlotState::OCCUPIED`.
 * - 'Tandai Perawatan' (`markMaintenance`) — visible for any non-
 *   maintenance plot, writes `PlotState::MAINTENANCE`.
 * - 'Tandai Tersedia' (`markAvailable`) — visible ONLY from
 *   maintenance/occupied plots (a reserved plot is owned by an active
 *   reservation and must never be freed behind it), with a confirm modal
 *   that states the state history stays in the audit trail. Writes
 *   `PlotState::AVAILABLE`.
 *
 * Each action's allowed from-state set is declared once in
 * `App\Filament\Shared\PlotInventory\PlotStateOverrides::fromStates()` and
 * used BOTH by the `->visible()` closures here and by that class's
 * run-time re-read, so render-time meaning and wire-call enforcement
 * cannot drift (finding I2). The rule moved out of this file on 28 Aug
 * 2026 when the Phase D Floor/Block Map became a second surface offering
 * the same three overrides; two hand-maintained copies of a security
 * control is how the two would eventually disagree.
 *
 * Every override writes `plot_state` ONLY through
 * `Audit::wrap` + `GRAVE_PLOT_STATE_CHANGED` (the row change and its
 * `audit_events` entry commit in one transaction), so the model's `saving`
 * guard (`PlotState::assertKnown`) and the same transaction still enforce
 * the closed list; an `InvalidArgumentException` from the guard surfaces as
 * a danger notification, not a 500.
 *
 * ---------------------------------------------------------------------------
 * Authorization: three layers, none redundant
 * ---------------------------------------------------------------------------
 * 1. `->authorize(...)` — the RENDER/MOUNT gate (the master-data
 *    authorizer), exactly as the sibling master-data surfaces carry it.
 * 2. `->visible(...)` — per-record state meaning (orthogonal to
 *    authorization: "is this action meaningful for this record"). This is
 *    NOT a security property: Filament's `mountAction` re-checks disabled
 *    and authorization but not visibility, so a hidden action is still
 *    wire-addressable.
 * 3. Inside the `->action()` closure, `ReauthenticationGuard::assertFresh()`
 *    — AGENTS.md requires recent re-authentication for plot-override
 *    actions; a stale actor is sent to the password re-authentication page
 *    before any write.
 *    Layer 3 is the enforcement: a Livewire component method is addressable
 *    directly over the wire, so "the button was not rendered" is not a
 *    security property.
 * 4. In the shared write path `PlotStateOverrides::apply()`, a `fresh()` re-read of
 *    the record followed by a refusal when the CURRENT state is not in
 *    the target's from-set (finding I2): a wire call against a record
 *    whose state changed since the page rendered — e.g. `markAvailable`
 *    on a plot another actor just reserved — is refused with a danger
 *    notification and no write, instead of freeing a plot behind an
 *    active reservation.
 *
 * There is deliberately NO delete action (the plan's bound scope: list +
 * overrides only; deletion is model-guarded and no delete surface exists).
 */
final class GravePlotsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->orderBy('block_id')->orderBy('slot'))
            ->columns([
                TextColumn::make('block.cemetery.name')
                    ->label('Makam')
                    ->sortable(),

                TextColumn::make('block.code')
                    ->label('Blok')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('slot')
                    ->label('Slot')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('plot_state')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => self::stateColor($state))
                    ->formatStateUsing(fn (string $state): string => self::stateLabel($state))
                    ->sortable(),

                TextColumn::make('cemeteryPackage.name')
                    ->label('Paket / Kelas')
                    ->placeholder('Tanpa paket'),
            ])
            ->filters([
                SelectFilter::make('cemetery')
                    ->label('Makam')
                    ->relationship('block.cemetery', 'name'),

                SelectFilter::make('block')
                    ->label('Blok')
                    ->relationship('block', 'code'),

                SelectFilter::make('plot_state')
                    ->label('Status')
                    ->options(array_combine(
                        PlotState::KNOWN_STATES,
                        array_map(
                            fn (string $state): string => self::stateLabel($state),
                            PlotState::KNOWN_STATES,
                        ),
                    )),
            ])
            ->recordActions([
                Action::make('markOccupied')
                    ->label('Tandai Terisi')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    ->modalHeading('Tandai plot terisi')
                    ->modalDescription('Plot ini ditandai terisi dan dicatat di audit.')
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->visible(fn (GravePlot $record): bool => in_array(
                        $record->plot_state,
                        PlotStateOverrides::fromStates(PlotState::OCCUPIED),
                        true,
                    ))
                    ->action(function (GravePlot $record): void {
                        if (! self::requireFreshAuthentication()) {
                            return;
                        }

                        // `GravePlotsResource::auditRoleFor()` is reused (not re-derived) so the
                        // audit trail's `actor_role` cannot become two vocabularies for one action.
                        PlotStateOverrides::apply(
                            $record,
                            PlotState::OCCUPIED,
                            'Plot ditandai terisi.',
                            GravePlotsResource::auditRoleFor(app(ActorContext::class)),
                        );
                    }),

                Action::make('markMaintenance')
                    ->label('Tandai Perawatan')
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading('Tandai plot perawatan')
                    ->modalDescription('Plot ditandai sedang perawatan dan dicatat di audit.')
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->visible(fn (GravePlot $record): bool => in_array(
                        $record->plot_state,
                        PlotStateOverrides::fromStates(PlotState::MAINTENANCE),
                        true,
                    ))
                    ->action(function (GravePlot $record): void {
                        if (! self::requireFreshAuthentication()) {
                            return;
                        }

                        // `GravePlotsResource::auditRoleFor()` is reused (not re-derived) so the
                        // audit trail's `actor_role` cannot become two vocabularies for one action.
                        PlotStateOverrides::apply(
                            $record,
                            PlotState::MAINTENANCE,
                            'Plot ditandai perawatan.',
                            GravePlotsResource::auditRoleFor(app(ActorContext::class)),
                        );
                    }),

                Action::make('markAvailable')
                    ->label('Tandai Tersedia')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading('Tandai plot tersedia')
                    ->modalDescription(
                        'Plot akan tersedia kembali untuk reservasi. Riwayat perubahan status '
                        .'tetap tercatat di audit.'
                    )
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->visible(fn (GravePlot $record): bool => in_array(
                        $record->plot_state,
                        PlotStateOverrides::fromStates(PlotState::AVAILABLE),
                        true,
                    ))
                    ->action(function (GravePlot $record): void {
                        if (! self::requireFreshAuthentication()) {
                            return;
                        }

                        // `GravePlotsResource::auditRoleFor()` is reused (not re-derived) so the
                        // audit trail's `actor_role` cannot become two vocabularies for one action.
                        PlotStateOverrides::apply(
                            $record,
                            PlotState::AVAILABLE,
                            'Plot ditandai tersedia.',
                            GravePlotsResource::auditRoleFor(app(ActorContext::class)),
                        );
                    }),
            ]);
    }

    /**
     * design-system.md §3.7 is normative and §9.2 MUST #5 is enforceable:
     * "Components must not switch on enum strings. Resolve status → intent
     * in ONE place." The local `match ($state)` that used to live here was
     * that forbidden switch; the mapping now lives in
     * `StatusIntent::FAMILY_PLOT_STATE`, which the Phase D floor map reads
     * too, so the two surfaces cannot drift into two colour schemes for
     * the same four states.
     *
     * The rendered output is UNCHANGED: success / warning / danger / info,
     * exactly as this table has shipped since 16 Aug 2026 — locked by
     * `StatusIntentTest::test_grave_plots_table_colours_and_labels_are_
     * unchanged_by_the_centralisation()`.
     */
    public static function stateColor(string $state): string
    {
        return StatusIntent::filamentColor($state, StatusIntent::FAMILY_PLOT_STATE);
    }

    /**
     * Same centralisation as `stateColor()`. One behavioural difference
     * from the removed `match`: an UNKNOWN state used to return the raw
     * value verbatim and now returns its humanisation, plus a logged
     * warning. Unreachable in practice — `GravePlot::booted()` asserts
     * `PlotState::assertKnown()` on every save, so no row can carry an
     * unmapped state — and the logged warning is strictly more useful
     * than silently rendering a raw enum to an operator.
     */
    public static function stateLabel(string $state): string
    {
        return StatusIntent::label($state, StatusIntent::FAMILY_PLOT_STATE);
    }

    private static function actorMayManage(): bool
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }

        return true;
    }

    /**
     * The wire-level re-authentication enforcement for the state overrides
     * (AGENTS.md: plot-override actions). On refusal, a warning notification
     * and a redirect into the password re-authentication page — the exact
     * `MarkMarketplaceOrderPaidAction` shape — and `false` is returned so
     * the action closure stops before any write; the state is untouched.
     */
    private static function requireFreshAuthentication(): bool
    {
        try {
            app(ReauthenticationGuard::class)->assertFresh(app(ActorContext::class));

            return true;
        } catch (ReauthenticationRequiredException) {
            Notification::make()
                ->warning()
                ->title('Perlu verifikasi ulang')
                ->body('Lakukan verifikasi ulang untuk tindakan ini.')
                ->send();

            session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, 'plot_override');
            session()->put('url.intended', GravePlotsResource::getUrl('index'));
            redirect()->route(PasswordReauthentication::ROUTE_NAME);

            return false;
        }
    }
}

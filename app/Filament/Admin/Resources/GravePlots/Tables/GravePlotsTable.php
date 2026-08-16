<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\GravePlots\Tables;

use App\Domain\PlotInventory\Models\GravePlot;
use App\Domain\PlotInventory\PlotInventoryAuditActions;
use App\Domain\PlotInventory\PlotState;
use App\Filament\Admin\Resources\GravePlots\GravePlotsResource;
use App\Http\Middleware\RequireRecentAuthentication;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use App\Platform\IdentityAccess\Reauthentication\Exceptions\ReauthenticationRequiredException;
use App\Platform\IdentityAccess\Reauthentication\ReauthenticationGuard;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

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
 * `overrideFromStates()` and used BOTH by the `->visible()` closures and
 * by the run-time re-read in `overrideState()`, so render-time meaning and
 * wire-call enforcement cannot drift (finding I2).
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
 *    actions; a stale actor is sent to the MFA challenge before any write.
 *    Layer 3 is the enforcement: a Livewire component method is addressable
 *    directly over the wire, so "the button was not rendered" is not a
 *    security property.
 * 4. In the shared write path `overrideState()`, a `fresh()` re-read of
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
                        self::overrideFromStates(PlotState::OCCUPIED),
                        true,
                    ))
                    ->action(function (GravePlot $record): void {
                        if (! self::requireFreshAuthentication()) {
                            return;
                        }

                        self::overrideState($record, PlotState::OCCUPIED, 'Plot ditandai terisi.');
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
                        self::overrideFromStates(PlotState::MAINTENANCE),
                        true,
                    ))
                    ->action(function (GravePlot $record): void {
                        if (! self::requireFreshAuthentication()) {
                            return;
                        }

                        self::overrideState($record, PlotState::MAINTENANCE, 'Plot ditandai perawatan.');
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
                        self::overrideFromStates(PlotState::AVAILABLE),
                        true,
                    ))
                    ->action(function (GravePlot $record): void {
                        if (! self::requireFreshAuthentication()) {
                            return;
                        }

                        self::overrideState($record, PlotState::AVAILABLE, 'Plot ditandai tersedia.');
                    }),
            ]);
    }

    public static function stateColor(string $state): string
    {
        return match ($state) {
            PlotState::AVAILABLE => 'success',
            PlotState::RESERVED => 'warning',
            PlotState::OCCUPIED => 'danger',
            PlotState::MAINTENANCE => 'info',
            default => 'gray',
        };
    }

    public static function stateLabel(string $state): string
    {
        return match ($state) {
            PlotState::AVAILABLE => 'Tersedia',
            PlotState::RESERVED => 'Dipesan',
            PlotState::OCCUPIED => 'Terisi',
            PlotState::MAINTENANCE => 'Perawatan',
            default => $state,
        };
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
     * The allowed from-state set for each override target — the SINGLE
     * source of truth consumed by both the row-action `->visible()`
     * closures (render-time meaning) and `overrideState()`'s run-time
     * re-read (wire-call enforcement), so the two cannot drift:
     * - `markAvailable` (→ `available`) is only meaningful FROM
     *   maintenance/occupied — never from `available` (no-op) and never
     *   from `reserved`, whose claim belongs to an active reservation.
     * - `markOccupied` (→ `occupied`) from available/reserved/maintenance.
     * - `markMaintenance` (→ `maintenance`) from any other state.
     */
    private static function overrideFromStates(string $toState): array
    {
        return match ($toState) {
            PlotState::AVAILABLE => [PlotState::MAINTENANCE, PlotState::OCCUPIED],
            PlotState::OCCUPIED => [PlotState::AVAILABLE, PlotState::RESERVED, PlotState::MAINTENANCE],
            PlotState::MAINTENANCE => [PlotState::AVAILABLE, PlotState::RESERVED, PlotState::OCCUPIED],
            default => [],
        };
    }

    /**
     * The wire-level re-authentication enforcement for the state overrides
     * (AGENTS.md: plot-override actions). On refusal, a warning notification
     * and a redirect into the MFA challenge — the exact
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
            redirect()->route('filament.admin.pages.mfa-challenge');

            return false;
        }
    }

    /**
     * The shared write path for the three overrides: `Audit::wrap` +
     * `GRAVE_PLOT_STATE_CHANGED` around the plot-state update. The model's
     * `saving` guard still asserts the closed list inside the same
     * transaction; an `InvalidArgumentException` there (or any other honest
     * refusal) rolls the write AND its audit row back and surfaces as a
     * danger notification.
     *
     * The run path re-reads the record (`fresh()`) BEFORE the write and
     * refuses when its CURRENT state is not in the target's from-set
     * (`overrideFromStates`): `->visible()` is not re-checked by
     * Filament's mount, so a wire call against a stale view — e.g.
     * `markAvailable` on a plot that has been reserved since the page
     * rendered — would otherwise free the plot behind its active
     * reservation (finding I2). The refusal is a danger notification and
     * no write; the audit reason uses the re-read state so the trail
     * records the transition that actually happened.
     */
    private static function overrideState(GravePlot $record, string $toState, string $successTitle): void
    {
        $fresh = $record->fresh() ?? $record;
        $fromState = $fresh->plot_state;

        if (! in_array($fromState, self::overrideFromStates($toState), true)) {
            Notification::make()
                ->title('Status plot tidak dapat diubah.')
                ->body('Status plot saat ini tidak mengizinkan tindakan ini; tidak ada perubahan yang ditulis.')
                ->danger()
                ->send();

            return;
        }

        try {
            $actor = app(ActorContext::class);

            Audit::wrap(
                fn (): bool => $fresh->update(['plot_state' => $toState]),
                action: PlotInventoryAuditActions::GRAVE_PLOT_STATE_CHANGED,
                subject: new AuditSubject('grave_plot', (string) $fresh->getKey()),
                outcome: AuditOutcome::Allowed,
                actorRef: $actor->identityReference,
                actorRole: GravePlotsResource::auditRoleFor($actor),
                source: AuditSource::Panel,
                reason: sprintf('Admin state override: plot %s → %s.', $fromState, $toState),
            );
        } catch (InvalidArgumentException $exception) {
            Notification::make()
                ->title('Status plot tidak dapat diubah.')
                ->body($exception->getMessage())
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title($successTitle)
            ->success()
            ->send();
    }
}

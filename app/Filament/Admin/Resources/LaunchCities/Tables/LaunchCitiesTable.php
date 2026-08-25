<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\LaunchCities\Tables;

use App\Domain\CemeteryDirectory\LaunchCityAuditActions;
use App\Domain\CemeteryDirectory\Models\LaunchCity;
use App\Filament\Admin\Resources\LaunchCities\LaunchCityResource;
use App\Platform\Audit\Audit;
use App\Platform\Audit\AuditOutcome;
use App\Platform\Audit\AuditSource;
use App\Platform\Audit\AuditSubject;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * List-page table for `LaunchCityResource`. Columns: code, label, active
 * badge, sort order. Row actions: edit, move-up/move-down — the explicit
 * reorder mechanism for the flat launch-city catalogue (no per-group
 * ordering exists, unlike `FaqArticlesTable`'s per-category list).
 *
 * ---------------------------------------------------------------------------
 * Reorder: swap `sort_order` of the two adjacent rows, audited
 * ---------------------------------------------------------------------------
 * The catalogue is a flat, gapless-by-convention list ordered by
 * `sort_order`, so a row move is exactly a swap of the moving row's
 * `sort_order` with its immediate neighbour's — the task brief's "swap
 * pattern" (and the boundary guards below make "adjacent" exact). The swap
 * is wrapped in `Audit::wrap` recording `LaunchCityAuditActions::REORDERED`
 * with the moving row's previous/new sort order in `metadata`
 * (`previous_state` / `new_state` — both `MetadataAllowlist` keys, so no
 * allowlist extension is needed), giving the same "state change and audit
 * row commit together" (AC4) guarantee every other write in this resource
 * carries.
 *
 * ---------------------------------------------------------------------------
 * Authorization on the two custom actions: two layers, neither redundant
 * ---------------------------------------------------------------------------
 * Same reasoning as `FaqArticlesTable`: plain `Action`s get NOTHING by
 * default ("Actions do not have automatic policy-based authorization"), so
 * both `moveUp` and `moveDown` carry:
 *
 *  1. `->authorize(...)` — the RENDER/MOUNT gate. Decides whether the
 *     control is drawn and whether `mountAction()` will accept it.
 *  2. The authorizer call as the FIRST statement of the `->action()`
 *     closure — the actual enforcement. A Livewire component method is
 *     addressable directly over the wire, so "the button was not rendered"
 *     is not a security property. This throws
 *     `MasterDataNotAuthorisedException` before the swap runs and before
 *     any audit row is written.
 *
 * The `->hidden()` calls are orthogonal: they answer "is this action
 * MEANINGFUL for this record" (already first/last in order), not "may this
 * actor do it at all".
 */
final class LaunchCitiesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->orderBy('sort_order')->orderBy('id'))
            ->columns([
                TextColumn::make('code')
                    ->label('Kode')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('label')
                    ->label('Nama kota')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('is_active')
                    ->label('Status')
                    ->badge()
                    ->color(fn (bool $state): string => $state ? 'success' : 'gray')
                    ->formatStateUsing(fn (bool $state): string => $state ? 'Aktif' : 'Nonaktif')
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),
            ])
            ->recordActions([
                EditAction::make(),

                Action::make('moveUp')
                    ->label('Naikkan urutan')
                    ->icon(Heroicon::OutlinedArrowUp)
                    ->color('gray')
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->hidden(fn (LaunchCity $record): bool => self::isFirstInOrder($record))
                    ->action(function (LaunchCity $record): void {
                        app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));

                        self::swapWithNeighbour($record, -1);
                    }),

                Action::make('moveDown')
                    ->label('Turunkan urutan')
                    ->icon(Heroicon::OutlinedArrowDown)
                    ->color('gray')
                    ->authorize(fn (): bool => self::actorMayManage())
                    ->hidden(fn (LaunchCity $record): bool => self::isLastInOrder($record))
                    ->action(function (LaunchCity $record): void {
                        app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));

                        self::swapWithNeighbour($record, 1);
                    }),
            ]);
    }

    /**
     * @return list<int>
     */
    private static function orderedIds(): array
    {
        return LaunchCity::query()
            ->orderBy('sort_order')
            // `id` as a deterministic tiebreaker: nothing enforces unique
            // `sort_order` values (the column has no unique index), so a
            // duplicated value is reachable if an admin edits the field
            // directly. Without a tiebreaker, "first"/"last" here could
            // disagree between calls against a duplicate-`sort_order` pair.
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    private static function isFirstInOrder(LaunchCity $record): bool
    {
        $ids = self::orderedIds();

        return ($ids[0] ?? null) === $record->id;
    }

    private static function isLastInOrder(LaunchCity $record): bool
    {
        $ids = self::orderedIds();

        return (end($ids) ?: null) === $record->id;
    }

    /**
     * Swap this row's `sort_order` with its immediate neighbour in the
     * current display order, inside `Audit::wrap`.
     */
    private static function swapWithNeighbour(LaunchCity $record, int $direction): void
    {
        $orderedIds = self::orderedIds();
        $index = array_search($record->id, $orderedIds, true);

        if ($index === false) {
            return;
        }

        $swapWith = $index + $direction;

        if ($swapWith < 0 || $swapWith >= count($orderedIds)) {
            // At the boundary — the row action is also `hidden()` here,
            // so this is a defensive no-op against a stale button click,
            // not the expected path.
            return;
        }

        $neighbour = LaunchCity::query()->findOrFail($orderedIds[$swapWith]);
        $previousOrder = $record->sort_order;
        $newOrder = $neighbour->sort_order;

        $actor = app(ActorContext::class);

        Audit::wrap(
            function () use ($record, $neighbour, $previousOrder, $newOrder): bool {
                $record->update(['sort_order' => $newOrder]);
                $neighbour->update(['sort_order' => $previousOrder]);

                return true;
            },
            action: LaunchCityAuditActions::REORDERED,
            subject: new AuditSubject(type: 'launch_city', id: (string) $record->getKey()),
            outcome: AuditOutcome::Allowed,
            actorRef: $actor->identityReference,
            actorRole: LaunchCityResource::auditRoleFor($actor),
            source: AuditSource::Panel,
            metadata: [
                'previous_state' => $previousOrder,
                'new_state' => $newOrder,
            ],
        );

        // Sent here rather than in the two row-action closures so the two
        // early returns above (id not in list, boundary click) stay silent
        // no-ops — nothing moved, so nothing is confirmed.
        Notification::make()
            ->title('Urutan kota diperbarui.')
            ->success()
            ->send();
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
}

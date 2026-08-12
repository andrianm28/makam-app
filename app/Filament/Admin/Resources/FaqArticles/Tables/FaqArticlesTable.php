<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\FaqArticles\Tables;

use App\Domain\Faq\Actions\PublishFaqArticle;
use App\Domain\Faq\Actions\ReorderFaqArticles;
use App\Domain\Faq\Actions\UnpublishFaqArticle;
use App\Domain\Faq\Contracts\FaqAuthorizer;
use App\Domain\Faq\FaqArticlePublishState;
use App\Domain\Faq\Models\FaqArticle;
use App\Filament\Admin\Resources\FaqArticles\FaqArticleStatusBadge;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * List-page table for `FaqArticleResource`. Columns: title, category
 * (relationship), publish state (badge, via `FaqArticleStatusBadge` — see
 * that class's own doc block for why it is not `StatusIntent`), sort
 * order, updated/published timestamps. Filterable by category and publish
 * state. Row actions: view (preview), edit, publish, unpublish, and
 * move-up/move-down (this batch's chosen reorder mechanism — see below).
 *
 * ---------------------------------------------------------------------------
 * Reorder: explicit "move up"/"move down" row actions, NOT Filament's
 * native `Table::reorderable()` drag feature — a verified, documented
 * choice
 * ---------------------------------------------------------------------------
 * The batch brief asked this to be investigated against the real installed
 * Filament 5 source before committing to an approach. Traced:
 *   - `Filament\Tables\Table\Concerns\CanReorderRecords::reorderable()`
 *     wires a drag handle; the actual reassignment happens in
 *     `Filament\Tables\Concerns\CanReorderRecords::reorderTable(array
 *     $order, ...)`, a method on the trait mixed into the Livewire table
 *     component (`ListRecords` via `InteractsWithTable`).
 *   - That method's real body (vendor/filament/tables/src/Concerns/
 *     CanReorderRecords.php) does the reassignment with a RAW SQL `CASE
 *     WHEN id = ? THEN n ... END` UPDATE issued directly against
 *     `$this->getTable()->getQuery()` — it does NOT go through the Eloquent
 *     model, does not call any Domain Action, and provides only
 *     `beforeReordering`/`afterReordering` closures that receive the
 *     already-applied `$order` array, i.e. by the time any app code could
 *     react, the write has already committed outside any transaction this
 *     Resource controls.
 *
 * That is disqualifying for two independent reasons this codebase treats as
 * hard requirements, not style preferences:
 *   1. `app/Domain/README.md`'s binding rule ("Domain logic lives here...
 *      not in controllers, Livewire components, or Filament Resources") —
 *      the native feature's write path is Filament's own trait code, which
 *      this Resource does not own and cannot make call
 *      `ReorderFaqArticles`; there is no hook point BEFORE the UPDATE runs.
 *   2. Audit: `ReorderFaqArticles` is the only path that writes
 *      `FaqAuditActions::REORDERED` to `audit_events`
 *      (`admin-operations` AC8). The native feature's raw UPDATE has no
 *      audit call at all — using it would silently lose the audit trail
 *      for every reorder.
 *   Separately (a real, if secondary, concern): `reorderTable()`'s raw
 *   UPDATE operates on whatever `$order` (the currently visible/sorted row
 *   keys) it is given — it has no awareness of `ReorderFaqArticles`'
 *   one-category-at-a-time invariant. A drag gesture on an unfiltered,
 *   multi-category table would silently interleave `sort_order` values
 *   across categories, exactly the corruption `ReorderFaqArticles`'s own
 *   doc block explains it validates against and rejects.
 *
 * Given that, this table exposes two explicit `Action`s per row — "Naikkan
 * urutan" (move up) / "Turunkan urutan" (move down) — that each: read the
 * COMPLETE ordered id list for the record's own category (`FaqArticle::
 * forAdmin()->where('category_id', ...)->orderBy('sort_order')`), swap the
 * record with its immediate neighbour in that list, and call
 * `ReorderFaqArticles` with the resulting full list — never a partial
 * move, satisfying that Action's own "complete ordered id list for ONE
 * category" contract exactly. This keeps every reorder inside this
 * Resource fully auditable and Action-routed, at the cost of one row-move
 * per click instead of free-form drag-and-drop — an acceptable trade at
 * this catalogue's documented ~23-row MVP scale (`FaqArticle::
 * scopeMatching()`'s own doc block).
 *
 * ---------------------------------------------------------------------------
 * Authorization on the four custom actions: two layers, and neither is
 * redundant
 * ---------------------------------------------------------------------------
 * `publish`, `unpublish`, `moveUp` and `moveDown` are plain `Action`s, and
 * Filament's own source is explicit that those get NOTHING by default —
 * `Filament\Actions\Concerns\CanBeAuthorized`'s header comment reads
 * "Actions do not have automatic policy-based authorization. Authorization
 * defaults to `null` (allowed for all users)." Until Task 1 of the L9
 * `admin-operations` lane these four carried only record-state guards, which
 * is the Critical finding ledgered at `docs/planning/retrofit-backlog.md:88`.
 * Each now carries both of:
 *
 *  1. `->authorize(...)` — the RENDER/MOUNT gate. It decides whether the
 *     control is drawn and whether `mountAction()` will accept it
 *     (`InteractsWithActions::mountAction()` unmounts an unauthorized action
 *     because `CanBeDisabled::isDisabled()` folds `isAuthorized()` in). This
 *     is presentation-layer safety: it stops the button existing.
 *  2. `authorizeManage(...)` as the FIRST statement of the `->action()`
 *     closure — the actual enforcement. A Livewire component method is
 *     addressable directly over the wire, so "the button was not rendered"
 *     is not a security property. This throws
 *     `Domain\Faq\Exceptions\FaqActionNotAuthorisedException`, before the
 *     Domain Action runs and therefore before any audit row or state change.
 *
 * Layer 1 alone is a UI hint; layer 2 alone leaves a control on screen that
 * errors when clicked. Both, or neither is honest.
 *
 * The `->visible()` / `->hidden()` calls are NOT part of this and must not be
 * confused with it: they answer "is this action MEANINGFUL for this record"
 * (already published, already first in its category), which is orthogonal to
 * "may this actor do it at all". Replacing one with the other in either
 * direction would lose a real guarantee.
 *
 * `app(FaqAuthorizer::class)` / `app(ActorContext::class)` are resolved
 * inline at each call site rather than hoisted into a shared private helper,
 * on purpose: `ActorContext` is a `scoped()` binding whose value is the
 * server's answer for THIS request, and a table definition is a static
 * builder that outlives no request — capturing either in a variable at
 * configure() time would freeze one request's actor into the definition.
 * Resolving per evaluation also keeps every authorization call site
 * individually greppable, which for a security boundary is worth the
 * repetition.
 */
final class FaqArticlesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // Base ordering: grouped by category, then display order within
            // it -- purely a presentation default so an admin scanning the
            // unfiltered list sees each category's articles together in
            // their real order, independent of whatever column the admin
            // clicks to sort by afterwards.
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->orderBy('category_id')->orderBy('sort_order'))
            ->columns([
                TextColumn::make('title')
                    ->label('Judul')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('category.name')
                    ->label('Kategori')
                    ->sortable(),

                TextColumn::make('publish_state')
                    ->label('Status')
                    ->badge()
                    ->color(fn (string $state): string => FaqArticleStatusBadge::color($state))
                    ->formatStateUsing(fn (string $state): string => FaqArticleStatusBadge::label($state))
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label('Urutan')
                    ->sortable(),

                TextColumn::make('updated_at')
                    ->label('Diperbarui')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                TextColumn::make('published_at')
                    ->label('Terakhir dipublikasikan')
                    ->dateTime()
                    ->placeholder('Belum pernah')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('category_id')
                    ->label('Kategori')
                    ->relationship('category', 'name'),

                SelectFilter::make('publish_state')
                    ->label('Status')
                    ->options(array_combine(
                        FaqArticlePublishState::KNOWN_STATES,
                        array_map(
                            fn (string $state): string => FaqArticleStatusBadge::label($state),
                            FaqArticlePublishState::KNOWN_STATES,
                        ),
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),

                Action::make('publish')
                    ->label('Terbitkan')
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('success')
                    ->requiresConfirmation()
                    // Optional reason field -- exposed deliberately. Neither
                    // `PublishFaqArticle` nor `UnpublishFaqArticle` is
                    // `SensitiveActions`-listed (`FaqAuditActions`'s own doc
                    // block), so `Audit::record()` never REQUIRES a reason
                    // for these two actions -- this is not presentation
                    // claiming a guarantee the Action doesn't enforce. It is
                    // offered as a legitimate optional field: an admin who
                    // DOES have a reason worth recording (e.g. "content
                    // reviewed and approved by ops lead") can leave one:
                    // blank submits `reason: null`, same as if the field did
                    // not exist.
                    ->schema([
                        Textarea::make('reason')
                            ->label('Alasan (opsional)')
                            ->rows(2),
                    ])
                    ->authorize(fn (): bool => app(FaqAuthorizer::class)->canManage(app(ActorContext::class)))
                    ->visible(fn (FaqArticle $record): bool => ! $record->isPublished())
                    ->action(function (FaqArticle $record, array $data): void {
                        app(FaqAuthorizer::class)->authorizeManage(app(ActorContext::class));

                        (new PublishFaqArticle)(
                            $record,
                            actorReference: Auth::id() ?? 0,
                            reason: filled($data['reason'] ?? null) ? $data['reason'] : null,
                        );

                        // Explicit, not `->successNotificationTitle()`: a plain
                        // custom `Action`'s user-supplied `->action()` closure
                        // never calls `$this->success()`, so these three verbs
                        // -- the only AC5 verbs with no page of their own --
                        // silently did nothing visible. Quiet confirmation, no
                        // celebration (tasks.md §6.8).
                        Notification::make()
                            ->title('Artikel diterbitkan.')
                            ->success()
                            ->send();
                    }),

                Action::make('unpublish')
                    ->label('Batalkan publikasi')
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->requiresConfirmation()
                    ->schema([
                        Textarea::make('reason')
                            ->label('Alasan (opsional)')
                            ->rows(2),
                    ])
                    ->authorize(fn (): bool => app(FaqAuthorizer::class)->canManage(app(ActorContext::class)))
                    ->visible(fn (FaqArticle $record): bool => $record->isPublished())
                    ->action(function (FaqArticle $record, array $data): void {
                        app(FaqAuthorizer::class)->authorizeManage(app(ActorContext::class));

                        (new UnpublishFaqArticle)(
                            $record,
                            actorReference: Auth::id() ?? 0,
                            reason: filled($data['reason'] ?? null) ? $data['reason'] : null,
                        );

                        Notification::make()
                            ->title('Publikasi artikel dibatalkan.')
                            ->success()
                            ->send();
                    }),

                Action::make('moveUp')
                    ->label('Naikkan urutan')
                    ->icon(Heroicon::OutlinedArrowUp)
                    ->color('gray')
                    ->authorize(fn (): bool => app(FaqAuthorizer::class)->canManage(app(ActorContext::class)))
                    ->hidden(fn (FaqArticle $record): bool => self::isFirstInCategory($record))
                    ->action(function (FaqArticle $record): void {
                        app(FaqAuthorizer::class)->authorizeManage(app(ActorContext::class));

                        self::moveWithinCategory($record, -1);
                    }),

                Action::make('moveDown')
                    ->label('Turunkan urutan')
                    ->icon(Heroicon::OutlinedArrowDown)
                    ->color('gray')
                    ->authorize(fn (): bool => app(FaqAuthorizer::class)->canManage(app(ActorContext::class)))
                    ->hidden(fn (FaqArticle $record): bool => self::isLastInCategory($record))
                    ->action(function (FaqArticle $record): void {
                        app(FaqAuthorizer::class)->authorizeManage(app(ActorContext::class));

                        self::moveWithinCategory($record, 1);
                    }),
            ]);
    }

    /**
     * @return list<int>
     */
    private static function orderedIdsForCategory(int $categoryId): array
    {
        return FaqArticle::forAdmin()
            ->where('category_id', $categoryId)
            // `id` as a deterministic tiebreaker: `UpdateFaqArticleContent`
            // accepts a raw `sort_order` int with no cross-sibling
            // normalisation (see `Schemas\FaqArticleForm`'s helper text on
            // that field), so two articles sharing a `sort_order` value is
            // a reachable, if discouraged, state. Without a tiebreaker,
            // "first"/"last" here could disagree between calls against a
            // duplicate-`sort_order` pair.
            ->orderBy('sort_order')
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    private static function isFirstInCategory(FaqArticle $record): bool
    {
        $ids = self::orderedIdsForCategory($record->category_id);

        return ($ids[0] ?? null) === $record->id;
    }

    private static function isLastInCategory(FaqArticle $record): bool
    {
        $ids = self::orderedIdsForCategory($record->category_id);

        return (end($ids) ?: null) === $record->id;
    }

    private static function moveWithinCategory(FaqArticle $record, int $direction): void
    {
        $orderedIds = self::orderedIdsForCategory($record->category_id);
        $index = array_search($record->id, $orderedIds, true);

        if ($index === false) {
            return;
        }

        $swapWith = $index + $direction;

        if ($swapWith < 0 || $swapWith >= count($orderedIds)) {
            // At the boundary -- the row action is also `hidden()` here,
            // so this is a defensive no-op against a stale button click,
            // not the expected path.
            return;
        }

        [$orderedIds[$index], $orderedIds[$swapWith]] = [$orderedIds[$swapWith], $orderedIds[$index]];

        (new ReorderFaqArticles)(
            $orderedIds,
            actorReference: Auth::id() ?? 0,
        );

        // Sent here rather than in the two row-action closures so the two
        // early returns above (id not in list, boundary click) stay silent
        // no-ops -- nothing moved, so nothing is confirmed.
        Notification::make()
            ->title('Urutan artikel diperbarui.')
            ->success()
            ->send();
    }
}

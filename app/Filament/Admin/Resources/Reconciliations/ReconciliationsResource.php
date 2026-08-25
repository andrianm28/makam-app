<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Reconciliations;

use App\Filament\Admin\Resources\Reconciliations\Pages\ListReconciliations;
use App\Filament\Admin\Resources\Reconciliations\Tables\ReconciliationsTable;
use App\Platform\FinancialLedger\Contracts\LedgerReadAuthorizer;
use App\Platform\FinancialLedger\Exceptions\LedgerReadNotAuthorisedException;
use App\Platform\FinancialLedger\Models\Reconciliation;
use App\Platform\IdentityAccess\ActorContext;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin surface for `reconciliations` — the report `Actions\RunReconciliation`
 * writes for a human to review, plus the header action that feeds it: upload
 * a settlement statement CSV, which is parsed into a `ProviderStatement` and
 * dispatched to `Jobs\ReconcileStatementJob`
 * (`Actions\UploadProviderStatementAction`).
 *
 * ---------------------------------------------------------------------------
 * Access: reused, not reinvented, and reused at two different layers
 * ---------------------------------------------------------------------------
 * This module already has two policies and neither is a perfect fit alone,
 * so this resource composes them the same "coarse mount gate + authoritative
 * per-action re-check" shape `Filament\Admin\Pages\FinanceReports` and
 * `Certificates\Actions\CreateCertificateAction` already establish elsewhere
 * in this codebase:
 *
 *  - `canAccess()` (page mount / navigation) reuses
 *    `Contracts\LedgerReadAuthorizer` — the same policy `FinanceReports`
 *    gates on — because "may this actor see reconciliation reports at all"
 *    is a read-scope question, not a per-badan-usaha decision, and
 *    `ReconciliationAuthorizer` has no entity-less overload to ask it with.
 *  - The upload action itself re-checks with
 *    `Contracts\ReconciliationAuthorizer::authorize($actor, $entityRef)` —
 *    the SAME policy `Actions\ResolveException` uses to gate deciding a
 *    variance — for the SPECIFIC badan usaha the admin named in the form.
 *
 * `ReconciliationAuthorizer`'s own doc block frames itself narrowly, as the
 * policy for "may this actor DECIDE a reconciliation exception", not "may
 * this actor feed the comparison that produces one". Triggering a
 * reconciliation run is a real financial write (it inserts
 * `reconciliation_exceptions` rows) even though it is not itself a decision,
 * so gating it at least as strictly as deciding an exception is the
 * conservative reading — but this is a REUSE of a policy for a purpose its
 * own doc block does not name, and that reuse is flagged for human
 * confirmation rather than treated as self-evidently correct. See this
 * batch's own report for the full reasoning.
 */
final class ReconciliationsResource extends Resource
{
    protected static ?string $model = Reconciliation::class;

    protected static ?string $slug = 'rekonsiliasi';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static ?string $recordTitleAttribute = 'statement_reference';

    public static function canAccess(): bool
    {
        try {
            app(LedgerReadAuthorizer::class)->authorize(app(ActorContext::class));
        } catch (LedgerReadNotAuthorisedException) {
            return false;
        }

        return true;
    }

    public static function table(Table $table): Table
    {
        return ReconciliationsTable::configure($table);
    }

    /**
     * Newest run first — the same "most recent conclusion at the top"
     * ordering `ReconciliationsTable`'s default sort restates for the user;
     * this is the query-level floor beneath it.
     *
     * ---------------------------------------------------------------------------
     * Entity-scoped, not just role-gated — a real gap this closes
     * ---------------------------------------------------------------------------
     * `canAccess()` only proves the actor holds SOME `LedgerReadAuthorizer`
     * grant (entity-less mount check); before this fix, the query itself
     * never filtered by `entityRefs`, so any actor who could see this page at
     * all saw every badan usaha's reconciliation runs — the same
     * query-level-scope-is-mandatory rule `LedgerReadScope`'s own doc block
     * states (`AGENTS.md` §Authorization) and that `FinanceReports` and the
     * `FinancialOverviewWidget` dashboard widget both already apply. An actor
     * with no active grant at all gets an always-false query
     * (`whereRaw('1 = 0')`) rather than an empty `whereIn` (an empty
     * `whereIn([])` is a no-op in some query builder paths — never rely on
     * that to mean "nothing", assert it explicitly).
     */
    public static function getEloquentQuery(): Builder
    {
        try {
            $scope = app(LedgerReadAuthorizer::class)->authorize(app(ActorContext::class));
        } catch (LedgerReadNotAuthorisedException) {
            return Reconciliation::query()->whereRaw('1 = 0');
        }

        return Reconciliation::query()
            ->whereIn('entity_ref', $scope->entityRefs)
            ->latest('ran_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListReconciliations::route('/'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'rekonsiliasi';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Rekonsiliasi';
    }

    public static function getNavigationLabel(): string
    {
        return 'Rekonsiliasi';
    }
}

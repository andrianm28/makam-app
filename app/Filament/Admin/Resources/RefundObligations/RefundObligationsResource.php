<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\RefundObligations;

use App\Domain\RefundObligation\Models\RefundObligation;
use App\Filament\Admin\Resources\RefundObligations\Pages\ListRefundObligations;
use App\Filament\Admin\Resources\RefundObligations\Tables\RefundObligationsTable;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\Payment\Contracts\PaymentActionAuthorizer;
use App\Platform\Payment\Exceptions\PaymentActionNotAuthorisedException;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The admin surface Stage R2 of
 * `docs/superpowers/plans/2026-09-13-sistem-refund.md` asks for: *"daftar
 * kewajiban terutang diurut tenggat, aksi 'catat eksekusi' yang mewajibkan
 * jumlah, tanggal, rujukan transfer, dan unggahan bukti."*
 *
 * ---------------------------------------------------------------------------
 * This screen is a bill, not a report
 * ---------------------------------------------------------------------------
 * Every other financial list in this panel answers "what happened". This one
 * answers "what do we still owe, and to whom is it late" — the plan's framing
 * is that the system *menagih operator*, invoices the operator. That is why
 * the default sort is `due_at` ASCENDING (see `Tables\RefundObligationsTable`)
 * rather than the newest-first ordering `ReconciliationsResource` and
 * `PaymentVerificationsResource` both use: the oldest debt is the most urgent
 * one, and a grieving family waiting longest must not be pushed onto page two
 * by newer arrivals.
 *
 * ---------------------------------------------------------------------------
 * Read-only by construction; the two writes are explicit actions
 * ---------------------------------------------------------------------------
 * `getPages()` registers only `index`. There is no create page (an obligation
 * is born from a rejection — Stage R1 — never typed in by hand) and no edit
 * page (every column that carries a lifecycle decision is force-filled by a
 * domain Action, and `RefundObligation::$fillable` excludes all of them). The
 * only two writes reachable from this panel are
 * `Actions\RecordRefundExecutionAction` and
 * `Actions\ConfirmRefundReceiptAction`, each of which calls its domain Action.
 *
 * ---------------------------------------------------------------------------
 * Authorization: reused, and flagged where the reuse is a judgement call
 * ---------------------------------------------------------------------------
 * `Contracts\PaymentActionAuthorizer` — the same contract gating manual
 * payment verification (`PaymentVerificationsResource`, and the
 * `VerifyManualPaymentController` write path) — is reused here at the same
 * "coarse mount gate + fail-closed query" shape that resource establishes.
 *
 * The reuse is deliberate but is NOT self-evidently correct, and is reported
 * rather than assumed: that authorizer's vocabulary is "may this actor take a
 * payment action", and recording a refund execution is a payment action in
 * every sense that matters (it is the manual counterpart of
 * `PAYMENT_REFUND`). What this codebase has no seam for is the narrower
 * question "may this actor discharge a refund debt specifically", and
 * inventing a rival authorizer here would duplicate an authorization concept
 * that belongs to the Platform payment module, which this stage consumes
 * rather than redefines. Human review of that choice is requested in this
 * lane's report.
 *
 * ---------------------------------------------------------------------------
 * NO row-level scope filter, and that is a real gap, not an oversight
 * ---------------------------------------------------------------------------
 * `refund_obligations` has no cemetery, vendor, or badan-usaha column to scope
 * against — it links to `orders`, which is where any such scope would have to
 * be resolved through. `AGENTS.md` §Authorization and files requires
 * query-level scope by cemetery/vendor/order/case/grave/business entity, so an
 * actor who may see this page at all currently sees every outstanding debt.
 * Adding a join-based scope is a real authorization change on a money screen
 * and therefore a human gate; it is reported as a finding rather than
 * improvised here. Until then the page still fails closed on the coarse
 * check — an unauthorised actor gets `abort(403)`, never an unfiltered query.
 */
final class RefundObligationsResource extends Resource
{
    protected static ?string $model = RefundObligation::class;

    protected static ?string $slug = 'kewajiban-refund';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'id';

    public static function canAccess(): bool
    {
        try {
            app(PaymentActionAuthorizer::class)->authorize(app(ActorContext::class));
        } catch (PaymentActionNotAuthorisedException) {
            return false;
        }

        return true;
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        try {
            app(PaymentActionAuthorizer::class)->authorize(app(ActorContext::class));

            return Response::allow();
        } catch (PaymentActionNotAuthorisedException) {
            return Response::deny('Anda tidak berwenang menangani kewajiban refund.');
        }
    }

    public static function table(Table $table): Table
    {
        return RefundObligationsTable::configure($table);
    }

    /**
     * Oldest deadline first, at the query level — the floor beneath the
     * table's own default sort, so the ordering survives a caller that builds
     * on this query without going through the table.
     *
     * Fails closed on refusal (`abort(403)`) rather than falling through to an
     * unfiltered query, the shape `PaymentVerificationsResource` documents. In
     * practice `canAccess()`/`getAuthorizationResponse()` stop an unauthorised
     * actor before this method runs; this is the backstop for a path that
     * bypasses them.
     */
    public static function getEloquentQuery(): Builder
    {
        try {
            app(PaymentActionAuthorizer::class)->authorize(app(ActorContext::class));
        } catch (PaymentActionNotAuthorisedException) {
            abort(403);
        }

        return RefundObligation::query()->with('order')->orderBy('due_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRefundObligations::route('/'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'kewajiban refund';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Kewajiban Refund';
    }

    public static function getNavigationLabel(): string
    {
        return 'Kewajiban Refund';
    }
}

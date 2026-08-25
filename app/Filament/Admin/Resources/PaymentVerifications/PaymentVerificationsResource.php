<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\PaymentVerifications;

use App\Filament\Admin\Resources\PaymentVerifications\Pages\ListPaymentVerifications;
use App\Filament\Admin\Resources\PaymentVerifications\Pages\ViewPaymentVerification;
use App\Filament\Admin\Resources\PaymentVerifications\Schemas\PaymentVerificationInfolist;
use App\Filament\Admin\Resources\PaymentVerifications\Tables\PaymentVerificationsTable;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\Payment\Contracts\PaymentActionAuthorizer;
use App\Platform\Payment\Exceptions\PaymentActionNotAuthorisedException;
use App\Platform\Payment\Models\PaymentVerification;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * ADM-070 (`docs/product/screen-inventory.md`) — the admin surface for
 * reviewing `payment_verifications`: manual proof-of-payment submissions and
 * the finance/restricted-admin decision recorded against each one. The
 * WRITE side of that decision already exists and is untouched by this
 * resource — `App\Platform\Payment\Http\Controllers\
 * VerifyManualPaymentController` / `App\Platform\Payment\
 * VerifyManualPayment` — this class only lets staff BROWSE the resulting
 * rows. Read-only by construction: `getPages()` below registers only
 * `index`/`view`, never `create`/`edit`.
 *
 * ---------------------------------------------------------------------------
 * Access: the same "coarse mount gate + fail-closed query" shape
 * `AuditEventsResource` uses, minus a row-level scope
 * ---------------------------------------------------------------------------
 * `canAccess()` and `getAuthorizationResponse()` both resolve
 * `Contracts\PaymentActionAuthorizer` — the SAME contract the manual
 * verification write path already uses
 * (`FinanceOrRestrictedAdminPaymentAuthorizer`, bound in
 * `PaymentServiceProvider`), reused directly rather than a new read-scope
 * authorizer: `payment_verifications` has no scopeable column (that
 * authorizer's own class-level doc block), so a dedicated read-scope object
 * would carry no real narrowing value over the existing one. Unlike
 * `AuditEventsResource::getEloquentQuery()`, there is no
 * `whereNotIn(...)`/similar scope filter applied on success — there is
 * nothing on this table to scope against — but the same fail-closed shape
 * still applies: a refusal `abort(403)`s rather than falling through to an
 * unfiltered query.
 */
final class PaymentVerificationsResource extends Resource
{
    protected static ?string $model = PaymentVerification::class;

    protected static ?string $slug = 'verifikasi-pembayaran';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?string $recordTitleAttribute = 'reference';

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
            return Response::deny('Anda tidak berwenang meninjau verifikasi pembayaran.');
        }
    }

    public static function table(Table $table): Table
    {
        return PaymentVerificationsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return PaymentVerificationInfolist::configure($schema);
    }

    /**
     * Newest submission first. No scope filter is applied on success — see
     * class doc block for why `payment_verifications` has nothing to scope
     * against — but this still fails closed on its own: a refusal here
     * throws, `abort(403)`, rather than silently falling through to an
     * unfiltered query. An unauthorised actor never reaches this method in
     * practice (`canAccess()`/`getAuthorizationResponse()` gate the page
     * first).
     */
    public static function getEloquentQuery(): Builder
    {
        try {
            app(PaymentActionAuthorizer::class)->authorize(app(ActorContext::class));
        } catch (PaymentActionNotAuthorisedException) {
            abort(403);
        }

        return PaymentVerification::query()->latest('submitted_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPaymentVerifications::route('/'),
            'view' => ViewPaymentVerification::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'verifikasi pembayaran';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Verifikasi Pembayaran';
    }

    public static function getNavigationLabel(): string
    {
        return 'Verifikasi Pembayaran';
    }
}

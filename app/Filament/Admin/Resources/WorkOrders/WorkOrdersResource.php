<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkOrders;

use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Filament\Admin\Resources\WorkOrders\Pages\ListWorkOrders;
use App\Filament\Admin\Resources\WorkOrders\Pages\ViewWorkOrder;
use App\Filament\Admin\Resources\WorkOrders\Schemas\WorkOrderInfolist;
use App\Filament\Admin\Resources\WorkOrders\Tables\WorkOrdersTable;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use App\Platform\IdentityAccess\Roles\ActorRole;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Admin surface for `work_orders` — fulfillment oversight plus the audited
 * vendor-replacement action (AC7: `App\Domain\VendorFulfillment\Actions
 * \ReplaceVendor`, previously unwired anywhere in the codebase — see
 * `Actions\ReplaceVendorAction`'s doc block).
 *
 * No admin resource covered `work_orders` before this file — this batch's
 * own judgement call for "the right home" (the wiring task's own phrasing),
 * following the shape `App\Filament\Admin\Resources\Subscriptions
 * \SubscriptionsResource` already established for the same P5b domain
 * rather than inventing a new authorization or navigation convention.
 *
 * ---------------------------------------------------------------------------
 * Two authorization bars, not one
 * ---------------------------------------------------------------------------
 * `canAccess()`/`getAuthorizationResponse()` mirror `SubscriptionsResource`
 * exactly: `MasterDataAdminAuthorizerContract` (admin, restricted_admin,
 * operator, finance may all VIEW). The vendor-replacement action itself
 * carries a narrower, independent admin/restricted_admin-only gate
 * (`Actions\ReplaceVendorAction::isAuthorized()`) — replacing a vendor is a
 * state mutation with its own audit trail, not read access to the
 * subscription catalogue, so it gets `CreateCertificateAction`'s stricter
 * two-layer issuer-gate shape instead of the broader master-data view gate.
 * Flagged as a judgement call: nothing in the P5b plan or the domain
 * Action's own doc block names an exact role list for this action.
 */
final class WorkOrdersResource extends Resource
{
    protected static ?string $model = WorkOrder::class;

    protected static ?string $slug = 'order-kerja';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?int $navigationSort = 62;

    public static function canAccess(): bool
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));
        } catch (MasterDataNotAuthorisedException) {
            return false;
        }

        return true;
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        try {
            app(MasterDataAdminAuthorizerContract::class)->authorize(app(ActorContext::class));

            return Response::allow();
        } catch (MasterDataNotAuthorisedException) {
            return Response::deny('Anda tidak berwenang mengelola pesanan kerja perawatan.');
        }
    }

    public static function auditRoleFor(ActorContext $actor): string
    {
        foreach ([ActorRole::ADMIN, ActorRole::RESTRICTED_ADMIN, ActorRole::OPERATOR, ActorRole::FINANCE] as $role) {
            if ($actor->hasRole($role)) {
                return $role;
            }
        }

        return $actor->isAuthenticated() ? 'authenticated_actor' : 'guest';
    }

    public static function table(Table $table): Table
    {
        return WorkOrdersTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return WorkOrderInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkOrders::route('/'),
            'view' => ViewWorkOrder::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'pesanan kerja';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Pesanan Kerja Perawatan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Pesanan Kerja Perawatan';
    }
}

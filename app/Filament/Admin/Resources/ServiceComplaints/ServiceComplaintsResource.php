<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceComplaints;

use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Filament\Admin\Resources\ServiceComplaints\Pages\ListServiceComplaints;
use App\Filament\Admin\Resources\ServiceComplaints\Pages\ViewServiceComplaint;
use App\Filament\Admin\Resources\ServiceComplaints\Schemas\ServiceComplaintInfolist;
use App\Filament\Admin\Resources\ServiceComplaints\Tables\ServiceComplaintsTable;
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
 * Admin surface for `service_complaints` — the first UI anywhere in this
 * codebase to read that table (`FileComplaint` writes it, nothing read it
 * before this file). Mirrors `WorkOrdersResource`'s exact shape: read-only
 * list/view, no create/edit form (complaints are only ever filed via
 * `FileComplaint`, from the customer-facing `CareHistoryPage`), same
 * `MasterDataAdminAuthorizerContract` view gate.
 */
final class ServiceComplaintsResource extends Resource
{
    protected static ?string $model = ServiceComplaint::class;

    /**
     * `ServiceComplaint` has no short human-readable reference column
     * (unlike `WorkOrder::$recordTitleAttribute = 'reference'`) — its real
     * columns are `id`/`work_order_id`/`customer_id`/`complaint_text`/
     * `status`/`resolution_notes`/`resolved_at`/`filed_at`/
     * `make_good_order_id`. `id` is used as-is rather than adding a new
     * accessor for a cosmetic breadcrumb label.
     */
    protected static ?string $recordTitleAttribute = 'id';

    protected static ?string $slug = 'keluhan-layanan';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?int $navigationSort = 63;

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
            return Response::deny('Anda tidak berwenang mengelola keluhan layanan.');
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
        return ServiceComplaintsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return ServiceComplaintInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServiceComplaints::route('/'),
            'view' => ViewServiceComplaint::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'keluhan layanan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Keluhan Layanan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Keluhan Layanan';
    }
}

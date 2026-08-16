<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CemeteryVisitationPolicies;

use App\Domain\Visitation\Models\CemeteryVisitationPolicy;
use App\Filament\Admin\Resources\CemeteryVisitationPolicies\Pages\CreateCemeteryVisitationPolicy;
use App\Filament\Admin\Resources\CemeteryVisitationPolicies\Pages\EditCemeteryVisitationPolicy;
use App\Filament\Admin\Resources\CemeteryVisitationPolicies\Pages\ListCemeteryVisitationPolicies;
use App\Filament\Admin\Resources\CemeteryVisitationPolicies\Schemas\CemeteryVisitationPolicyForm;
use App\Filament\Admin\Resources\CemeteryVisitationPolicies\Tables\CemeteryVisitationPoliciesTable;
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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Admin surface for `cemetery_visitation_policies` — Task 2 (Lane 2 —
 * Visitation) of `docs/superpowers/plans/2026-08-16-p4-memorial-qr-
 * visitation.md`: the operator configuration behind the public `/kunjungan`
 * page (weekday operating hours + daily capacity).
 *
 * ---------------------------------------------------------------------------
 * Single record per cemetery
 * ---------------------------------------------------------------------------
 * The schema has ONE policy per cemetery by construction: `cemetery_id`
 * is unique in the migration, the create form's cemetery Select offers
 * only cemeteries WITHOUT a policy yet (Filament's Select then rejects a
 * value outside the options — a second policy for one cemetery is
 * unrepresentable from the form), and on edit the Select is disabled —
 * the row can never be silently moved onto a second cemetery.
 *
 * ---------------------------------------------------------------------------
 * Every write audits
 * ---------------------------------------------------------------------------
 * Create and edit both commit through `Audit::wrap` with
 * `CEMETERY_VISITATION_POLICY_UPDATED` (one action name for both: it is
 * one policy per cemetery, and the audit subject — the policy row — is
 * what distinguishes a first write from a later one), in the page's
 * `handleRecordCreation()`/`handleRecordUpdate()` overrides, so the row
 * change and its audit record can never be separated.
 *
 * Same access-gate shape as `GravePlotsResource` and the sibling master-
 * data resources: one `canAccess()` + one `getAuthorizationResponse()`
 * delegating to `MasterDataAdminAuthorizerContract`, and one
 * `auditRoleFor()` walking that gate's fixed role precedence for the
 * audit calls.
 */
final class CemeteryVisitationPolicyResource extends Resource
{
    protected static ?string $model = CemeteryVisitationPolicy::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $recordTitleAttribute = 'cemetery.name';

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
        } catch (MasterDataNotAuthorisedException) {
            return Response::deny('Anda tidak berwenang mengelola kebijakan kunjungan.');
        }

        return Response::allow();
    }

    public static function form(Schema $schema): Schema
    {
        return CemeteryVisitationPolicyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CemeteryVisitationPoliciesTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return CemeteryVisitationPolicy::query()->with('cemetery');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCemeteryVisitationPolicies::route('/'),
            'create' => CreateCemeteryVisitationPolicy::route('/create'),
            'edit' => EditCemeteryVisitationPolicy::route('/{record}/edit'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'kebijakan kunjungan';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Kebijakan Kunjungan';
    }

    public static function getNavigationLabel(): string
    {
        return 'Kebijakan Kunjungan';
    }

    /**
     * The single deterministic `actor_role` value for this resource's
     * `Audit::wrap` calls — same reasoning and shape as
     * `GravePlotsResource::auditRoleFor()`.
     */
    public static function auditRoleFor(ActorContext $actor): string
    {
        if (! $actor->isAuthenticated()) {
            return 'guest';
        }

        foreach ([
            ActorRole::ADMIN,
            ActorRole::RESTRICTED_ADMIN,
            ActorRole::OPERATOR,
            ActorRole::FINANCE,
        ] as $role) {
            if ($actor->hasRole($role)) {
                return $role;
            }
        }

        return 'authenticated_actor';
    }
}

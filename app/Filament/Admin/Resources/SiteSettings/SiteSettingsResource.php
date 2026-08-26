<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\SiteSettings;

use App\Filament\Admin\Resources\SiteSettings\Pages\EditSiteSettings;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use App\Platform\IdentityAccess\Roles\ActorRole;
use App\Platform\SiteSettings\Models\SiteSetting;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Admin management surface for the site-wide settings singleton
 * (`site_settings` rows) — the P2 `admin-data-management` plan's Task 1
 * resource.
 *
 * ---------------------------------------------------------------------------
 * Authorization: same shape as `BookingOrderResource` / `CemeteryResource`
 * ---------------------------------------------------------------------------
 * One shared `MasterDataAdminAuthorizerContract` gate answered twice:
 * `canAccess()` is the predicate the panel's page-mount guard and navigation
 * consult; `getAuthorizationResponse()` answers the same question from the
 * same authorizer so the two cannot disagree.
 *
 * ---------------------------------------------------------------------------
 * Single-record page, not Eloquent CRUD
 * ---------------------------------------------------------------------------
 * `site_settings` is append-only key/value data, not an entity: there is no
 * row-per-record list to manage, and the plan explicitly rejects single-record
 * Eloquent plumbing ("keep the page honest and simple"). The resource
 * therefore exposes exactly one page (`Pages\EditSiteSettings`, routed at
 * `/`) whose Livewire form upserts the seven `SiteSetting::KNOWN_KEYS` rows
 * through the domain seam and audits each change.
 */
final class SiteSettingsResource extends Resource
{
    protected static ?string $model = SiteSetting::class;

    protected static ?string $slug = 'pengaturan-situs';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static ?string $recordTitleAttribute = 'key';

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
            return Response::deny('Anda tidak berwenang mengelola pengaturan situs.');
        }
    }

    public static function getPages(): array
    {
        return [
            'edit' => EditSiteSettings::route('/'),
        ];
    }

    /**
     * Single-record resource: there is no index/list page, so the navigation
     * target (and every index-url resolution) is the edit page itself.
     * Filament's default `getIndexUrl()` throws without an `index` page —
     * see `Resource\Concerns\CanGenerateUrls` — and its own message points
     * here for exactly this shape of resource.
     */
    public static function getIndexUrl(array $parameters = [], bool $isAbsolute = true, ?string $panel = null, ?Model $tenant = null, bool $shouldGuessMissingParameters = false): string
    {
        return self::getUrl('edit', $parameters, $isAbsolute, $panel, $tenant, $shouldGuessMissingParameters);
    }

    public static function getNavigationLabel(): string
    {
        return 'Pengaturan Situs';
    }

    /**
     * UI-audit fix (26 Aug 2026): with no override, Filament derives the
     * breadcrumb's first crumb from `get_model_label(SiteSetting::class)`
     * — "Site Setting", English, straight from the model class name — while
     * every other resource in this codebase overrides both label methods
     * (e.g. `PreNeedCaseResource::getModelLabel()` -> 'kasus pra-layanan',
     * `getPluralModelLabel()` -> 'Kasus Pra-Layanan'). Same pattern here,
     * reusing the already-correct `getNavigationLabel()` wording rather than
     * inventing new copy.
     */
    public static function getModelLabel(): string
    {
        return 'pengaturan situs';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Pengaturan Situs';
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
}

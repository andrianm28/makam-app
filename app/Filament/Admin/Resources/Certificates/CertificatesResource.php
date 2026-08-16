<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\Certificates;

use App\Domain\AgreementCertificate\Models\Certificate;
use App\Filament\Admin\Resources\Certificates\Pages\ListCertificates;
use App\Filament\Admin\Resources\Certificates\Pages\ViewCertificate;
use App\Filament\Admin\Resources\Certificates\Schemas\CertificateInfolist;
use App\Filament\Admin\Resources\Certificates\Tables\CertificatesTable;
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
 * Admin surface for `certificates` — Task 2 (P5a, Lane 1) of
 * `docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md`.
 *
 * Same access shape as the P1 resources: one shared
 * `MasterDataAdminAuthorizerContract` gate answered twice (`canAccess()`
 * for the page/navigation mount, `getAuthorizationResponse()` for every
 * row/action predicate) and the same deterministic `auditRoleFor()`.
 *
 * The issuance/revocation/replacement SURFACE is issuer-gated INSIDE the
 * action factories (`Actions\CreateCertificateAction`,
 * `Actions\RevokeCertificateAction`, `Actions\ReplaceCertificateAction`):
 * admin + restricted_admin may issue/revoke/replace, operator and finance
 * may VIEW (list + view) but never write — the same two-layer
 * `->authorize()` + in-closure role check enforced by the domain
 * `CertificateIssuerAuthorizer`.
 */
final class CertificatesResource extends Resource
{
    protected static ?string $model = Certificate::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentCheck;

    protected static ?string $recordTitleAttribute = 'reference';

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
            return Response::deny('Anda tidak berwenang mengelola sertifikat.');
        }
    }

    /**
     * Same deterministic role derivation as `BookingOrderResource` — the
     * actor's highest back-office role, else a labelled fallback.
     */
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
        return CertificatesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CertificateInfolist::configure($schema);
    }

    /**
     * Append-only records, newest first.
     */
    public static function getEloquentQuery(): Builder
    {
        return Certificate::query()->latest('created_at');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCertificates::route('/'),
            'view' => ViewCertificate::route('/{record}'),
        ];
    }

    public static function getModelLabel(): string
    {
        return 'sertifikat';
    }

    public static function getPluralModelLabel(): string
    {
        return 'Sertifikat';
    }

    public static function getNavigationLabel(): string
    {
        return 'Sertifikat';
    }
}

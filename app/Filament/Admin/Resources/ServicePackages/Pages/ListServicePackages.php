<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServicePackages\Pages;

use App\Domain\ServiceCatalog\Actions\DefineServicePackage;
use App\Filament\Admin\Resources\ServicePackages\ServicePackageResource;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\MasterData\Contracts\MasterDataAdminAuthorizerContract;
use App\Platform\IdentityAccess\MasterData\Exceptions\MasterDataNotAuthorisedException;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;

/**
 * List page for `ServicePackageResource`.
 *
 * ---------------------------------------------------------------------------
 * The CreateAction — routes through `Actions\DefineServicePackage`
 * ---------------------------------------------------------------------------
 * `DefineServicePackage` REQUIRES at least one item, so the create modal's
 * schema is `ServicePackageForm`'s repeatable items repeater (the resource's
 * `form()`), and the `->using()` closure hands the validated item lines
 * straight to the domain Action — the ONLY way this module creates a
 * package (`DefineServicePackage`'s own doc block: "Never
 * `ServicePackage::create()`/`ServicePackageVersion::create()` directly from
 * outside this class").
 *
 * The action returns the package with its DRAFT v1 and items, self-auditing
 * `SERVICE_PACKAGE_DEFINED` — so no `Audit::wrap()` here: double-wrapping
 * the action would mint a second audit row for one create.
 *
 * `is_active` is not part of the create form (see
 * `Schemas\ServicePackageForm`'s own doc block: the action forces it true),
 * so the closure does not read it from the payload.
 */
final class ListServicePackages extends ListRecords
{
    protected static string $resource = ServicePackageResource::class;

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label('Buat paket layanan')
                ->modalHeading('Buat paket layanan')
                ->modalWidth('7xl')
                ->successNotificationTitle('Paket layanan dibuat dengan versi draft.')
                ->authorize(fn (): bool => self::actorMayManage())
                ->using(function (array $data): Model {
                    $actor = app(ActorContext::class);

                    return (new DefineServicePackage)(
                        code: (string) $data['code'],
                        name: (string) $data['name'],
                        items: array_map(
                            static fn (array $item): array => [
                                ...$item,
                                'quantity' => (int) $item['quantity'],
                            ],
                            $data['items'],
                        ),
                        actorReference: $actor->identityReference ?? 0,
                        description: filled($data['description'] ?? null) ? (string) $data['description'] : null,
                        actorRole: ServicePackageResource::auditRoleFor($actor),
                        auditSource: AuditSource::Panel,
                    );
                }),
        ];
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

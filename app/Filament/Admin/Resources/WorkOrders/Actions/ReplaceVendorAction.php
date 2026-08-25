<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\WorkOrders\Actions;

use App\Domain\Marketplace\Models\Vendor;
use App\Domain\VendorFulfillment\Actions\ReplaceVendor;
use App\Domain\VendorFulfillment\Models\WorkOrder;
use App\Filament\Admin\Resources\WorkOrders\WorkOrdersResource;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Roles\ActorRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Throwable;

/**
 * The 'Ganti Vendor' header action on the admin `ViewWorkOrder` page —
 * `App\Domain\VendorFulfillment\Actions\ReplaceVendor` (AC7) existed,
 * audited, since the domain lane shipped, but nothing in any UI called it
 * before this file; it also had zero test coverage anywhere in the repo
 * (flagged in the task report).
 *
 * ---------------------------------------------------------------------------
 * Two-layer issuer gate, same shape as `CreateCertificateAction`
 * ---------------------------------------------------------------------------
 * `isAuthorized()` is checked TWICE: once by `WorkOrdersResource::getPage
 * ('view')`'s header action `->authorize()` (the render/mount gate — the
 * button does not even appear for operator/finance, who
 * `WorkOrdersResource::canAccess()` still lets VIEW the resource), and once
 * again as the first act of `run()`, because "the button was not rendered"
 * is not a security property. This is narrower than
 * `WorkOrdersResource`'s own `MasterDataAdminAuthorizerContract` view gate
 * (admin/restricted_admin/operator/finance) — see that resource's doc block
 * for why replacing a vendor gets the stricter admin/restricted_admin-only
 * bar instead.
 *
 * ---------------------------------------------------------------------------
 * The reason field
 * ---------------------------------------------------------------------------
 * `ReplaceVendor::__invoke()` requires a non-optional `string $reason`
 * parameter — this form's `Textarea::make('reason')->required()` is the
 * only source for it, never a hardcoded placeholder string.
 */
final class ReplaceVendorAction
{
    /**
     * @return array<Select|Textarea>
     */
    public static function schema(WorkOrder $record): array
    {
        return [
            Select::make('new_vendor_id')
                ->label('Vendor baru')
                ->options(fn (): array => self::vendorOptions($record))
                ->searchable()
                ->required(),

            Textarea::make('reason')
                ->label('Alasan penggantian')
                ->rows(3)
                ->required(),
        ];
    }

    public static function isAuthorized(): bool
    {
        if (! WorkOrdersResource::canAccess()) {
            return false;
        }

        $actor = app(ActorContext::class);

        return $actor->hasRole(ActorRole::ADMIN) || $actor->hasRole(ActorRole::RESTRICTED_ADMIN);
    }

    public static function run(WorkOrder $record, array $data): void
    {
        if (! self::isAuthorized()) {
            self::deny('Anda tidak berwenang mengganti vendor pesanan kerja ini.');

            return;
        }

        $newVendorId = (string) ($data['new_vendor_id'] ?? '');
        $reason = trim((string) ($data['reason'] ?? ''));

        if ($newVendorId === '' || $reason === '') {
            self::deny('Vendor baru dan alasan penggantian wajib diisi.');

            return;
        }

        if ($newVendorId === $record->vendor_id) {
            self::deny('Vendor baru harus berbeda dari vendor saat ini.');

            return;
        }

        $actor = app(ActorContext::class);

        try {
            app(ReplaceVendor::class)(
                $record,
                $newVendorId,
                $reason,
                (string) $actor->identityReference,
            );

            Notification::make()->success()->title('Vendor pesanan kerja diganti.')->send();
        } catch (Throwable $exception) {
            Notification::make()
                ->danger()
                ->title('Gagal mengganti vendor')
                ->body($exception->getMessage())
                ->send();
        }
    }

    /**
     * Active vendors other than the one currently assigned.
     *
     * @return array<string, string>
     */
    private static function vendorOptions(WorkOrder $record): array
    {
        return Vendor::query()
            ->active()
            ->when($record->vendor_id !== null, fn ($query) => $query->whereKeyNot($record->vendor_id))
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    private static function deny(string $title): void
    {
        Notification::make()->danger()->title($title)->send();
    }
}

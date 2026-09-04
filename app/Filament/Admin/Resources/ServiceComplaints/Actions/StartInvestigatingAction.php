<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceComplaints\Actions;

use App\Domain\VendorFulfillment\Actions\StartInvestigatingComplaint;
use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Filament\Admin\Resources\ServiceComplaints\ServiceComplaintsResource;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Notifications\Notification;
use Throwable;

final class StartInvestigatingAction
{
    public static function isAuthorized(): bool
    {
        return ServiceComplaintsResource::canAccess();
    }

    public static function visible(ServiceComplaint $record): bool
    {
        return $record->status === ComplaintStatus::Open->value;
    }

    public static function run(ServiceComplaint $record): void
    {
        if (! self::isAuthorized()) {
            Notification::make()->danger()->title('Anda tidak berwenang mengelola keluhan ini.')->send();

            return;
        }

        $actor = app(ActorContext::class);

        try {
            app(StartInvestigatingComplaint::class)(
                $record,
                ServiceComplaintsResource::auditRoleFor($actor),
                AuditSource::Panel,
                (string) $actor->identityReference,
            );

            Notification::make()->success()->title('Keluhan sedang diselidiki.')->send();
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Gagal memperbarui status keluhan')->body($exception->getMessage())->send();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceComplaints\Actions;

use App\Domain\VendorFulfillment\Actions\DismissComplaint;
use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Filament\Admin\Resources\ServiceComplaints\ServiceComplaintsResource;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Throwable;

final class DismissComplaintAction
{
    /** @return array<Textarea> */
    public static function schema(): array
    {
        return [
            Textarea::make('reason')
                ->label('Alasan penolakan')
                ->rows(3)
                ->required(),
        ];
    }

    public static function isAuthorized(): bool
    {
        return ServiceComplaintsResource::canAccess();
    }

    public static function visible(ServiceComplaint $record): bool
    {
        return in_array($record->status, [ComplaintStatus::Open->value, ComplaintStatus::Investigating->value], true);
    }

    public static function run(ServiceComplaint $record, array $data): void
    {
        if (! self::isAuthorized()) {
            Notification::make()->danger()->title('Anda tidak berwenang mengelola keluhan ini.')->send();

            return;
        }

        $reason = trim((string) ($data['reason'] ?? ''));

        if ($reason === '') {
            Notification::make()->danger()->title('Alasan penolakan wajib diisi.')->send();

            return;
        }

        $actor = app(ActorContext::class);

        try {
            app(DismissComplaint::class)(
                $record,
                $reason,
                ServiceComplaintsResource::auditRoleFor($actor),
                AuditSource::Panel,
                (string) $actor->identityReference,
            );

            Notification::make()->success()->title('Keluhan ditolak.')->send();
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Gagal menolak keluhan')->body($exception->getMessage())->send();
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\ServiceComplaints\Actions;

use App\Domain\VendorFulfillment\Actions\ResolveComplaint;
use App\Domain\VendorFulfillment\ComplaintStatus;
use App\Domain\VendorFulfillment\Models\ServiceComplaint;
use App\Filament\Admin\Resources\ServiceComplaints\ServiceComplaintsResource;
use App\Platform\Audit\AuditSource;
use App\Platform\IdentityAccess\ActorContext;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Throwable;

final class ResolveComplaintAction
{
    /** @return array<Textarea|Toggle> */
    public static function schema(): array
    {
        return [
            Textarea::make('resolution_notes')
                ->label('Catatan penyelesaian')
                ->rows(3)
                ->required(),

            Toggle::make('create_make_good')
                ->label('Buat pesanan perbaikan (make-good)?')
                ->live(),

            Textarea::make('make_good_notes')
                ->label('Catatan make-good')
                ->rows(2)
                ->visible(fn ($get) => (bool) $get('create_make_good')),
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

        $resolutionNotes = trim((string) ($data['resolution_notes'] ?? ''));
        $createMakeGood = (bool) ($data['create_make_good'] ?? false);
        $makeGoodNotes = $data['make_good_notes'] ?? null;

        if ($resolutionNotes === '') {
            Notification::make()->danger()->title('Catatan penyelesaian wajib diisi.')->send();

            return;
        }

        $actor = app(ActorContext::class);

        try {
            app(ResolveComplaint::class)(
                $record,
                $resolutionNotes,
                $createMakeGood,
                $makeGoodNotes !== null ? (string) $makeGoodNotes : null,
                ServiceComplaintsResource::auditRoleFor($actor),
                AuditSource::Panel,
                (string) $actor->identityReference,
            );

            Notification::make()->success()->title('Keluhan diselesaikan.')->send();
        } catch (Throwable $exception) {
            Notification::make()->danger()->title('Gagal menyelesaikan keluhan')->body($exception->getMessage())->send();
        }
    }
}

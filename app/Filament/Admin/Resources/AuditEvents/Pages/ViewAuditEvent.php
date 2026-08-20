<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuditEvents\Pages;

use App\Filament\Admin\Resources\AuditEvents\AuditEventsResource;
use Filament\Resources\Pages\ViewRecord;

/**
 * View page for `AuditEventsResource` — the full detail infolist
 * (`Schemas\AuditEventInfolist`). No header action: no 'Edit' action is
 * registered, because `AuditEvent` throws on every mutation path
 * (`update()`/`performUpdate()`/`delete()`) and this resource must not offer
 * an affordance whose only outcome is that exception.
 */
final class ViewAuditEvent extends ViewRecord
{
    protected static string $resource = AuditEventsResource::class;
}

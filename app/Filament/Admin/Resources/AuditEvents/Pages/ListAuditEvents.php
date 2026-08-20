<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\AuditEvents\Pages;

use App\Filament\Admin\Resources\AuditEvents\AuditEventsResource;
use Filament\Resources\Pages\ListRecords;

/**
 * List page for `AuditEventsResource` — the audit register. No header
 * action: this resource offers no write path at all (see the resource's own
 * class-level doc block for why), so there is nothing to mount a 'Create'
 * button for.
 */
final class ListAuditEvents extends ListRecords
{
    protected static string $resource = AuditEventsResource::class;
}

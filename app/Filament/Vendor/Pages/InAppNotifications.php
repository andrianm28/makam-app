<?php

declare(strict_types=1);

namespace App\Filament\Vendor\Pages;

use App\Platform\Notification\InAppNotificationInboxQuery;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * `/vendor/notifikasi-aplikasi` — NOTIF-04
 * (`docs/superpowers/plans/2026-09-07-batchm8b-notification-completeness.md`).
 *
 * Same shape as `App\Filament\Admin\Pages\InAppNotifications` and
 * `App\Filament\Operator\Pages\InAppNotifications` — see the admin page's
 * own doc block for the full reasoning.
 *
 * Meaningful the moment a `vendor`-scoped subject resolves an in-app
 * recipient: today that happens for `vendor` scope subjects wherever the
 * matrix's Vendor column is `IN_APP` (e.g. "Payment received"'s "Vendor
 * when allocated", "Order processing"'s "Assigned vendor", "Order
 * completed", "Vendor evidence uploaded"), and NOTIF-01's multi-scope fix
 * in this same PR is what will let `vendor_order`/`marketplace_order`
 * subjects (PR #248, not yet merged as of this fix) ALSO carry a vendor
 * scope entity alongside their other scope. This page ships now rather
 * than as a second follow-up PR once that lands.
 */
class InAppNotifications extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $navigationLabel = 'Notifikasi';

    protected static ?string $title = 'Notifikasi';

    protected static ?string $slug = 'notifikasi-aplikasi';

    protected string $view = 'filament.admin.pages.in-app-notifications';

    public static function getNavigationBadge(): ?string
    {
        $count = app(InAppNotificationInboxQuery::class)->unreadCountForCurrentActor();

        return $count > 0 ? (string) $count : null;
    }
}

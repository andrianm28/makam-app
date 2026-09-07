<?php

declare(strict_types=1);

namespace App\Filament\Operator\Pages;

use App\Platform\Notification\InAppNotificationInboxQuery;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * `/operator/notifikasi-aplikasi` — NOTIF-04
 * (`docs/superpowers/plans/2026-09-07-batchm8b-notification-completeness.md`).
 *
 * The exact same shape as `App\Filament\Admin\Pages\InAppNotifications` —
 * see that class's own doc block for the full reasoning (a plain
 * `Filament\Pages\Page`, not a Resource; no query/authorization logic
 * here). Before this page existed, `App\Livewire\Platform\Notification\
 * InAppNotificationList` was already written panel-agnostic (its own doc
 * block explicitly names "so a future vendor panel can mount the same
 * component") and `InAppNotificationInboxQuery` was already actor-scoped,
 * not panel-scoped — but the ONLY page that mounted the component was the
 * admin one. A cemetery operator holding a real `cemetery` scope grant
 * (the `Pengelola TPU/TPS` matrix column's actual `IN_APP` recipient,
 * e.g. `Renewal submitted`/`Renewal paid,verified`/NOTIF-13's visitation
 * rows) had genuine `in_app_notifications` rows written for them and
 * absolutely no surface in this codebase to ever read them. This page is
 * that surface — no changes to the component or the query were needed,
 * because both already worked correctly for any actor in any panel.
 */
class InAppNotifications extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $navigationLabel = 'Notifikasi';

    protected static ?string $title = 'Notifikasi';

    protected static ?string $slug = 'notifikasi-aplikasi';

    protected string $view = 'filament.admin.pages.in-app-notifications';

    /**
     * Same scoped-count source as the admin page's own badge — see that
     * class's doc block for why the badge is scoped, not global.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = app(InAppNotificationInboxQuery::class)->unreadCountForCurrentActor();

        return $count > 0 ? (string) $count : null;
    }
}

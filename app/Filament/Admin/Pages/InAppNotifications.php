<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Platform\Notification\InAppNotificationInboxQuery;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * `/admin/notifikasi-aplikasi` — the admin panel's scoped in-app
 * notification inbox (task-5-brief.md Step 1).
 *
 * Shape choice — a plain `Filament\Pages\Page`, not a Resource, with the
 * brief's explicit blessing ("a notification inbox is a natural custom
 * `Filament\Pages\Page`"). An inbox is a read-only, actor-scoped list with
 * exactly one transition (mark read); it is not a CRUD table over a record
 * set an admin manages, which is what a Resource would model. The list
 * itself lives in the panel-agnostic
 * `App\Livewire\Platform\Notification\InAppNotificationList` component
 * (the brief's scope note: written panel-agnostic so a future vendor panel
 * can mount the same component when one exists), so this page is a thin
 * shell: it registers the route/navigation and supplies the unread
 * navigation badge. No query and no authorization logic live here — the
 * badge count comes from `InAppNotificationInboxQuery::
 * unreadCountForCurrentActor()`, the same scope-filtered source the list
 * itself renders from.
 */
class InAppNotifications extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $navigationLabel = 'Notifikasi';

    protected static ?string $title = 'Notifikasi';

    protected static ?string $slug = 'notifikasi-aplikasi';

    protected string $view = 'filament.admin.pages.in-app-notifications';

    /**
     * The unread-count badge (task-5-brief.md Step 2). Scoped, not global:
     * the count is the current actor's own scoped unread rows, so the badge
     * leaks nothing outside the same scope boundary the list enforces.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = app(InAppNotificationInboxQuery::class)->unreadCountForCurrentActor();

        return $count > 0 ? (string) $count : null;
    }
}

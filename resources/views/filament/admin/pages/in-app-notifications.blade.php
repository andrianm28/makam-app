{{--
    resources/views/filament/admin/pages/in-app-notifications.blade.php

    View for `App\Filament\Admin\Pages\InAppNotifications`. A thin shell:
    the panel's standard `<x-filament-panels::page>` wrapper (the same
    convention `mfa-settings.blade.php` uses) around the panel-agnostic
    inbox component. All behaviour — scoped list, delivery chips, empty
    state, read transition — lives in
    `App\Livewire\Platform\Notification\InAppNotificationList` and its
    view/partials, so this page stays a route + heading + badge.
--}}
<x-filament-panels::page>
    <livewire:platform.notification.in-app-notification-list />
</x-filament-panels::page>

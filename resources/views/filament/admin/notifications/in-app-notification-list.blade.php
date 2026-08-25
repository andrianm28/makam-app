{{--
    resources/views/filament/admin/notifications/in-app-notification-list.blade.php

    View for `App\Livewire\Platform\Notification\InAppNotificationList`.
    Renders the current actor's SCOPED inbox (query scoping lives in
    `InAppNotificationInboxQuery`, never here) with its delivery-state chips.

    Empty state uses design-system.md §6.2's layout recipe (the same as
    `coming-soon.blade.php`) and the mandatory "Belum ada notifikasi" copy
    (task-5-brief.md Step 3). The list itself is newest-first, with no
    pagination for MVP: each row carries its own delivery chips, so a bare
    "next page" would detach a delivery's state from the notification it
    belongs to; a full paged inbox is a later concern once the inbox grows
    past one screen.

    Unread rows carry an info-intent "Belum dibaca" chip (`--mk-intent-info-*`),
    per design-system §3.6 — a badge with text, never a red dot.

    Delivery chips render via `partials/delivery-state-chip.blade.php` from
    `notification_deliveries` only (AC4). No static "Email & WhatsApp
    terkirim" string anywhere in this file or its partials (grep gate).
--}}
<div class="grid gap-y-4">
    @if ($notifications->isEmpty())
        <div class="flex flex-col items-center gap-3 px-4 py-12 text-center">
            <h2 class="text-lg font-semibold text-neutral-800">Belum ada notifikasi</h2>
            <p class="max-w-prose text-base text-neutral-600">
                Pembaruan untuk area kerja Anda akan muncul di sini.
            </p>
        </div>
    @else
        <ul class="grid gap-y-3">
            @foreach ($notifications as $notification)
                <li
                    @class([
                        'grid gap-y-2 rounded-md border bg-neutral-0 p-4 shadow-sm',
                        'border-info-300' => $notification->read_at === null,
                        'border-neutral-200' => $notification->read_at !== null,
                    ])
                >
                    <div class="flex flex-wrap items-start justify-between gap-2">
                        <h3 class="text-base font-semibold text-neutral-800">{{ $notification->subject }}</h3>
                        @if ($notification->read_at === null)
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="inline-flex items-center gap-1.5 rounded-sm border border-info-300 bg-info-100 px-2 py-0.5 text-xs font-medium text-info-800">
                                    Belum dibaca
                                </span>
                                <x-filament::button
                                    size="sm"
                                    color="primary"
                                    outlined
                                    wire:click="markRead({{ $notification->id }})"
                                >
                                    Tandai dibaca
                                </x-filament::button>
                            </div>
                        @endif
                    </div>
                    <p class="max-w-prose text-base text-neutral-600">{{ $notification->body }}</p>
                    @php
                        // `InAppNotification::deliveries()` is event-scoped
                        // (see that method's doc block for why it cannot be
                        // recipient-scoped without breaking eager loading);
                        // this row shows only this recipient's own channel
                        // rows, never another recipient's sends on the same
                        // event (customer AND operator AND vendor rows share
                        // one event_id).
                        $chips = $notification->deliveries
                            ->where('recipient_ref', $notification->recipient_ref)
                            ->values();
                    @endphp
                    @if ($chips->isNotEmpty())
                        <div class="flex flex-wrap gap-2" aria-label="Status pengiriman">
                            @foreach ($chips as $delivery)
                                @include('filament.admin.notifications.partials.delivery-state-chip', ['delivery' => $delivery])
                            @endforeach
                        </div>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>

{{--
    resources/views/filament/admin/notifications/partials/delivery-state-chip.blade.php

    The ONLY visual mapping a delivery-state list may use — task-5-brief.md
    Step 3: render from `notification_deliveries` via `DeliveryState::
    presentation()`. The label and intent come from the enum; this partial
    adds only the CSS classes for the intent and the human channel name.

    Intent classes are a STATIC map, not concatenated strings, so Tailwind's
    scanner can find them (the same technique `badge.blade.php`'s `$intents`
    map uses). Colours follow the design-system intent tokens: pending ->
    `--mk-intent-pending-*` (warning namespace), success ->
    `--mk-intent-success-*`, neutral -> `--mk-intent-neutral-*`, danger ->
    `--mk-intent-danger-*`.

    A UNAVAILABLE delivery renders one of the enum's own neutral labels — it
    can never render "Terkirim", because "Terkirim" exists only on the
    enum's SENT/DELIVERED branch (AC4: no false sent). The delivery's own
    `failure_message` is passed to `presentation()` so the WA-gate-specific
    "WhatsApp belum tersedia" copy is only used for its real cause, never
    for an unrelated UNAVAILABLE cause (e.g. a missing template) on any
    channel including EMAIL — see `DeliveryState`'s doc block. The raw
    `failure_message` value itself is never rendered here. There is no
    "Email & WhatsApp terkirim" string here or anywhere in the inbox (grep
    gate).
--}}
@props(['delivery'])
@php
    $state = $delivery->state;
    $presentation = $state->presentation($delivery->failure_message);

    $intentClasses = [
        'pending' => 'bg-warning-100 text-warning-800 border-warning-300',
        'success' => 'bg-success-100 text-success-800 border-success-300',
        'neutral' => 'bg-neutral-100 text-neutral-700 border-neutral-300',
        'danger' => 'bg-danger-100 text-danger-800 border-danger-300',
    ];

    $channelLabels = [
        'EMAIL' => 'Email',
        'WA' => 'WhatsApp',
    ];
@endphp
<span
    class="inline-flex items-center gap-1.5 rounded-sm border px-2 py-0.5 text-xs font-medium {{ $intentClasses[$presentation['intent']] ?? $intentClasses['neutral'] }}"
>
    <span class="size-1.5 rounded-full bg-current opacity-60" aria-hidden="true"></span>
    {{ $channelLabels[$delivery->channel] ?? $delivery->channel }} · {{ $presentation['label'] }}
</span>

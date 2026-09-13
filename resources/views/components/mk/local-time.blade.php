{{--
    resources/views/components/mk/local-time.blade.php

    <x-mk.local-time :at="$invoice->issued_at" format="j F Y, H:i" /> — ARCH-14's
    Blade seam for a customer/operator-facing timestamp. Storage and `now()`
    stay UTC (`config('app.timezone')`); this converts to
    `config('app.display_timezone')` (Asia/Jakarta) before formatting, so a
    real customer sees their own local time instead of the raw UTC value.
    Filament panels get the equivalent conversion automatically via
    `Filament\Support\Facades\FilamentTimezone` (wired in
    `AppServiceProvider::boot()`) — this component is for plain Blade views.

    `$at` is any `Illuminate\Support\Carbon`/`Carbon\CarbonInterface`
    instance (an Eloquent `datetime`/`immutable_datetime` cast attribute,
    typically). `null` renders nothing.
--}}
@props([
    'at',
    'format' => 'j F Y, H:i',
])

@php
    $local = $at === null ? null : $at->copy()->setTimezone(config('app.display_timezone', 'Asia/Jakarta'));
@endphp

@if ($local !== null)
    <span {{ $attributes }}>{{ $local->translatedFormat($format) }}</span>
@endif

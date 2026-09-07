{{--
    resources/views/components/mk/money.blade.php

    <x-mk.money :minor="$order->total_minor" /> — ARCH-06's Blade seam for
    money outside Filament (Filament panels use the `->moneyRupiah()` column/entry
    macro registered in `App\Providers\AppServiceProvider::boot()` instead).
    Both route through the ONE `App\Platform\FinancialLedger\Money::format()`
    implementation rather than hand-rolled `number_format()` math.

    `$minor` is an integer number of minor units, never a decimal or a
    pre-divided rupiah figure. `null` renders nothing (the attributes wrapper
    is skipped entirely) so a caller with an optional amount can rely on
    `:minor="$maybeNull"` without an extra `@if` in the view.
--}}
@props(['minor'])

@php
    $formatted = $minor === null ? null : (new \App\Platform\FinancialLedger\Money((int) $minor))->format();
@endphp

@if ($formatted !== null)
    <span {{ $attributes }}>{{ $formatted }}</span>
@endif

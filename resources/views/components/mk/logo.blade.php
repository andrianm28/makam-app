{{--
    resources/views/components/mk/logo.blade.php

    <x-mk.logo> — official Makam.co.id brand mark (ADR-0034, OQ-02 resolved).
    Raster assets from public/brand/ (Task-3 pipeline; PROVISIONAL until OQ-12
    official artwork). The wordmark is LIVE TEXT (Poppins 600 via font-display,
    lowercase per the brand render) — never baked pixels: crisp, accessible,
    token-coloured. Props: size (px), variant (normal|inverse — closed list,
    throws like <x-mk.badge>'s intent), wordmark (bool).
--}}
@props([
    'size' => 32,
    'variant' => 'normal',
    'wordmark' => true,
])

@php
    if (! in_array($variant, ['normal', 'inverse'], true)) {
        throw new InvalidArgumentException("x-mk.logo: unknown variant [{$variant}]");
    }
    $mark = $variant === 'inverse' ? 'brand/mark-inverse-96' : 'brand/mark-96';
@endphp

<span class="inline-flex items-center gap-2">
    <picture>
        <source srcset="{{ asset($mark.'.webp') }}" type="image/webp">
        <img src="{{ asset($mark.'.png') }}" width="{{ $size }}" height="{{ $size }}"
             alt="{{ $wordmark ? '' : 'makam.co.id' }}"
             @if ($wordmark) aria-hidden="true" @endif
             {{ $attributes->merge(['class' => 'shrink-0']) }}>
    </picture>
    @if ($wordmark)
        <span class="font-display font-semibold {{ $variant === 'inverse' ? 'text-neutral-0' : 'text-primary-800' }}">makam.co.id</span>
    @endif
</span>

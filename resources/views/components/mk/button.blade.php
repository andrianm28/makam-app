{{--
    resources/views/components/mk/button.blade.php

    <x-mk.button> — design-system.md §3.1. This is the FIRST of nine `<x-mk.*>`
    primitives; it is the convention reference the rest copy, so read this file
    (not just the spec) before building another one:
      - @props([...]) lists every prop with its default, in the same order the
        spec's props table does.
      - Class composition is base + size + variant, built once in PHP, merged
        exactly once via $attributes->merge(['class' => $classes]) on the root
        element.
      - Every colour/spacing/motion value below is a Tailwind utility that
        traces back to a Layer 1 token in tokens.css (`@theme`), per the
        two-layer model in design-system.md §1.1. None of it is invented here.

    Variants: primary · secondary · tertiary · ghost · danger · link
    Sizes:    sm (36px, desktop admin tables only, §3.1) · md (44px, default)
              · lg (52px, primary CTA)
    States:   idle · hover · focus-visible · active · loading · disabled
--}}
@props([
    'variant' => 'secondary',
    'size' => 'md',
    'type' => 'button',
    'icon' => null,
    'iconTrailing' => null,
    'full' => false,
    'loading' => false,
    'disabled' => false,
    'href' => null,
])

@php
    // A disabled or loading <a> has no reliable "cannot be activated" native
    // semantics the way <button disabled> does, so both states fall back to a
    // real <button> even when `href` was supplied — the href is simply not
    // rendered, since there is nothing to navigate to while the action is
    // unavailable.
    $isLink = ! is_null($href) && ! $loading && ! $disabled;
    $tag = $isLink ? 'a' : 'button';

    $base = 'inline-flex items-center justify-center gap-2 font-medium rounded-md
             transition-[color,background-color,border-color,box-shadow]
             duration-fast ease-standard select-none
             focus-visible:outline-none focus-visible:ring-2
             focus-visible:ring-primary-600 focus-visible:ring-offset-2
             disabled:cursor-not-allowed
             disabled:bg-neutral-100 disabled:text-neutral-500
             disabled:border-neutral-300';

    $sizes = [
        'sm' => 'h-9 px-3 text-sm',
        'md' => 'h-11 px-4 text-base',
        'lg' => 'h-13 px-6 text-base',
    ];

    // `neutral-0` (not Tailwind's built-in `white`) — tokens.css defines
    // --color-neutral-0 explicitly and reuses it as --mk-text-on-brand, so
    // this is the label colour tracing back to a real token, not Tailwind's
    // untouched default palette.
    $variants = [
        'primary'   => 'bg-primary-600 text-neutral-0 border border-transparent hover:bg-primary-700 active:bg-primary-800',
        'secondary' => 'bg-neutral-0 text-primary-700 border border-primary-600 hover:bg-primary-50 active:bg-primary-100',
        'tertiary'  => 'bg-neutral-0 text-neutral-700 border border-neutral-450 hover:bg-neutral-50 active:bg-neutral-100',
        'ghost'     => 'bg-transparent text-primary-700 border border-transparent hover:bg-primary-50 active:bg-primary-100',
        'danger'    => 'bg-danger-600 text-neutral-0 border border-transparent hover:bg-danger-700 active:bg-danger-800',
        'link'      => 'bg-transparent text-primary-600 underline underline-offset-2 hover:text-primary-700 h-auto p-0',
    ];

    // `link` is inline prose, not a sized control, so it keeps its own
    // `h-auto p-0` instead of a fixed control height — mixing $sizes in would
    // fight that. WCAG 2.5.5 exempts inline text links from the 44px target
    // floor, and §3.1 lists `link` as "Inline in prose".
    $sizeClasses = $variant === 'link' ? '' : ($sizes[$size] ?? $sizes['md']);
    $variantClasses = $variants[$variant] ?? $variants['secondary'];

    $classes = trim("$base $sizeClasses $variantClasses " . ($full ? 'w-full' : ''));
@endphp

<{{ $tag }}
    @if ($isLink) href="{{ $href }}" @else type="{{ $type }}" @endif
    @if ($tag === 'button' && ($loading || $disabled)) disabled @endif
    {{ $attributes->merge(['class' => $classes]) }}
    @if ($loading) aria-busy="true" @endif
>
    {{-- The spinner replaces only the leading icon; the label span below
         always renders, so the button never changes width when loading
         starts — a layout shift right as someone taps "Bayar" is the worst
         possible moment for it (§3.1). --}}
    @if ($loading)
        <x-mk.spinner class="size-5 shrink-0" aria-hidden="true" />
    @elseif ($icon)
        <x-dynamic-component :component="'icon.' . $icon" class="size-5 shrink-0" aria-hidden="true" />
    @endif

    <span>{{ $slot }}</span>

    @if ($iconTrailing)
        <x-dynamic-component :component="'icon.' . $iconTrailing" class="size-5 shrink-0" aria-hidden="true" />
    @endif
</{{ $tag }}>

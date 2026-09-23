{{--
    resources/views/components/mk/bottom-nav.blade.php

    <x-mk.bottom-nav> — the five-tab mobile persistent navigation
    primitive, FFI full-visual-clone design doc §3, Stage 2 ticket 02,
    approved by ADR-0044 (resolves design-system.md OQ-04). Follows
    button.blade.php's convention: @props with defaults, classes composed
    once in a single @php block, one $attributes->merge() on the root.

    `active` is an explicit prop, matching header.blade.php's own
    established convention exactly (same pattern: 'active' => null, a
    closed set of string keys) -- NOT request-path auto-detection. This
    component is unwired here; whichever Stage 3 ticket wires it into a
    real page passes `active` explicitly, the same way every page that
    renders <x-mk.header> already must.

    z-bottomnav (1100) sits above z-sticky-cta (900) -- the token whose
    own comment names it "wizard sticky footer", even though its one real
    consumer today (stepper.blade.php) uses it for a top-sticky progress
    header, not a bottom CTA bar. Documented here so whoever next builds
    a real bottom CTA bar knows the intended stacking order without
    re-deriving it.
--}}
@props([
    'active' => null, // 'beranda' | 'pemesanan' | 'perpanjangan' | 'akun' | 'bantuan' | null
])

@php
    $tabs = [
        ['key' => 'beranda', 'label' => 'Beranda', 'href' => '/', 'icon' => 'home'],
        ['key' => 'pemesanan', 'label' => 'Pemesanan', 'href' => '/pemesanan-makam', 'icon' => 'document-text'],
        ['key' => 'perpanjangan', 'label' => 'Perpanjangan', 'href' => '/perpanjangan', 'icon' => 'clock-x'],
        ['key' => 'akun', 'label' => 'Akun', 'href' => '/akun', 'icon' => 'user'],
        ['key' => 'bantuan', 'label' => 'Bantuan', 'href' => '/bantuan', 'icon' => 'question-mark-circle'],
    ];

    $activeClasses = 'text-primary-700 border-t-2 border-primary-600';
    $inactiveClasses = 'text-neutral-700 border-t-2 border-transparent';
@endphp

<nav {{ $attributes->merge(['class' => 'lg:hidden fixed inset-x-0 bottom-0 z-bottomnav h-[var(--mk-bottomnav-total)] bg-neutral-0 border-t border-neutral-200 pb-[var(--mk-safe-bottom)]']) }} aria-label="Navigasi utama">
    <ul class="grid h-[var(--mk-bottomnav-h)] grid-cols-5">
        @foreach ($tabs as $tab)
            <li>
                <a href="{{ $tab['href'] }}"
                    @if ($active === $tab['key']) aria-current="page" @endif
                    class="touch-target flex h-full w-full flex-col items-center justify-center gap-0.5 text-2xs transition-colors duration-fast ease-standard {{ $active === $tab['key'] ? $activeClasses : $inactiveClasses }}"
                >
                    <x-dynamic-component :component="'icon.' . $tab['icon']" class="size-5 shrink-0" aria-hidden="true" />
                    <span>{{ $tab['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>

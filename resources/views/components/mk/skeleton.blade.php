{{--
    resources/views/components/mk/skeleton.blade.php

    <x-mk.skeleton> — loading-placeholder primitive, design doc §3
    (FFI full-visual-clone, Stage 2 ticket 01). Follows button.blade.php's
    established convention: @props lists every prop with its default,
    class composition is base + shape, built once in PHP as static
    literal strings (never string-interpolated -- see the file-header
    comment on card.blade.php for why that specific mistake is dangerous
    here), merged once via $attributes->merge().

    Colour is always --mk-skeleton-base/-sheen (tokens.css), consumed
    through the mk-skeleton-shimmer utility (app.css) -- no colour prop,
    matching every other <x-mk.*> primitive's refusal to accept a raw
    colour override. Reduced motion needs no handling here: tokens.css's
    existing global @media (prefers-reduced-motion: reduce) rule
    collapses any animation-duration to 1ms automatically.
--}}
@props([
    'shape' => 'text',
    'lines' => 3,
    'count' => 1,
    'sectionRhythm' => false,
    'announce' => 'Memuat…',
])

@php
    $base = 'mk-skeleton-shimmer rounded-md';

    $shapeClasses = [
        'text' => 'mk-skeleton-line h-4 w-full mb-2 last:mb-0',
        'card' => 'mk-skeleton-card h-40 w-full rounded-lg',
        'media' => 'mk-skeleton-media aspect-video w-full rounded-lg',
        'section' => 'mk-skeleton-section h-64 w-full rounded-lg' . ($sectionRhythm ? ' py-section' : ''),
    ];

    $shapeClass = $shapeClasses[$shape] ?? $shapeClasses['text'];
    $instanceCount = $shape === 'text' ? 1 : max(1, (int) $count);
    $lineCount = $shape === 'text' ? max(1, (int) $lines) : 1;
@endphp

<div {{ $attributes->merge(['class' => 'block']) }} aria-busy="true">
    <span class="sr-only">{{ $announce }}</span>

    @for ($i = 0; $i < $instanceCount; $i++)
        @if ($shape === 'text')
            @for ($j = 0; $j < $lineCount; $j++)
                <div class="{{ $base }} {{ $shapeClass }}" aria-hidden="true"></div>
            @endfor
        @else
            <div class="{{ $base }} {{ $shapeClass }}" aria-hidden="true"></div>
        @endif
    @endfor
</div>

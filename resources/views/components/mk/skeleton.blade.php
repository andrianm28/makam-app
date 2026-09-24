{{--
    resources/views/components/mk/skeleton.blade.php

    <x-mk.skeleton> — loading-placeholder primitive, design-system.md
    §6.1 (component-backed form) and the FFI full-visual-clone design
    doc §3, Stage 2 ticket 01. Follows button.blade.php's established
    convention: @props lists every prop with its default, class
    composition is base + shape, built once in PHP as static literal
    strings (never string-interpolated -- see card.blade.php's own
    file-header comment for the incident this avoids), merged once via
    $attributes->merge().

    Colour is --mk-skeleton-base/-sheen (tokens.css §2.11 COMPONENT
    TOKENS), consumed through the mk-skeleton-shimmer utility (app.css)
    -- no colour prop, matching every other <x-mk.*> primitive's refusal
    to accept a raw colour override. Reduced motion has an explicit
    static end state in that utility's own @media block (base colour
    only, no gradient) -- tokens.css's global 1ms-collapse rule alone
    would leave a static two-tone gradient visible, which is not what
    "base colour only" means.

    Unknown `shape` falls back to `text`, matching card.blade.php's
    documented defensive-fallback convention (not icon-medallion's/
    badge's throw-on-unknown-value convention) -- a decided choice,
    covered by its own test, not an accident of the ?? operator.
--}}
@props([
    'shape' => 'text',
    'lines' => 3,
    'count' => 1,
    'sectionRhythm' => false,
    'announce' => 'Memuat…',
])

@php
    $shimmer = 'mk-skeleton-shimmer';

    $shapeClasses = [
        'text' => $shimmer . ' h-4 w-full rounded-sm',
        'card' => $shimmer . ' h-40 w-full rounded-lg',
        'media' => $shimmer . ' aspect-video w-full rounded-lg',
        'section' => $shimmer . ' h-64 w-full rounded-lg',
    ];

    // Normalize once -- every branch below checks $resolvedShape, never the
    // raw $shape prop, so an unknown value falls back to "text" both
    // visually (the CSS classes) AND structurally (which branch renders).
    $resolvedShape = array_key_exists($shape, $shapeClasses) ? $shape : 'text';
    $shapeClass = $shapeClasses[$resolvedShape];
    $instanceCount = max(1, (int) $count);
    $lineCount = max(1, (int) $lines);
@endphp

<div {{ $attributes->except('aria-busy')->merge(['class' => 'block', 'aria-busy' => 'true']) }}>
    <span class="sr-only">{{ $announce }}</span>

    @if ($resolvedShape === 'section' && $sectionRhythm)
        <div class="py-section lg:py-section-lg">
            <div class="{{ $shapeClass }}" aria-hidden="true"></div>
        </div>
    @else
        <div class="space-y-2">
            @for ($i = 0; $i < $instanceCount; $i++)
                @if ($resolvedShape === 'text')
                    <div class="space-y-2">
                        @for ($j = 0; $j < $lineCount; $j++)
                            <div class="{{ $shapeClass }}" aria-hidden="true"></div>
                        @endfor
                    </div>
                @else
                    <div class="{{ $shapeClass }}" aria-hidden="true"></div>
                @endif
            @endfor
        </div>
    @endif
</div>

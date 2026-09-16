{{--
    resources/views/components/mk/icon-medallion.blade.php

    <x-mk.icon-medallion> — design-system.md §3.3a. Added 19 Aug 2026 for the
    homepage visual refresh: a decorative icon-or-numeral-on-a-tinted-tile
    figure, used inside <x-mk.card>'s default slot (service cards, Cara
    Kerja steps, trust points) — never a standalone status indicator, and
    never a substitute for a real text label.

    Convention matches <x-mk.button> (read that file first): @props([...])
    with defaults, classes composed once in a single PHP block, one
    $attributes->merge() on the root element.

    --- Why `rounded-xl`, not `rounded-full` ---
    tokens.css §1.7 / design-system.md §1.5 restrict `--radius-full` to
    "avatar, stepper dot, progress track ONLY." A circular badge is the
    obvious "interactive" shape (and the one kitabisa.co.id itself uses),
    but it is out for this component specifically — squircle tiles instead,
    which also keeps this visually distinct from that benchmark rather than
    copying it, per the user's own "jangan terlalu mirip" instruction.

    --- Why `tone="leaf"` is inside the Leaf cage, not an exception to it ---
    design-system.md §1.2(b) restricts `secondary` (Leaf) to "50-200 as
    surface tint, 700-900 as text on those tints, 300-400 as decorative
    rules/icons" — never a fill, badge, button, or alert (§9.2 MUST-NOT #7).
    `tone="leaf"` renders `bg-secondary-100 text-secondary-800`: a surface
    tint with text on it, exactly the permitted pairing, not a fill. Both
    `primary-800 on primary-100` and `secondary-800 on secondary-100` are
    already asserted in docs/design/verify-contrast.py (lines pre-dating
    this component), so no new contrast pair was needed for the tiles
    themselves. This component must never render inside a context carrying
    order/payment/availability status, where a Leaf tile could be misread
    as a `success` state (Leaf sits ~23° from success on the hue wheel,
    design-system.md §1.2(b)) — it is `aria-hidden` and marketing-only.

    --- Why `tone="brand"` exists, and why it is a fill where the other two
        are tints (added 14 Sep 2026, ADR-0040) ---
    `earth` and `leaf` both paint a 100-shade tile with an 800-shade mark on
    it. Neither is a brand FIELD, and the kamboja benchmark's §2.2 survey of
    the live homepage found the brand colour filling exactly one element on
    the whole page (the 160x52 `Pesan Makam` button) while appearing 46 times
    as text — brand as ink, almost never as area. `brand` renders
    `bg-primary-600 text-neutral-0`: a genuine Earth field, the same fill
    <x-mk.button variant="primary"> uses, at medallion scale.
    Contrast needs no new verify-contrast.py pair — "white on primary-600"
    is already asserted there at 10.25:1. The tile is aria-hidden
    decoration, so that is a floor it clears, not one it must meet.
    This tone does NOT relax §3.3a's "never adjacent to order/payment/
    availability data" rule — it tightens the reason for it, because a filled
    tile reads louder than a tint. It is also not a licence to fill a
    medallion with any other family: `secondary` (Leaf) stays caged by
    §1.2(b)/§9.2 MUST-NOT #7 and must never gain a fill tone here.

    --- Three internal $tones entries MUST be static complete literal strings ---
    Never build the class name by interpolating $tone at request time (e.g.
    "bg-secondary-{...}"). Tailwind's @source scanner reads file TEXT for
    literal class-shaped strings; it cannot execute PHP, so an interpolated
    class is invisible to it and silently generates no CSS. card.blade.php
    and badge.blade.php both carry doc-block scars from getting this wrong
    once (25 Jul 2026) — this mirrors their fix, not their mistake.

    Icons follow <x-mk.button>'s `x-dynamic-component :component="'icon.' .
    $icon"` convention — omit `icon` and use the default slot instead for a
    numeral (Cara Kerja's ordered steps: a number is the correct affordance
    for an <ol> item, not an icon).
--}}
@props([
    'icon' => null,
    'tone' => 'earth',
    'size' => 'md',
])

@php
    // Closed list, same defensive throw-on-unknown pattern badge.blade.php
    // uses for $intent — a silently-wrong tone on a brand-colour surface is
    // exactly the kind of defect that pattern exists to catch loud.
    $tones = [
        'earth' => 'bg-primary-100 text-primary-800',
        'leaf'  => 'bg-secondary-100 text-secondary-800',
        'brand' => 'bg-primary-600 text-neutral-0',
    ];

    if (! array_key_exists($tone, $tones)) {
        throw new InvalidArgumentException(
            "Unsupported <x-mk.icon-medallion> tone [{$tone}]. Known tones: ".implode(', ', array_keys($tones)).'.'
        );
    }

    $sizes = [
        'md' => 'size-11',
        'lg' => 'size-13',
    ];
    $sizeClasses = $sizes[$size] ?? $sizes['md'];

    $iconSizes = [
        'md' => 'size-5',
        'lg' => 'size-6',
    ];
    $iconSizeClasses = $iconSizes[$size] ?? $iconSizes['md'];

    $base = 'inline-flex items-center justify-center rounded-xl font-semibold';
    $classes = trim("$base $sizeClasses {$tones[$tone]}");
@endphp

<span {{ $attributes->merge(['class' => $classes]) }} aria-hidden="true">
    @if ($icon)
        <x-dynamic-component :component="'icon.' . $icon" class="{{ $iconSizeClasses }} shrink-0" aria-hidden="true" />
    @else
        {{ $slot }}
    @endif
</span>

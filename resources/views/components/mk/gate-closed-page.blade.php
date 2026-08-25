{{--
    resources/views/components/mk/gate-closed-page.blade.php

    <x-mk.gate-closed-page> — design-system.md §6.4 "Authorization failure",
    specifically its "Gate closed" row: "Explain the capability is not yet
    available + the documented fallback path." Never a raw 403/404 for a
    gated route (§6.4: "Never render a raw 403/404 for a gated route") —
    this component IS the explanatory page a route renders instead, per
    information-architecture.md §4 (quoted in §6.4): "Route unavailable
    karena gate harus memberi explanatory page, bukan 404 generik."

    This is the "Gate closed" case ONLY — §6.4 names two other authorization-
    failure cases ("Not signed in", "Signed in, no access") with their own
    message shapes; those are `platform-identity-and-access` territory, not
    this spec's, and are not built by this component. Do not repurpose this
    component for a plain 403/404 — its copy assumes "the capability exists
    but is not yet available", which is only true for the gate-closed case.

    No app layout (`<x-app-layout>`, a `resources/views/layouts/*` file) exists
    in this repository yet — same "component-only, nothing routes to it yet"
    state every other `<x-mk.*>` primitive is in. This component therefore
    renders a page BODY, not a full HTML document; whatever route eventually
    serves a closed gate wraps this in its own layout once one exists.

    Layout follows §6.2's already-documented empty-state recipe verbatim
    (`flex flex-col items-center gap-3 py-12 text-center`, icon `size-12
    text-neutral-400`, title `text-lg font-semibold text-neutral-800`, body
    `text-base text-neutral-600 max-w-prose`) rather than inventing a new
    page-level layout recipe — §6.4 does not document its own literal
    classes, and reusing an already-specified, already-token-backed pattern
    is both consistent (§2.3 DO) and avoids adding an unreviewed visual
    decision for what is functionally the same "nothing to show here, here
    is why and what to do" shape.

    Icons follow <x-mk.button>'s `x-dynamic-component :component="'icon.' .
    $icon"` convention. FLAG: no `icon.*` components exist in this repo yet
    (same pre-existing gap already present in button/badge/alert.blade.php);
    the `icon` prop is honoured only when a caller supplies one, so this
    component never fabricates the missing namespace itself.

    Slots: default (the "why", plain-Indonesian explanation — required),
    `fallback` (the documented fallback path/CTA — e.g. an
    <x-mk.button href="...">), `support` (the support/help link, §6.10 —
    every transactional screen needs one; this is the gate-closed page's
    version of it).
--}}
@props([
    'heading',
    'icon' => null,
])

<div {{ $attributes->merge(['class' => 'mx-auto flex max-w-content flex-col items-center gap-3 px-4 py-12 text-center']) }}>
    @if ($icon)
        <x-dynamic-component :component="'icon.' . $icon" class="size-12 text-neutral-400" aria-hidden="true" />
    @endif

    <h1 class="text-lg font-semibold text-neutral-800">{{ $heading }}</h1>

    <div class="max-w-prose text-base text-neutral-600">
        {{ $slot }}
    </div>

    @isset($fallback)
        <div class="pt-2">
            {{ $fallback }}
        </div>
    @endisset

    @isset($support)
        <div class="pt-4 text-sm text-neutral-600">
            {{ $support }}
        </div>
    @endisset
</div>

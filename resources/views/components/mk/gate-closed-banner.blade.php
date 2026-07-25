{{--
    resources/views/components/mk/gate-closed-banner.blade.php

    <x-mk.gate-closed-banner> — design-system.md §6.9 "Gated fallback mode
    banner". This spec (`platform-feature-gate`) owns this contract per
    tasks.md's "Design system" section: "This spec owns the fallback-banner
    contract the whole product renders."

    Extends <x-mk.alert> (§3.8) rather than forking it — §9.2 MUST #2:
    "Use the <x-mk.*> primitives in §3. Extend them rather than forking."
    Every colour/spacing value rendered here still traces back to
    <x-mk.alert>'s own token-backed `$intents` map; nothing new is invented
    in this file.

    §6.9's placement note ("directly below the header, above <main>,
    full-bleed background with contained text") is a PAGE-layout concern,
    not this component's own — deliberately NOT attempted here by fighting
    <x-mk.alert>'s card-style rounded corners with conflicting override
    utilities (`rounded-lg` vs `rounded-none` compiled-CSS precedence is
    not something this batch can verify without a real Tailwind build on
    this host, and guessing at it would risk exactly the kind of
    unverified visual claim `AGENTS.md` warns against). The caller achieves
    "full-bleed" the same way any full-width banner does: place
    `<x-mk.gate-closed-banner>` inside a full-width wrapper positioned
    directly below the header and above `<main>`, per §6.9's placement
    rule — this component itself renders <x-mk.alert>'s normal contained
    card look and forwards any extra classes a caller passes (via
    `$attributes`) unchanged.

    --- Server-resolved input only (AC2) ---
    The `intent`/`dismissible` pair is expected to come from a
    `GateFallback` value object (`App\Platform\FeatureGate\GateFallback`),
    itself only ever produced by a `Modes\*` enum's `fallback()` method,
    itself only ever driven by `FeatureGateResolver::isOpen()` reading the
    database. Nothing in this component (or anywhere upstream of it) reads
    a request header, query string, or cookie — a client cannot influence
    which banner renders. Accepting the plain `intent`/`dismissible`
    scalars directly (rather than requiring a `GateFallback` object prop)
    keeps this component framework-agnostic and easy to unit-test in
    isolation; the caller is responsible for sourcing them from the server
    resolution, never inventing them locally.

    --- Not dismissible for a payment/receipt-changing mode (§6.9) ---
    `dismissible` defaults to `false` — the safer default for a component
    whose entire purpose is showing up on money-affecting screens. A
    caller must explicitly opt in for the two informational modes
    (`WhatsAppMode`, `GraveSearchMode`) that §6.9 allows to be dismissed —
    see `Modes\WhatsAppMode::fallback()` / `Modes\GraveSearchMode::fallback()`
    for where that `true` is decided, once per mode, not per call site.

    --- `live="polite"` (§7.4) ---
    This banner is an ambient, already-on-the-page notice, not a
    post-submit validation summary — `<x-mk.alert>`'s `polite` default
    (role="status" aria-live="polite") is correct here and is passed
    through explicitly so a caller cannot accidentally override it to
    `assertive` (which §6.3 reserves for validation summaries) by omission.
--}}
@props([
    'intent' => 'info',
    'dismissible' => false,
    'title' => null,
])

<x-mk.alert
    :intent="$intent"
    :dismissible="$dismissible"
    :title="$title"
    live="polite"
    {{ $attributes }}
>
    {{ $slot }}
</x-mk.alert>

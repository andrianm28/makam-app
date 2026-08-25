{{--
    resources/views/components/mk/alert.blade.php

    <x-mk.alert> — design-system.md §3.8. Inline banner used for validation
    summaries, gated fallback-mode notices (§6.9), provider-unavailable
    states (§6.5), and any other status message that needs more than a
    badge.

    Same status → intent boundary as <x-mk.badge>: §3.7 requires the
    status → intent resolution to happen in one place
    (`app/Support/Design/StatusIntent.php`, which does not exist in this
    repo yet — not fabricated here). This component only renders whatever
    `intent` string it is given; it never switches on a status enum.

    --- Colour recipe and why it does NOT reuse `--mk-intent-*` ---
    §3.8's base recipe is explicit: left border = the intent's `600`,
    background = the intent's `50`, text = the intent's `800`. That is a
    DIFFERENT shade triad from the badge's `--mk-intent-{intent}-*`
    aliases (tokens.css §2.10 defines those as `800`-fg on `100`-bg with a
    `300`-border — the one exception is `urgent`, which already happens to
    sit on `800/50/600`, because tokens.css deliberately built the urgent
    alias to read as a strong-bordered alert, not a badge).
    Rather than reach for `--mk-intent-*` and get the wrong shades for
    five of six intents, this component maps `intent` straight to the
    matching `--color-{family}-*` scale (tokens.css §1.4), which already
    has a full Tailwind utility namespace (`--color-*` → `bg-info-50`,
    `border-l-danger-600`, `text-success-800`, per design-system.md §8.2's
    utility table). That means zero arbitrary-value syntax is needed here
    — every class below is an ordinary generated Tailwind utility.

    Family per intent (tokens.css §1.4 / §2.10 comments): neutral→neutral,
    info→info, pending→warning, success→success, danger→danger,
    urgent→warning (§2.10: "URGENT is an ALIAS of warning, not a new
    hue" — so `pending` and `urgent` intentionally render with the same
    literal class string here; they read identically in colour and are
    told apart by their copy, exactly as tokens.css intends).

    Same JIT-scanning discipline as the badge: $intents below is a STATIC
    ARRAY of complete, literal class strings, never built by interpolating
    `$intent` into a class name at request time.

    --- a11y: `live` → role/aria-live (§3.8 / §7.4) ---
    `assertive` → `role="alert"` (an ARIA "alert" role is an implicit
    assertive live region on its own; no extra `aria-live` attribute is
    needed or added). Use this for a validation summary that appears after
    form submit (§6.3).
    `polite`    → `role="status" aria-live="polite"`. Use this for an
    ambient/pre-existing notice (gated fallback banner §6.9, a pending
    state that was already on the page).
    `off`       → no role, no aria-live. The alert is still visible; it
    just isn't announced as a live region (e.g. it is present on initial
    page load and doesn't need an interruption).
    Default is `polite` — the ambient case is the more common one; a
    validation summary must opt in to `assertive` explicitly.

    --- Dismiss button ---
    44px target via the existing `touch-target` utility (app.css — not
    edited here, only consumed) and `aria-label="Tutup"`, exactly as §3.8
    specifies (Indonesian label taken verbatim, not translated). There is
    no `autoDismiss`/`timeout` prop on this component at all — §3.8 says
    "Never auto-dismiss an error" and the safest way to honour that is to
    not expose the capability in the first place, for ANY intent.
    Dismissal is Alpine-only (`x-data`/`x-show`); Livewire 4 ships Alpine
    by default (composer.json: livewire/livewire ^4.0), so no extra script
    tag is introduced. It is only wired up when `dismissible` is true, so
    a non-dismissible alert (e.g. a validation summary, which should not
    normally be dismissed) stays plain markup with no JS dependency at
    all — if JS never runs, the alert simply stays visible, which is the
    safe failure mode.

    --- `urgent` and the no-alarm rule (§2.3) ---
    `urgent` uses the exact same literal class string as `pending` (both
    alias `warning`), so there is no visual escalation beyond a slightly
    different structural context callers give it (§3.8: "always with
    explicit availability text"). This component adds no animation, no
    flashing, and no default icon of its own for any intent — `icon` is
    caller-supplied, so nothing here can silently turn into a countdown or
    an alarm.

    Icons follow <x-mk.button>'s `x-dynamic-component :component="'icon.'
    . $icon"` convention. FLAG: no `icon.*` components exist in this repo
    yet (same pre-existing gap already present in button.blade.php); this
    file does not fabricate them, including for the close button's icon.
--}}
@props([
    'intent' => 'info',
    'title' => null,
    'dismissible' => false,
    'icon' => null,
    'live' => 'polite',
])

@php
    $base = 'flex items-start gap-3 rounded-lg border-l-4 p-4';

    // Complete, literal per-intent strings — see file header. Keep every
    // value fully written out; do not refactor into interpolation.
    $intents = [
        'neutral' => 'border-l-neutral-600 bg-neutral-50 text-neutral-800',
        'info'    => 'border-l-info-600 bg-info-50 text-info-800',
        'pending' => 'border-l-warning-600 bg-warning-50 text-warning-800',
        'success' => 'border-l-success-600 bg-success-50 text-success-800',
        'danger'  => 'border-l-danger-600 bg-danger-50 text-danger-800',
        'urgent'  => 'border-l-warning-600 bg-warning-50 text-warning-800',
    ];

    $intentClasses = $intents[$intent] ?? $intents['info'];
    $classes = trim("$base $intentClasses");

    // live → role/aria-live mapping (§3.8 / §7.4). Anything not one of the
    // three documented values falls back to the safer `polite` ambient
    // case rather than silently going assertive.
    $live = in_array($live, ['polite', 'assertive', 'off'], true) ? $live : 'polite';
@endphp

<div
    {{ $attributes->merge(['class' => $classes]) }}
    @if ($live === 'assertive') role="alert"
    @elseif ($live === 'polite') role="status" aria-live="polite"
    @endif
    @if ($dismissible) x-data="{ dismissed: false }" x-show="!dismissed" @endif
>
    @if ($icon)
        <x-dynamic-component :component="'icon.' . $icon" class="size-5 shrink-0" aria-hidden="true" />
    @endif

    <div class="min-w-0 flex-1 space-y-1">
        @if ($title)
            <p class="font-semibold">{{ $title }}</p>
        @endif

        <div class="text-sm">
            {{ $slot }}
        </div>

        @isset($action)
            <div class="pt-1">
                {{ $action }}
            </div>
        @endisset
    </div>

    {{--
        FIXED 26 Jul 2026: this button unconditionally required
        `icon.x-mark` via <x-dynamic-component> — a namespace that does not
        exist anywhere in this repo (OQ-05, still open). Every `dismissible`
        alert has been crashing since this file was first built; nothing
        had exercised it with a real render until Batch 3.2's
        GateClosedBladeComponentsRenderTest. Fixed the same way
        modal.blade.php's own close button already does it: a hand-drawn
        inline SVG X, not a dependency on the undecided icon set. Once
        OQ-05 resolves, both this and modal.blade.php's close button can
        switch to the real icon component together, in one pass.
    --}}
    @if ($dismissible)
        <button
            type="button"
            x-on:click="dismissed = true"
            aria-label="Tutup"
            class="touch-target inline-flex shrink-0 items-center justify-center rounded-md text-current focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
        >
            <svg class="size-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
            </svg>
        </button>
    @endif
</div>

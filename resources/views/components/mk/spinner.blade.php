{{--
    resources/views/components/mk/spinner.blade.php

    <x-mk.spinner> — small loading indicator used by <x-mk.button loading>
    (design-system.md §3.1) and available standalone wherever §6.1 "Loading"
    calls for an inline spinner.

    Purely decorative by default: pass `aria-hidden="true"` (as x-mk.button
    does) when a surrounding element already announces the busy state, e.g.
    the button's own `aria-busy="true"`. Pass `label` only when the spinner
    is the SOLE loading signal — it then gets `role="status"` and an
    accessible name via <title>, per design-system.md §7.4 (loading state is
    announced via aria-busy/role="status", never by swapping visible text).

    Rotation uses Tailwind's built-in `animate-spin` (1s linear infinite).
    tokens.css §1.6 only defines durations for discrete state transitions
    (capped at 400ms); a continuous spinner isn't a transition, so there is
    no Layer 2 motion token to reference here — this is Tailwind's own
    default animation, not an invented value.
--}}
@props([
    'label' => null,
])

<svg
    {{ $attributes->merge(['class' => 'animate-spin']) }}
    viewBox="0 0 24 24"
    fill="none"
    @if ($label) role="status" @endif
>
    @if ($label)
        <title>{{ $label }}</title>
    @endif
    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
    <path class="opacity-90" fill="currentColor" d="M4 12a8 8 0 0 1 8-8V0C5.373 0 0 5.373 0 12h4z"></path>
</svg>

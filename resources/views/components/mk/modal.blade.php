{{--
    resources/views/components/mk/modal.blade.php

    <x-mk.modal> — design-system.md §3.4, "Modal and Bottom Sheet". ONE
    component, not two: below `md` it is a bottom sheet pinned to the bottom
    edge with rounded top corners; at `md` and above it becomes a centred
    dialog. The switch is pure `md:` responsive classes on a single element,
    per the spec's own worked example (§3.4's Sheet/Dialog table rows), not
    two templates behind a breakpoint check.

    Convention: follows button.blade.php/spinner.blade.php — @props with every
    prop defaulted, one @php block composing classes once, `neutral-0` instead
    of Tailwind's `white` (the spec's literal example says `bg-white`; §3.1's
    established convention is followed here instead, since neutral-0 traces to
    a real token and `white` does not).

    Props: size (sm|md|lg) · title (required — used for aria-labelledby) ·
    dismissible (default true) · intent (optional accent, see below).

    Slots: default ($slot) — dialog body. `footer` (optional, named) — action
    buttons; rendered `flex-col-reverse` on mobile so the primary action sits
    last in markup / first in visual thumb-reach order, `flex-row justify-end`
    at `md+` (§3.4, footer order is an a11y requirement, not a style choice).

    ---------------------------------------------------------------------------
    LAYOUT NOTE (judgement call beyond the literal spec table): §3.4 gives the
    Sheet/Dialog element's OWN classes (`fixed inset-x-0 bottom-0 ...` mobile,
    `md:relative md:inset-auto md:mx-auto md:my-16 ...` desktop) but does not
    spell out what those classes render *inside*. Taken completely literally,
    switching the panel to `md:relative` with no positioned ancestor would drop
    it into normal document flow and push page content around instead of
    overlaying it. To make the given classes behave correctly as an overlay at
    both breakpoints, this file adds one plain positioning ancestor — a `fixed
    inset-0` root (no visual styling, no z-index of its own) that anchors the
    mobile `fixed` sheet to the viewport exactly as before, and gives the
    desktop `relative` dialog a full-viewport box to centre inside via
    `mx-auto`/`my-16` without disturbing page layout. This is an interpretation
    of an underspecified part of §3.4, not a documented requirement — flagging
    it for design review rather than silently picking one reading.

    ---------------------------------------------------------------------------
    NOT IMPLEMENTED — needs an Alpine.js (or equivalent) behaviour layer that
    does not exist anywhere in this repo yet (grep found no Alpine usage), so
    it is not fabricated here:
      - Open/close state. This component always renders "open" when included;
        the caller decides whether to include it (e.g. `@if ($open)` in a
        Livewire view, or a future `x-show`/`wire:model` binding on the root).
      - Focus moving to the dialog on open, focus TRAPPED inside it, and focus
        RETURNING to the trigger element on close (§3.4, §7.2 mandatory).
      - `Esc` closing the dialog when `dismissible`.
      - Backdrop-click closing the dialog when `dismissible`.
      - Background scroll lock while open.
    What IS provided so that layer has something correct to attach to:
      - `tabindex="-1"` on the dialog panel (a valid initial-focus target).
      - `data-mk-modal-dismiss` on the backdrop and the close button when
        `dismissible` — both currently inert, marking exactly where a click
        handler needs to be wired.
      - Correct `role`, `aria-modal`, `aria-labelledby` scaffolding.
--}}
@props([
    'size' => 'md',
    'title' => null,
    'dismissible' => true,
    'intent' => null,
])

@php
    // aria-labelledby must point at the title element specifically, not the
    // dialog. Respect a caller-supplied id (`:id="..."`) so the same id can be
    // referenced elsewhere (e.g. a trigger's aria-controls); otherwise mint
    // one. uniqid() is fine here — this renders once per request, server-side,
    // not a client-side key that needs collision-proofing across renders.
    $baseId = $attributes->get('id') ?: 'mk-modal-' . uniqid();
    $titleId = $baseId . '-title';

    // §3.4's literal example is for the default size; sm/lg extend the same
    // pattern. Not spelled out in §3.4's table itself, so treat sm/lg as a
    // reasonable extension rather than a directly-sourced value.
    $maxWidths = [
        'sm' => 'md:max-w-sm',
        'md' => 'md:max-w-lg',
        'lg' => 'md:max-w-2xl',
    ];
    $maxWidth = $maxWidths[$size] ?? $maxWidths['md'];

    // Panel classes = union of §3.4's "Sheet (<md)" row and "Dialog (md+)" row.
    // Unprefixed classes (rounded-t-xl, overflow-y-auto, ...) apply at every
    // breakpoint unless a `md:` class overrides them; the dialog row does not
    // repeat max-h/overflow, so the sheet's own internal scroll (needed for
    // the sticky header/footer below) carries over to the desktop dialog too,
    // exactly as the combined rows imply.
    //
    // The sheet's max-height references `--mk-modal-sheet-max-height` (a new
    // component token, tokens.css §2.11: 85% of dynamic viewport height,
    // dvh so mobile browser chrome is accounted for). §3.4 originally spelled
    // this height out as a raw bracketed literal, which violates §9.2's own
    // MUST-NOT rule against arbitrary Tailwind values outside a var()
    // reference. Fixed 25 Jul 2026 by adding the token and correcting §3.4 to
    // match — this file always used the correct height, only the reference
    // form changed.
    //
    // `bg-white` -> `bg-neutral-0`, matching the convention established in
    // button.blade.php. `shadow-xl` on the mobile sheet (not just the md+
    // dialog, where §3.4's own row places it) is this file's addition: §1.5's
    // elevation table assigns the bottom sheet `xl` and the dialog `lg`, which
    // does not literally match §3.4's row (`md:shadow-xl`) — flagging that
    // §1.5-text vs §3.4-table mismatch rather than silently resolving it.
    $panelClasses = "fixed inset-x-0 bottom-0 z-modal rounded-t-xl bg-neutral-0
        pb-[var(--mk-safe-bottom)] max-h-[var(--mk-modal-sheet-max-height)] overflow-y-auto shadow-xl
        flex flex-col
        md:relative md:inset-auto md:mx-auto md:my-16 md:rounded-xl md:shadow-xl
        $maxWidth";

    // Optional severity accent next to the title. §3.4 lists `intent` as a
    // prop but gives no dedicated structural row for it (unlike badge/alert in
    // §3.6/§3.8) — this is a minimal, low-risk reading: a small decorative dot
    // in the resolved intent colour, supplementary only (the title text still
    // carries the actual meaning, per §7.5 "never colour alone"). Callers
    // resolve status -> intent via StatusIntent (§3.7) before passing it in;
    // this component does not switch on a domain enum, only on the already-
    // resolved intent name, the same pattern button.blade.php uses for variant.
    $intentDots = [
        'neutral' => 'bg-neutral-500',
        'info' => 'bg-info-600',
        'pending' => 'bg-warning-600',
        'success' => 'bg-success-600',
        'danger' => 'bg-danger-600',
    ];
    $intentDot = $intentDots[$intent] ?? null;
@endphp

{{--
    Root: a plain full-viewport positioning anchor (see LAYOUT NOTE above). No
    z-index of its own — the backdrop and panel below carry z-backdrop/z-modal
    individually, per §3.4's table, so DOM order plus their own z-index keeps
    the panel above the backdrop regardless of the root.
--}}
<div {{ $attributes->except(['id'])->merge(['class' => 'fixed inset-0 overflow-y-auto']) }}>
    {{-- Backdrop — §3.4 --}}
    <div
        class="fixed inset-0 bg-[var(--mk-surface-overlay)] z-backdrop"
        aria-hidden="true"
        @if ($dismissible) data-mk-modal-dismiss @endif
    ></div>

    {{-- Sheet (<md) / Dialog (md+) — one element, responsive classes --}}
    <div
        id="{{ $baseId }}"
        role="dialog"
        aria-modal="true"
        aria-labelledby="{{ $titleId }}"
        tabindex="-1"
        class="{{ $panelClasses }}"
    >
        {{-- Header --}}
        {{-- No explicit z utility needed: a `position: sticky` element always
             paints above plain in-flow (`position: static`) siblings, so the
             header/footer correctly stay above the scrolling body without
             borrowing a z-index meant for a different layer (§3.4 lists none
             here; z-raised is §3.5's table-header token, not reused). --}}
        <header class="sticky top-0 bg-neutral-0 border-b border-neutral-200 px-4 py-4 flex items-start gap-3">
            @if ($intentDot)
                <span class="mt-2 size-2.5 rounded-full shrink-0 {{ $intentDot }}" aria-hidden="true"></span>
            @endif

            <h2 id="{{ $titleId }}" class="flex-1 text-lg font-semibold text-neutral-900">
                {{ $title }}
            </h2>

            @if ($dismissible)
                {{-- Inert until the JS/Alpine layer wires `data-mk-modal-dismiss`
                     (see file header). touch-target keeps the 44px hit area
                     even though the visual glyph is small. --}}
                <button
                    type="button"
                    data-mk-modal-dismiss
                    class="touch-target -m-2 inline-flex items-center justify-center shrink-0 rounded-md text-neutral-600 hover:bg-neutral-100 hover:text-neutral-800"
                >
                    <svg class="size-5" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="M6 6l12 12M18 6L6 18" stroke="currentColor" stroke-width="2" stroke-linecap="round" />
                    </svg>
                    <span class="sr-only">Tutup</span>
                </button>
            @endif
        </header>

        {{-- Body --}}
        <div class="flex-1 px-4 py-4">
            {{ $slot }}
        </div>

        {{-- Footer — optional. flex-col-reverse on mobile puts the primary
             action last in markup (so it reads first to a screen reader) and
             bottom of screen (thumb reach); md+ becomes a normal left-to-right
             row, right-justified. §3.4. --}}
        @isset($footer)
            <footer class="sticky bottom-0 bg-neutral-0 border-t border-neutral-200 px-4 py-4 flex flex-col-reverse gap-3 md:flex-row md:justify-end">
                {{ $footer }}
            </footer>
        @endisset
    </div>
</div>

{{--
    resources/views/components/mk/stepper.blade.php

    <x-mk.stepper> — design-system.md §3.9. Booking Steps 1-9.

    Labels are a PRODUCT CONTRACT (booking-wizard-fields.md, design-system.md
    §3.9) — hardcoded below, not exposed as a prop, so nothing can reword them
    by accident. Do not add a `labels` prop.

    Two genuinely different layouts (not a responsive class tweak on one
    markup tree), same reasoning the table/modal primitives use for their own
    mobile/desktop splits:
      - Mobile (< md): compact "Langkah N dari 9" + title + thin progress bar.
      - Desktop (md+): full horizontal dot rail with four visual states.
    Both are always in the DOM; Tailwind responsive display utilities
    (`md:hidden` / `hidden md:flex`) pick one. A single `sr-only` progressbar
    node (always rendered, never display:none) carries the accessible
    progress announcement for both breakpoints, so hiding the visual mobile
    bar on desktop never removes the accessible name from the tree.

    Class composition follows button.blade.php: one @php block, state->class
    lookup tables, single $attributes->merge on the root element. `neutral-0`
    is used instead of Tailwind's `white`, same convention.

    Interaction (design-system.md §3.9 table):
      complete -> clickable, back nav preserves data
      current  -> aria-current="step", not a link
      upcoming -> aria-disabled="true", not clickable
      error    -> clickable

    "Clickable" is implemented as `wire:click="{{ $stepMethod }}({{ $n }})"`
    (default method name `goToStep`, override via the `stepMethod` prop).
    This assumes a HOST LIVEWIRE COMPONENT — Livewire 4 is the pinned stack
    (technology-baseline.md) and `wire:click` is a stable, already-adopted
    Livewire directive, not hand-written/unverified JS, so it is not the same
    category of gap as the header's hamburger toggle. What IS unverified: no
    such `goToStep()` method exists anywhere in this repo yet — the wizard
    controller/Livewire component that would implement back-navigation with
    data preservation has not been built. Until it exists, the dots render
    correct, accessible, clickable markup that currently has nothing to call.

    Autosave (§3.9) is explicitly OUT OF SCOPE for this primitive — not
    built here. An optional `autosave` slot is provided so a consumer can
    compose the (separately built) autosave indicator next to the stepper
    without this component inventing that feature itself.

    Dot visual size is `--mk-stepper-dot` (28px, no Tailwind utility, hence
    `size-[var(--mk-stepper-dot)]` — an explicitly allowed arbitrary value
    because it references a Layer-2 token with no generated utility, per the
    task's own constraint). The clickable/tappable area is the pre-existing
    `touch-target` utility from app.css (`min-h-11 min-w-11` = 44px), applied
    to the outer control, not the visual dot — a real accessibility floor,
    not decoration (WCAG 2.5.5).
--}}
@props([
    'step' => 1,           // current step, 1-9
    'errorSteps' => [],    // step numbers currently in the `error` state
    'stepMethod' => 'goToStep', // Livewire method invoked as stepMethod(n)
])

@php
    $labels = [
        1 => 'Lokasi',
        2 => 'TPU/TPS',
        3 => 'Jenis Layanan',
        4 => 'Pilih Layanan',
        5 => 'Ringkasan',
        6 => 'Data Pemesan',
        7 => 'Data Almarhum + Dokumen',
        8 => 'Pembayaran',
        9 => 'Konfirmasi',
    ];

    $totalSteps = count($labels);
    $step = max(1, min($totalSteps, (int) $step));
    $currentLabel = $labels[$step] ?? '';
    $percent = (int) round(($step / $totalSteps) * 100);

    $stateOf = function (int $n) use ($step, $errorSteps): string {
        if (in_array($n, $errorSteps, true)) {
            return 'error';
        }
        if ($n < $step) {
            return 'complete';
        }
        if ($n === $step) {
            return 'current';
        }
        return 'upcoming';
    };

    // §3.9 state table. `text-white` in the spec's own table is rendered as
    // `text-neutral-0` here, matching button.blade.php's documented reason:
    // it traces to a real Layer-1 token (`--color-neutral-0`), not Tailwind's
    // untouched default palette.
    $dotClasses = [
        'complete' => 'bg-primary-600 text-neutral-0',
        'current'  => 'bg-neutral-0 border-2 border-primary-600 text-primary-700',
        'upcoming' => 'bg-neutral-100 border border-neutral-300 text-neutral-500',
        'error'    => 'bg-danger-50 border-2 border-danger-600 text-danger-700',
    ];

    $labelClasses = [
        'complete' => 'text-neutral-700',
        'current'  => 'text-neutral-900 font-semibold',
        'upcoming' => 'text-neutral-500',
        'error'    => 'text-danger-700',
    ];

    // Connector: primary-600 behind completed steps, neutral-300 ahead
    // (§3.9). A segment is "behind" once its lower-indexed endpoint has been
    // passed. `border-2` here is Tailwind's own default border-width scale
    // (2px), not a value sourced from `--mk-stepper-track` — there is no
    // generated utility for that token, and 2px already matches it exactly.
    $segmentClass = fn (int $endpointIdx): string => $endpointIdx < $step
        ? 'border-primary-600'
        : 'border-neutral-300';
@endphp

<div {{ $attributes->merge(['class' => 'w-full']) }} role="group" aria-label="Progres pemesanan">
    {{-- Single accessible progress announcement, shared by both layouts so it
         is never removed from the tree by a `md:hidden` / `hidden md:flex`
         split (display:none removes descendants from the accessibility tree
         too). --}}
    <div
        class="sr-only"
        role="progressbar"
        aria-valuenow="{{ $step }}"
        aria-valuemin="1"
        aria-valuemax="{{ $totalSteps }}"
        aria-valuetext="Langkah {{ $step }} dari {{ $totalSteps }}: {{ $currentLabel }}"
    ></div>

    {{-- Mobile compact layout (< md). Sticky under the header.
         "Collapses to the numeric line on scroll" (§3.9) is a scroll-driven
         behaviour that needs JS this repo does not have yet — not
         implemented here; only the static sticky-under-header position is. --}}
    <div class="md:hidden sticky top-[var(--mk-header-h)] z-sticky-cta bg-neutral-0 border-b border-neutral-200 px-4 py-3">
        <p class="text-sm text-neutral-600">Langkah {{ $step }} dari {{ $totalSteps }}</p>
        <p class="text-xl font-semibold text-neutral-900">{{ $currentLabel }}</p>
        <div class="mt-2 h-1 rounded-full bg-[var(--mk-progress-track)] overflow-hidden" aria-hidden="true">
            <div class="h-1 rounded-full bg-[var(--mk-progress-fill)]" style="width: {{ $percent }}%"></div>
        </div>
    </div>

    {{-- Desktop full rail (md+). Dots connected by a track line, each dot in
         one of four visual states. Not aria-hidden: the complete/error dots
         are real interactive controls and must stay reachable by keyboard
         and screen reader; the sr-only progressbar above only adds a single
         summarised announcement, it does not replace these controls. --}}
    <ol class="hidden md:flex md:items-start md:w-full">
        @foreach ($labels as $n => $label)
            @php($state = $stateOf($n))
            <li class="flex flex-1 flex-col items-center gap-2">
                <div class="flex w-full items-center">
                    @unless ($loop->first)
                        <span class="flex-1 border-t-2 {{ $segmentClass($n - 1) }}"></span>
                    @endunless

                    @if ($state === 'complete' || $state === 'error')
                        {{-- wire:click target is not yet implemented anywhere in
                             this repo — see the file-header comment. --}}
                        <button
                            type="button"
                            wire:click="{{ $stepMethod }}({{ $n }})"
                            class="touch-target shrink-0 inline-flex items-center justify-center rounded-full {{ $dotClasses[$state] }}"
                        >
                            <span class="size-[var(--mk-stepper-dot)] inline-flex items-center justify-center rounded-full text-xs font-semibold" aria-hidden="true">
                                @if ($state === 'complete')
                                    {{-- Same icon.* dynamic-component convention as
                                         button.blade.php; the icon set itself is
                                         not yet built (design-system.md OQ-05). --}}
                                    <x-dynamic-component component="icon.check" class="size-4" />
                                @else
                                    {{ $n }}
                                @endif
                            </span>
                            <span class="sr-only">Langkah {{ $n }}: {{ $label }} ({{ $state === 'complete' ? 'selesai' : 'perlu perbaikan' }})</span>
                        </button>
                    @elseif ($state === 'current')
                        <span
                            aria-current="step"
                            class="touch-target shrink-0 inline-flex items-center justify-center rounded-full {{ $dotClasses[$state] }}"
                        >
                            <span class="size-[var(--mk-stepper-dot)] inline-flex items-center justify-center rounded-full text-xs font-semibold" aria-hidden="true">
                                {{ $n }}
                            </span>
                            <span class="sr-only">Langkah {{ $n }}: {{ $label }} (langkah saat ini)</span>
                        </span>
                    @else
                        <span
                            aria-disabled="true"
                            class="touch-target shrink-0 inline-flex items-center justify-center rounded-full cursor-not-allowed {{ $dotClasses[$state] }}"
                        >
                            <span class="size-[var(--mk-stepper-dot)] inline-flex items-center justify-center rounded-full text-xs font-semibold" aria-hidden="true">
                                {{ $n }}
                            </span>
                            <span class="sr-only">Langkah {{ $n }}: {{ $label }} (belum tersedia)</span>
                        </span>
                    @endif

                    @unless ($loop->last)
                        <span class="flex-1 border-t-2 {{ $segmentClass($n) }}"></span>
                    @endunless
                </div>

                <span class="text-sm text-center {{ $labelClasses[$state] }}">{{ $label }}</span>
            </li>
        @endforeach
    </ol>

    @isset($autosave)
        <div class="mt-2">
            {{ $autosave }}
        </div>
    @endisset
</div>

{{--
    resources/views/components/mk/field.blade.php

    <x-mk.field> — design-system.md §3.2. Covers input, select, textarea,
    checkbox, and radio in one component (the spec's heading groups all
    five under this one primitive; do not split into separate files).

    Convention matches <x-mk.button> (read that file first):
      - @props([...]) lists every prop with its default.
      - Classes are composed once in a single PHP block, then merged
        exactly once via $attributes->merge() on the root element.
      - `neutral-0` (not Tailwind's built-in `white`) — see button.blade.php
        for why: tokens.css defines --color-neutral-0 explicitly.

    Uses the bare `duration-fast` utility, matching button.blade.php.
    `--mk-duration-fast` is a Layer 2 (`:root`) token, but `duration-fast` is
    registered as a real Tailwind utility via `@utility duration-fast { ... }`
    in resources/css/app.css — the utility name and the token's storage layer
    are independent. (An earlier revision of this file checked tokens.css
    alone, missed the app.css @utility declaration, and used the longer
    `duration-[var(--mk-duration-fast)]` form on the mistaken belief that
    `duration-fast` compiled to nothing. Corrected 25 Jul 2026.)

    States (§3.2): idle · hover · focus · filled (= idle, no extra CSS) ·
    error · disabled · readonly.
--}}
@props([
    'label' => null,
    'name' => null,
    'type' => 'text',
    'id' => null,
    'value' => null,
    'checked' => false,
    'hint' => null,
    'error' => null,
    'required' => false,
    'optional' => false,
    'prefix' => null,
    'suffix' => null,
    'autocomplete' => null,
    'inputmode' => null,
    'disabled' => false,
    'readonly' => false,
    'rows' => 3,
])

@php
    $isCheckboxLike = in_array($type, ['checkbox', 'radio'], true);
    $isTextarea = $type === 'textarea';
    $isSelect = $type === 'select';

    // Livewire attributes (`wire:model`, `wire:model.live`, `wire:key`, ...)
    // must land on the inner control, not the wrapper div. The wrapper merges
    // the caller's attributes onto the root for class composition, but
    // `wire:*` on a div does nothing — Livewire reads bindings from the
    // control element itself. Extract them so a caller can pass
    // `wire:model="..."` to <x-mk.field> exactly as it would to a raw input.
    $wireAttributes = collect($attributes->getAttributes())
        ->filter(fn (mixed $value, string $key): bool => str_starts_with($key, 'wire'))
        ->map(function (mixed $value, string $key): string {
            $name = str_replace('_', ':', $key);

            return $value === '' ? $name : $name.'="'.e((string) $value).'"';
        })
        ->implode(' ');

    // Only needs to match the label's `for` and the hint/error ids within
    // this single render — doesn't need to be stable across requests.
    $id = $id ?? $name ?? uniqid('mk-field-');

    $describedBy = collect([
        $hint ? "{$id}-hint" : null,
        $error ? "{$id}-error" : null,
    ])->filter()->implode(' ');

    // §3.2 states table: idle is `border-neutral-450` — NOT `neutral-300`,
    // which measures 1.71:1 and fails WCAG 1.4.11 for a control boundary
    // (design-system.md §7.1 finding #2). This is the single most important
    // detail in this component. Hover is suppressed once the field can no
    // longer be usefully hovered into a different-looking state (readonly
    // keeps its own quieter border regardless of pointer position).
    $stateClasses = $error
        ? 'border-danger-600 focus:border-danger-600 focus:ring-danger-600'
        : trim('border-neutral-450 focus:border-primary-600 focus:ring-primary-600 '
            . ($readonly || $disabled ? '' : 'hover:border-neutral-600'));

    // Shared by input/select/textarea. Disabled colours are the documented
    // pair (neutral-100/neutral-500/neutral-300) — never `opacity-50`, same
    // rule as button.blade.php: a translucent field misreads as "temporarily
    // busy" rather than "not editable". `read-only:` targets the native
    // :read-only pseudo-class (input/textarea only — <select> has no
    // readonly attribute in HTML, so this rule is inert there).
    $controlBase = 'w-full rounded-md border bg-neutral-0 text-base text-neutral-900
        placeholder:text-neutral-500
        transition-[border-color,box-shadow] duration-fast ease-standard
        focus:outline-none focus:ring-2 focus:ring-offset-1
        disabled:cursor-not-allowed disabled:bg-neutral-100 disabled:text-neutral-500 disabled:border-neutral-300
        read-only:bg-neutral-50 read-only:border-neutral-300';

    // Approximate offset for an inline prefix/suffix; not a token — just a
    // plain multiple of the 4px spacing scale (§1.3), not a bracket value.
    $paddingClasses = ($prefix ? 'pl-10' : 'pl-4') . ' ' . ($suffix ? 'pr-10' : 'pr-4');

    $controlClasses = trim("$controlBase $stateClasses $paddingClasses "
        . ($isTextarea ? 'min-h-24 py-3' : 'h-11'));

    // Checkbox/radio: 20px visual control (`size-5`) inside a 44px
    // clickable row — the row height comes from the wrapping <label>, not
    // this element. `rounded-xs` for checkbox, `rounded-full` for radio.
    if ($isCheckboxLike) {
        $shape = $type === 'radio' ? 'rounded-full' : 'rounded-xs';
        $controlClasses = trim("size-5 shrink-0 border bg-neutral-0 $shape text-primary-600
            transition-[border-color,box-shadow] duration-fast ease-standard
            focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-primary-600
            disabled:cursor-not-allowed disabled:bg-neutral-100 disabled:border-neutral-300
            " . ($error ? 'border-danger-600' : 'border-neutral-450'));
    }
@endphp

<div {{ $attributes->merge(['class' => 'flex flex-col gap-1.5']) }}>
    @if ($isCheckboxLike)
        {{-- §3.2: the whole 44px row is the label target, not just the box. --}}
        <label for="{{ $id }}" class="flex min-h-11 cursor-pointer items-center gap-3 py-2 select-none">
            <input
                type="{{ $type }}"
                id="{{ $id }}"
                @if ($name) name="{{ $name }}" @endif
                @if (! is_null($value)) value="{{ $value }}" @endif
                @checked($checked)
                @disabled($disabled)
                @if ($required) required @endif
                @if ($error) aria-invalid="true" @endif
                @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                {!! $wireAttributes !!}
                class="{{ $controlClasses }}"
            >
            <span class="text-base text-neutral-800">
                {{ $label }}
                @if ($required)
                    <span class="text-danger-600" aria-hidden="true">*</span>
                    <span class="sr-only">(wajib diisi)</span>
                @elseif ($optional)
                    <span class="font-normal text-neutral-600">(opsional)</span>
                @endif
            </span>
        </label>

        @if ($hint)
            <p id="{{ $id }}-hint" class="pl-8 text-sm text-neutral-600">{{ $hint }}</p>
        @endif

        @if ($error)
            {{-- icon.alert-circle does not exist in this repo yet — same gap
                 button.blade.php flagged for its own icon references. --}}
            <p id="{{ $id }}-error" class="flex items-start gap-1.5 pl-8 text-sm text-danger-700">
                <x-dynamic-component :component="'icon.alert-circle'" class="size-4 mt-0.5 shrink-0" aria-hidden="true" />
                <span>{{ $error }}</span>
            </p>
        @endif
    @else
        <label for="{{ $id }}" class="text-base font-medium text-neutral-800">
            {{ $label }}
            @if ($required)
                <span class="text-danger-600" aria-hidden="true">*</span>
                <span class="sr-only">(wajib diisi)</span>
            @elseif ($optional)
                <span class="font-normal text-neutral-600">(opsional)</span>
            @endif
        </label>

        {{-- Labels are always visible (§3.2) — placeholder-as-label is
             forbidden, it fails WCAG 3.3.2 and disappears on focus. Hint
             renders here, above the control, and error renders below it;
             neither ever replaces the other (§6.3). --}}
        @if ($hint)
            <p id="{{ $id }}-hint" class="text-sm text-neutral-600">{{ $hint }}</p>
        @endif

        <div class="relative">
            @if ($prefix)
                <span class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-4 text-neutral-500">{{ $prefix }}</span>
            @endif

            @if ($isTextarea)
                <textarea
                    id="{{ $id }}"
                    @if ($name) name="{{ $name }}" @endif
                    rows="{{ $rows }}"
                    @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
                    @disabled($disabled)
                    @readonly($readonly)
                    @if ($required) required @endif
                    @if ($error) aria-invalid="true" @endif
                    @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                    {!! $wireAttributes !!}
                    class="{{ $controlClasses }}"
                >{{ $value }}</textarea>
            @elseif ($isSelect)
                {{-- Caller supplies <option> tags via the default slot. --}}
                <select
                    id="{{ $id }}"
                    @if ($name) name="{{ $name }}" @endif
                    @disabled($disabled)
                    @if ($required) required @endif
                    @if ($error) aria-invalid="true" @endif
                    @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                    {!! $wireAttributes !!}
                    class="{{ $controlClasses }}"
                >{{ $slot }}</select>
            @else
                <input
                    type="{{ $type }}"
                    id="{{ $id }}"
                    @if ($name) name="{{ $name }}" @endif
                    @if (! is_null($value)) value="{{ $value }}" @endif
                    @if ($autocomplete) autocomplete="{{ $autocomplete }}" @endif
                    @if ($inputmode) inputmode="{{ $inputmode }}" @endif
                    @disabled($disabled)
                    @readonly($readonly)
                    @if ($required) required @endif
                    @if ($error) aria-invalid="true" @endif
                    @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
                    {!! $wireAttributes !!}
                    class="{{ $controlClasses }}"
                >
            @endif

            @if ($suffix)
                <span class="pointer-events-none absolute inset-y-0 right-0 flex items-center pr-4 text-neutral-500">{{ $suffix }}</span>
            @endif
        </div>

        @if ($error)
            {{-- icon.alert-circle does not exist in this repo yet — same gap
                 button.blade.php flagged for its own icon references. --}}
            <p id="{{ $id }}-error" class="flex items-start gap-1.5 text-sm text-danger-700">
                <x-dynamic-component :component="'icon.alert-circle'" class="size-4 mt-0.5 shrink-0" aria-hidden="true" />
                <span>{{ $error }}</span>
            </p>
        @endif
    @endif
</div>

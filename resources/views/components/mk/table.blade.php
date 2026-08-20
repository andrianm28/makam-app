{{--
    resources/views/components/mk/table.blade.php

    <x-mk.table> — design-system.md §3.5. Mobile-first rule that is not
    optional: below `md` this renders as stacked cards (one record per card,
    label-value pairs, primary action at the bottom) — horizontal scroll on a
    transaction list is called out in §3.5 as a usability failure, not an
    acceptable fallback. At `md+` it renders a real semantic `<table>`.

    Both views are driven from the SAME `headers`/`rows` data so a consumer
    never writes the content twice: this component renders a real `<table>`
    (`md:table`, hidden below `md`) and a parallel stacked-card list
    (`md:hidden`) from one loop over `$rows`, per the task brief's guidance
    that this — not two copies of caller-supplied slot markup — is the clean
    approach given Blade/Tailwind with no JS.

    Convention: follows button.blade.php/spinner.blade.php's @props + single
    PHP-block composition style; `neutral-0` instead of Tailwind's `white`.

    -----------------------------------------------------------------------
    Props
      caption   (string, required in practice) — sr-only, describes the table
                for screen-reader users. §3.5/§7.4: every table needs this.
      headers   (array) — one entry per column:
                  'key'      => string, matches a key in each $rows entry
                  'label'    => string, visible header text
                  'srLabel'  => string, optional — a screen-reader-only
                                accessible name for a column whose 'label'
                                is deliberately empty visually (e.g. a
                                trailing actions column). Ignored when
                                'label' is non-empty. A `<th>` with neither
                                is a real WCAG failure (axe `empty-table-
                                header`) — found live 20 Aug 2026 via a new
                                Playwright a11y scan on cart.blade.php's
                                actions column, which had 'label' => ''
                                and nothing else.
                  'numeric'  => bool (default false) — right-aligned,
                                tabular-nums, font-mono, per §3.5
                  'sortable' => bool (default false) — header renders as a
                                <button> with aria-sort on the parent <th>
                  'sort'     => 'ascending'|'descending'|'none' (default
                                'none') — current sort state for aria-sort
      rows      (array) — one assoc array per record, keyed by each header's
                'key'. A cell value that implements Laravel's Htmlable
                contract (e.g. `new \Illuminate\Support\HtmlString(...)`,
                such as the output of rendering an <x-mk.button>) is rendered
                unescaped; anything else is escaped as plain text. This is a
                stock Laravel mechanism, not a bespoke one — callers must
                never pass unsanitised user input through Htmlable.
                Two optional per-row keys drive the mobile card's primary
                action (§3.5: "primary action at the bottom"):
                  '_href'        => string URL
                  '_actionLabel' => string (default 'Lihat detail')
      rowKey    (string, default 'id') — key read from each row to build a
                stable `wire:key`/checkbox value when `selectable`.
      sticky    (bool, default false) — sticky `<thead>` for long lists,
                `sticky top-0 z-raised` per §3.5.
      striped   (bool, default false) — zebra striping via `--mk-table-stripe`.
      selectable (bool, default false) — adds a 44px checkbox column. See
                NOT IMPLEMENTED below.

    Slots
      empty (named, optional) — rendered instead of the table/cards when
            $rows is empty. §6.2 requires an empty state to say what's empty,
            why, and what to do next ("never a bare 'Tidak ada data'"), which
            is page-specific copy this primitive cannot know — so there is
            deliberately NO built-in fallback text. If a caller omits this
            slot, an empty $rows renders nothing rather than a non-compliant
            placeholder message.

    -----------------------------------------------------------------------
    NOT IMPLEMENTED — needs a JS/Livewire behaviour layer that does not exist
    in this repo yet, not fabricated here:
      - Sort buttons carry `data-mk-sort="{key}"` and the current `aria-sort`
        state, but clicking does not resort anything — that requires wiring
        to a Livewire action (or JS) that updates `headers[].sort` and the
        row order server-side, then re-renders.
      - Selection checkboxes (`selectable`) are plain, unwired
        `<input type="checkbox">` elements — no name/value binding, no
        select-all sync, and no sticky bulk-action bar (`z-sticky-cta`, per
        §3.5). That bar is a separate composed pattern, not part of this
        primitive; wiring selection state is the caller's/JS layer's concern.
--}}
@props([
    'caption' => null,
    'headers' => [],
    'rows' => [],
    'rowKey' => 'id',
    'sticky' => false,
    'striped' => false,
    'selectable' => false,
])

@php
    // §3.5 names the utility `z-raised`. This file originally found it
    // missing from app.css (unlike z-modal/z-backdrop) and fell back to a
    // `z-[var(--mk-z-raised)]` arbitrary value. `@utility z-raised { ... }`
    // was added to app.css 25 Jul 2026 to close that gap at the source, so
    // this now uses the real bare utility, consistent with the rest of the
    // z-* layer names.
    $stickyHeadClasses = $sticky ? 'sticky top-0 z-raised' : '';
    $stripeClasses = $striped ? 'odd:bg-neutral-0 even:bg-[var(--mk-table-stripe)]' : '';

    // Read a cell value, honouring Htmlable (see header comment). Escaping by
    // default is the safe path; only an explicit Htmlable opts out of it.
    $cell = function ($row, $key) {
        $value = $row[$key] ?? null;

        return $value instanceof \Illuminate\Contracts\Support\Htmlable
            ? $value->toHtml()
            : e($value);
    };
@endphp

<div {{ $attributes }}>
    {{-- ===================================================================
         Desktop / md+: real semantic table. Hidden below md.
         =================================================================== --}}
    <table class="hidden md:table w-full text-sm border-collapse">
        <caption class="sr-only">{{ $caption }}</caption>

        <thead class="{{ $stickyHeadClasses }} bg-neutral-50">
            <tr>
                @if ($selectable)
                    <th scope="col" class="px-4 py-3 w-11">
                        {{-- Unwired select-all — see NOT IMPLEMENTED above. --}}
                        <input type="checkbox" class="touch-target" aria-label="Pilih semua baris" />
                    </th>
                @endif

                @foreach ($headers as $header)
                    @php
                        $align = ($header['numeric'] ?? false) ? 'text-right' : 'text-left';
                        $sort = $header['sort'] ?? 'none';
                    @endphp
                    <th
                        scope="col"
                        class="px-4 py-3 {{ $align }} text-xs font-semibold uppercase tracking-wide text-neutral-600"
                        @if ($header['sortable'] ?? false) aria-sort="{{ $sort }}" @endif
                    >
                        @if ($header['sortable'] ?? false)
                            {{-- Inert until wired — see NOT IMPLEMENTED above. --}}
                            <button
                                type="button"
                                data-mk-sort="{{ $header['key'] }}"
                                class="inline-flex items-center gap-1 uppercase tracking-wide"
                            >
                                {{ $header['label'] }}
                            </button>
                        @elseif (($header['label'] ?? '') !== '')
                            {{ $header['label'] }}
                        @elseif (($header['srLabel'] ?? '') !== '')
                            <span class="sr-only">{{ $header['srLabel'] }}</span>
                        @endif
                    </th>
                @endforeach
            </tr>
        </thead>

        <tbody>
            @forelse ($rows as $row)
                <tr
                    @if (isset($row[$rowKey])) wire:key="row-{{ $row[$rowKey] }}" @endif
                    class="border-b border-neutral-200 hover:bg-[var(--mk-table-hover)] {{ $stripeClasses }}"
                >
                    @if ($selectable)
                        <td class="px-4 py-3">
                            <input
                                type="checkbox"
                                class="touch-target"
                                aria-label="Pilih baris{{ isset($row[$rowKey]) ? ' ' . $row[$rowKey] : '' }}"
                            />
                        </td>
                    @endif

                    @foreach ($headers as $header)
                        @php
                            $numeric = $header['numeric'] ?? false;
                            $cellClasses = $numeric ? 'text-right tabular-nums font-mono' : 'text-left';
                        @endphp
                        <td class="px-4 py-3 {{ $cellClasses }}">
                            {!! $cell($row, $header['key']) !!}
                        </td>
                    @endforeach
                </tr>
            @empty
                {{-- Intentionally nothing here: an `empty` slot with real
                     what/why/next copy (§6.2) is rendered once below the
                     table, not per-breakpoint markup duplicated in here. --}}
            @endforelse
        </tbody>
    </table>

    {{-- ===================================================================
         Mobile (<md): stacked cards, one per record. `md:hidden`.
         =================================================================== --}}
    <div class="md:hidden flex flex-col gap-3">
        @foreach ($rows as $row)
            <div
                @if (isset($row[$rowKey])) wire:key="card-{{ $row[$rowKey] }}" @endif
                class="rounded-lg border border-neutral-200 bg-neutral-0 p-4 flex flex-col gap-3"
            >
                @if ($selectable)
                    <input
                        type="checkbox"
                        class="touch-target self-start"
                        aria-label="Pilih baris{{ isset($row[$rowKey]) ? ' ' . $row[$rowKey] : '' }}"
                    />
                @endif

                <dl class="flex flex-col gap-2">
                    @foreach ($headers as $header)
                        @php
                            $numeric = $header['numeric'] ?? false;
                            $valueClasses = $numeric ? 'text-right tabular-nums font-mono' : 'text-right';
                        @endphp
                        <div class="flex items-baseline justify-between gap-3">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-neutral-600">
                                {{ $header['label'] }}
                            </dt>
                            <dd class="{{ $valueClasses }} text-neutral-800">
                                {!! $cell($row, $header['key']) !!}
                            </dd>
                        </div>
                    @endforeach
                </dl>

                @if (! empty($row['_href']))
                    {{-- Primary action at the bottom of the card, §3.5. Reuses
                         the already-built <x-mk.button> rather than hand-
                         rolling another interactive element. Default (md,
                         44px) size — this is the mobile stacked-card view, a
                         public/mobile surface, and §3.1 forbids `sm` (36px)
                         there; `sm` is reserved for dense Filament table rows,
                         which is the md:table branch below, not this one. --}}
                    <x-mk.button :href="$row['_href']" variant="secondary" full>
                        {{ $row['_actionLabel'] ?? 'Lihat detail' }}
                    </x-mk.button>
                @endif
            </div>
        @endforeach
    </div>

    {{-- Empty state — caller-supplied only, see Slots doc above. --}}
    @if (empty($rows) && isset($empty))
        {{ $empty }}
    @endif
</div>

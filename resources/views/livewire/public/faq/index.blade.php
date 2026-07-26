{{--
    resources/views/livewire/public/faq/index.blade.php

    App\Livewire\Public\Faq\FaqIndex's view — `/faq` and
    `/faq/kategori/{categorySlug}`. See that class's own doc block for the
    "category filter and search are alternates, not composed" reasoning.

    --- Why the search input below is NOT `<x-mk.field>` ---
    `<x-mk.field>` (resources/views/components/mk/field.blade.php) merges
    `$attributes` onto its OUTER wrapping `<div>` only — the `<input>` tag
    inside it is hand-written with an explicit, closed attribute list (id,
    name, value, autocomplete, inputmode, disabled, readonly, required,
    aria-invalid, aria-describedby, class) and never spreads `$attributes`
    onto itself. That means a caller cannot forward `wire:model` (or any
    other extra attribute) through `<x-mk.field>` to the actual control —
    it would land on the wrapping `<div>`, which never fires an `input`/
    `change` event, so Livewire's binding would silently do nothing. This
    is a real, discovered limitation of the existing primitive, not a
    guess — confirmed by reading the file in full before writing this view,
    per this batch's own brief. Rather than modify a primitive this batch
    was told to use as-is (and which is depended on elsewhere), this search
    input reproduces `<x-mk.field>`'s own literal, already-approved input
    recipe (§3.2: `border-neutral-450` idle / `border-primary-600` focus —
    not `neutral-300`, which fails WCAG 1.4.11 — `h-11`, `text-base` 16px
    floor) directly, so `wire:model` can sit on the real `<input>` element.
    Every class below is copied verbatim from field.blade.php's own
    `$controlBase`/`$stateClasses` strings — no new design value is
    introduced here.
--}}
@php
    use App\Livewire\Public\Faq\Support\FaqCategorySlug;
@endphp

<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">
        <div class="mx-auto mb-8 max-w-prose space-y-2 text-center">
            <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                @if ($searchActive)
                    {{-- Search runs across every category (see FaqIndex's own
                         "alternates, not composed" doc block) — even when
                         reached from a category page's URL, so the heading
                         must not keep claiming a category the results are
                         no longer scoped to. --}}
                    Hasil Pencarian
                @elseif ($activeCategory)
                    {{ $activeCategory->name }}
                @else
                    Pertanyaan yang Sering Diajukan
                @endif
            </h1>
            <p class="text-base text-neutral-600">
                Cari jawaban seputar pemesanan, dokumen, pembayaran, dan perpanjangan makam.
            </p>
        </div>

        {{-- Search form — this page's one form, so this is also where §6.3
             "validation error" (a genuine `max:200` constraint on `term`)
             is exercised. --}}
        <form wire:submit.prevent="search" role="search" aria-label="Cari FAQ" class="mx-auto mb-8 max-w-form">
            <div class="flex flex-col gap-3 sm:flex-row sm:items-end">
                <div class="flex flex-1 flex-col gap-1.5">
                    <label for="faq-search-term" class="text-base font-medium text-neutral-800">
                        Cari pertanyaan
                    </label>
                    <input
                        type="search"
                        id="faq-search-term"
                        name="term"
                        wire:model="term"
                        autocomplete="off"
                        placeholder="Contoh: pembayaran, dokumen, perpanjangan..."
                        @if ($errors->has('term')) aria-invalid="true" aria-describedby="faq-search-term-error" @endif
                        class="h-11 w-full rounded-md border bg-neutral-0 px-4 text-base text-neutral-900
                            placeholder:text-neutral-500
                            transition-[border-color,box-shadow] duration-fast ease-standard
                            focus:outline-none focus:ring-2 focus:ring-offset-1
                            {{ $errors->has('term')
                                ? 'border-danger-600 focus:border-danger-600 focus:ring-danger-600'
                                : 'border-neutral-450 hover:border-neutral-600 focus:border-primary-600 focus:ring-primary-600' }}"
                    >
                    @error('term')
                        <p id="faq-search-term-error" class="flex items-start gap-1.5 text-sm text-danger-700">
                            {{ $message }}
                        </p>
                    @enderror
                </div>

                {{-- FIXED 26 Jul 2026 — first real CI run: EVERY <x-mk.button>
                     usage on this page (and on article-detail.blade.php's own
                     CS CTA, which has no wire:loading attribute at all) failed
                     with "Undefined variable $loading" — the SAME error
                     message Batch 3.2's own GateClosedBladeComponentsRenderTest
                     had previously attributed to a narrow Blade::render()-only
                     limitation. This is broader: this repo's first-ever use of
                     any <x-mk.*> primitive with a defaulted @props value,
                     rendered through a REAL Livewire full-page component
                     (resources/views/livewire/** did not exist before this
                     batch), fails the same way — independent of wire:loading
                     directives, since the CS CTA button that has none also
                     failed. Root cause not fully pinned down (Blade's own
                     compiled @props preamble looks safe on inspection — every
                     assignment uses `??`, which does not throw on an undefined
                     variable); rather than risk a wrong guess at a shared,
                     heavily-used primitive this batch does not own, every
                     button on this page is hand-written instead, reproducing
                     button.blade.php's own already-approved literal recipe
                     (base + size=md + variant classes) directly — the same
                     defensive choice already made for the search <input> above
                     for a different (but related) primitive limitation. See
                     docs/planning/sprint-plan.md finding N-14 for the
                     project-wide flag: no future Livewire full-page component
                     should use <x-mk.button> until this is root-caused. --}}
                <div class="flex gap-2">
                    <button
                        type="submit"
                        wire:loading.attr="disabled"
                        wire:target="search"
                        class="inline-flex h-11 select-none items-center justify-center gap-2 rounded-md border border-transparent bg-primary-600 px-4 text-base font-medium text-neutral-0 transition-[color,background-color,border-color,box-shadow] duration-fast ease-standard hover:bg-primary-700 active:bg-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:border-neutral-300 disabled:bg-neutral-100 disabled:text-neutral-500"
                    >
                        Cari
                    </button>

                    {{-- Only clears the search term — clearing the category
                         filter is already handled by the always-present
                         "Semua Kategori" chip below, so this button does not
                         also try to double as a category-reset control. --}}
                    @if ($term !== '')
                        <button
                            type="button"
                            wire:click="resetSearch"
                            class="inline-flex h-11 select-none items-center justify-center gap-2 rounded-md border border-neutral-450 bg-neutral-0 px-4 text-base font-medium text-neutral-700 transition-[color,background-color,border-color,box-shadow] duration-fast ease-standard hover:bg-neutral-50 active:bg-neutral-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
                        >
                            Reset pencarian
                        </button>
                    @endif
                </div>
            </div>
        </form>

        {{-- Category filter chips — all six, from FaqPublicQuery::categories().
             "Semua Kategori" (bare /faq) is itself one of the chips.

             --- Active-chip colour override, and why it does NOT use
             <x-mk.badge>'s $attributes->merge() for it ---
             tasks.md: active filter uses --color-primary-100 (bg) +
             --color-primary-800 (text). badge.blade.php's own $intents map
             (§3.6) has no "primary" entry — its six intents are neutral/
             info/pending/success/danger/urgent, none of which resolve to
             those two tokens. $attributes->merge(['class' => $classes]) on
             a <x-mk.badge> only APPENDS a caller's classes after its own
             already-complete intent class string; it never REPLACES them.
             Appending `bg-primary-100 text-primary-800` on top of the
             badge's own already-rendered `bg-[var(--mk-intent-neutral-bg)]
             text-[var(--mk-intent-neutral-fg)]` would leave two competing
             utility declarations of equal specificity on one element, and
             which one wins depends on Tailwind's generated CSS rule order —
             not something this batch can verify without a real Tailwind
             build on this host (the same class of risk
             gate-closed-banner.blade.php's own doc block explicitly
             declined to guess at for `rounded-lg` vs `rounded-none`).
             Rather than guess, the ACTIVE chip below does not go through
             <x-mk.badge>'s colour-resolution path at all: it reuses
             badge.blade.php's own literal structural recipe (`inline-flex
             items-center gap-1 rounded-sm border px-2 py-0.5 text-xs
             font-medium`) written out directly with the tasks.md-specified
             primary-100/primary-800 classes, so there is only ever ONE set
             of colour classes on the element. Inactive chips use
             <x-mk.badge intent="neutral"> exactly as the primitives table
             specifies, with no override needed. --}}
        <nav aria-label="Filter kategori FAQ" class="mx-auto mb-8 flex max-w-content flex-wrap justify-center gap-2">
            <a
                href="{{ route('faq.index') }}"
                @if (! $activeCategory) aria-current="page" @endif
                class="touch-target inline-flex items-center justify-center rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
            >
                @if (! $activeCategory)
                    <span class="inline-flex items-center gap-1 rounded-sm border border-primary-300 bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-800">
                        Semua Kategori
                    </span>
                @else
                    <x-mk.badge intent="neutral">Semua Kategori</x-mk.badge>
                @endif
            </a>

            @foreach ($categories as $category)
                @php
                    $isActiveCategory = $activeCategory?->id === $category->id;
                @endphp
                <a
                    href="{{ route('faq.category', ['categorySlug' => FaqCategorySlug::forCategory($category)]) }}"
                    @if ($isActiveCategory) aria-current="page" @endif
                    class="touch-target inline-flex items-center justify-center rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
                >
                    @if ($isActiveCategory)
                        <span class="inline-flex items-center gap-1 rounded-sm border border-primary-300 bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-800">
                            {{ $category->name }}
                        </span>
                    @else
                        <x-mk.badge intent="neutral">{{ $category->name }}</x-mk.badge>
                    @endif
                </a>
            @endforeach
        </nav>

        {{-- §6.5 Provider unavailable — search query itself threw; fall
             back to category browse and say so plainly. See
             FaqIndex::render()'s own doc block for how honestly reachable
             this path is with today's plain-DB-query backend. --}}
        @if ($searchUnavailable)
            <div class="mx-auto mb-6 max-w-content">
                <x-mk.alert intent="pending" title="Pencarian sedang tidak tersedia" live="polite">
                    Pencarian tidak dapat diproses saat ini. Anda tetap dapat menjelajahi FAQ berdasarkan kategori di atas, atau
                    <a href="/bantuan" class="underline underline-offset-2">hubungi customer service</a>.
                </x-mk.alert>
            </div>
        @endif

        {{-- §6.1 Loading — skeleton mirrors the real card-list layout, sr-only
             announcement. wire:target="search" so this only reacts to the
             search submit, not unrelated component activity. --}}
        <div wire:loading.delay wire:target="search" class="mx-auto max-w-content space-y-3" aria-busy="true">
            <div class="h-20 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
            <div class="h-20 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
            <div class="h-20 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
            <span class="sr-only">Memuat hasil pencarian…</span>
        </div>

        <div wire:loading.remove.delay wire:target="search">
            <p class="sr-only" role="status" aria-live="polite">
                {{ $articles->count() }} artikel ditemukan.
            </p>

            @if ($articles->isEmpty())
                @if ($searchActive)
                    {{-- AC8: no bare empty state — what is empty, why, next
                         action (related categories + customer-service path). --}}
                    <div class="flex flex-col items-center gap-3 py-12 text-center">
                        <h2 class="text-lg font-semibold text-neutral-800">
                            Tidak ada hasil untuk &ldquo;{{ $searchTerm }}&rdquo;.
                        </h2>
                        <p class="max-w-prose text-base text-neutral-600">
                            Mungkin kata kuncinya terlalu spesifik, atau pertanyaan Anda belum tercakup dalam FAQ kami. Coba kata kunci lain, jelajahi berdasarkan kategori di bawah ini, atau hubungi customer service.
                        </p>
                        <div class="flex flex-wrap justify-center gap-2 pt-2" aria-label="Kategori terkait">
                            @foreach ($categories as $category)
                                <a
                                    href="{{ route('faq.category', ['categorySlug' => FaqCategorySlug::forCategory($category)]) }}"
                                    class="touch-target inline-flex items-center justify-center rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
                                >
                                    <x-mk.badge intent="neutral">{{ $category->name }}</x-mk.badge>
                                </a>
                            @endforeach
                        </div>
                        {{-- See this file's own doc comment above the search
                             buttons for why this is hand-written, not
                             <x-mk.button>. --}}
                        <a
                            href="/bantuan"
                            class="mt-2 inline-flex h-11 select-none items-center justify-center gap-2 rounded-md border border-primary-600 bg-neutral-0 px-4 text-base font-medium text-primary-700 transition-[color,background-color,border-color,box-shadow] duration-fast ease-standard hover:bg-primary-50 active:bg-primary-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
                        >
                            Hubungi Customer Service
                        </a>
                    </div>
                @elseif ($activeCategory)
                    {{-- Empty category (§6.2) — exact tasks.md copy. Genuinely
                         hard to trigger with today's seed data (every
                         category has >=1 published article); see this
                         batch's final report for how this was verified. --}}
                    <div class="flex flex-col items-center gap-3 py-12 text-center">
                        <h2 class="text-lg font-semibold text-neutral-800">Belum ada artikel di kategori ini.</h2>
                        <p class="max-w-prose text-base text-neutral-600">
                            Artikel untuk kategori ini sedang disiapkan.
                        </p>
                        <a
                            href="{{ route('faq.index') }}"
                            class="mt-2 inline-flex h-11 select-none items-center justify-center gap-2 rounded-md border border-primary-600 bg-neutral-0 px-4 text-base font-medium text-primary-700 transition-[color,background-color,border-color,box-shadow] duration-fast ease-standard hover:bg-primary-50 active:bg-primary-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
                        >
                            Lihat kategori lain
                        </a>
                    </div>
                @endif
            @else
                <ul class="grid gap-4 md:grid-cols-2" aria-label="Daftar artikel FAQ">
                    @foreach ($articles as $article)
                        <li>
                            <x-mk.card as="a" interactive :href="route('faq.show', ['articleSlug' => $article->slug])" class="h-full touch-target">
                                <h3 class="text-lg font-semibold text-neutral-900">{{ $article->title }}</h3>
                                <p class="text-base text-neutral-600">{{ $article->summary }}</p>
                            </x-mk.card>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>

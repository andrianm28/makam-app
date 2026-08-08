{{--
    resources/views/livewire/public/directory/index.blade.php

    App\Livewire\Public\Directory\CemeteryDirectoryIndex's view — the public
    TPU/TPS directory list, route name `cemeteries.index`. Sprint 4 S4-T6,
    .kiro/specs/cemetery-directory-and-availability AC1, AC2, AC3, AC4, AC5,
    AC11, AC12. Screen PUB-011 ("list, filter, detail, no result").

    --- Two rules this file exists to hold, both easy to break later ---

    1. NO `match`/`@if` ON A CAPABILITY STRING IN THIS FILE (design-system.md
       §3.7). Every badge below takes an already-resolved (intent, icon,
       label) triple from
       App\Livewire\Public\Directory\Support\CemeteryAvailabilityIntent.
       Adding `@if ($capabilities->availabilityMode === '...')` here is the
       regression this note is here to prevent.

    2. THE ADDRESS RENDERS UNCONDITIONALLY (AC11). The Google Maps link is a
       separate, optional element that appears only when
       CemeteryPresenter::mapUrl() returns non-null — and its absence never
       gates, wraps, or hides the textual address. Do not "tidy" the address
       and the map link into one @if block.

    --- Buttons ---
    <x-mk.button> is used directly. Finding N-14 (the "Undefined variable
    $loading" failure that made resources/views/livewire/public/faq/*.blade.php
    hand-write button markup) is FIXED and the fix was verified 8 Aug 2026
    against real Laravel 13.22.0 + Livewire 4.3.3 — all three variants,
    including the loading state. New pages use the primitive; the older
    hand-written call sites are left alone.
--}}
@php
    use App\Livewire\Public\Directory\Support\CemeteryAvailabilityIntent;
    use App\Livewire\Public\Directory\Support\CemeteryPresenter;
@endphp

<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">
        <div class="mx-auto mb-8 max-w-prose space-y-2 text-center">
            <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                Direktori TPU dan TPS
            </h1>
            <p class="text-base text-neutral-600">
                Telusuri lokasi pemakaman umum (TPU) dan pemakaman swasta (TPS) di area layanan kami.
                Seluruh informasi ketersediaan dan kisaran biaya bersifat indikatif dan perlu dikonfirmasi
                ke pengelola sebelum menjadi keputusan akhir.
            </p>
        </div>

        {{-- AC1 + AC2 — the five launch cities, always all five, sourced from
             CemeteryDirectoryQuery::launchCities() (which reads
             LaunchCityCode::KNOWN_CODES). A city with zero published
             cemeteries still gets a chip: the negative criterion is "No
             hidden omission of a required MVP city," so a city must never
             disappear from this control just because today's data is thin.
             Selecting it lands on the §6.2 empty state, which says so. --}}
        <nav aria-label="Filter kota" class="mb-4 flex flex-wrap justify-center gap-2">
            <button
                type="button"
                wire:click="$set('city', '')"
                @if ($city === '') aria-current="true" @endif
                class="touch-target inline-flex items-center justify-center rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
            >
                @if ($city === '')
                    {{-- The `icon.check` tick is NOT decoration — do not remove
                         it. Without it the active chip differs from an inactive
                         one by HUE ALONE (identical inline-flex/rounded-sm/
                         border/px-2/py-0.5/text-xs/font-medium recipe, only the
                         colour family changes), which is what design-system.md
                         §9.2 MUST-NOT "never convey status by colour alone"
                         and WCAG 1.4.1 exist to prevent.

                         `aria-current` below already carries selection to
                         assistive tech, so this is specifically for a SIGHTED
                         user with low colour discrimination. And unlike
                         faq/index.blade.php — whose <h1> becomes the active
                         category name, giving a redundant non-chromatic cue
                         elsewhere on the page — THIS page's <h1> is static
                         ("Direktori TPU dan TPS") and its sr-only status line
                         is only a result count. Checked directly, 8 Aug 2026:
                         nothing else on this page names the active filter, so
                         the tick is the only non-colour selection cue there is.
                         tasks.md's own accessibility task ("no colour-only
                         availability signalling") is what this closes.

                         Active chip reuses badge.blade.php's own literal
                         structural recipe with primary-100/primary-800,
                         rather than appending colour classes onto
                         <x-mk.badge>'s already-complete intent string —
                         two competing utility declarations of equal
                         specificity would resolve by Tailwind's generated
                         rule order, which cannot be verified without a real
                         build on this host. Same reasoning, and the same
                         literal recipe, as the FAQ category chips.

                         NOT related to finding N-14 (see this file's header):
                         N-14 was a <x-mk.button> defect and it is fixed. This
                         is a different, still-live limitation — badge.blade.php
                         has no `primary` intent, and $attributes->merge()
                         APPENDS to its intent classes rather than REPLACING
                         them. Routing this chip back through <x-mk.badge> with
                         appended colour classes would reintroduce the
                         rule-order ambiguity, not clean anything up.

                         AND DO NOT JUST PASS intent="primary" — it FAILS
                         SILENTLY. badge.blade.php:83 is
                         `$intents[$intent] ?? $intents['neutral']`, so an
                         unknown intent renders neutral with no error, no
                         warning, and nothing in the logs; the active chip
                         would simply stop looking active and no test that
                         asserts on text would catch it. Verified by render,
                         8 Aug 2026 (marketplace-builder, S4-T8) — the output
                         is byte-identical to intent="neutral".

                         The real fix is adding a `primary` intent to the badge
                         primitive. Cheaper than it looks: primary-800 on
                         primary-100 is ALREADY an asserted WCAG pair
                         (docs/design/verify-contrast.py:99, inside GATE 1's
                         46), so it needs no tokens.css change and no §9.4 ADR.
                         But design-system.md §3.6 defines `intent` as a CLOSED
                         list (neutral|info|pending|success|danger|urgent), so
                         it is a governing-document edit plus a shared
                         single-writer file — not this view's call. Raised with
                         the lead 8 Aug 2026 by S4-T6 and S4-T8 jointly. --}}
                    <span class="inline-flex items-center gap-1 rounded-sm border border-primary-300 bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-800">
                        <x-dynamic-component component="icon.check" class="size-3.5 shrink-0" aria-hidden="true" />
                        Semua Kota
                    </span>
                @else
                    <x-mk.badge intent="neutral">Semua Kota</x-mk.badge>
                @endif
            </button>

            @foreach ($cities as $cityCode => $cityLabel)
                <button
                    type="button"
                    wire:click="$set('city', @js($cityCode))"
                    @if ($city === $cityCode) aria-current="true" @endif
                    class="touch-target inline-flex items-center justify-center rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
                >
                    @if ($city === $cityCode)
                        <span class="inline-flex items-center gap-1 rounded-sm border border-primary-300 bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-800">
                            <x-dynamic-component component="icon.check" class="size-3.5 shrink-0" aria-hidden="true" />
                            {{ $cityLabel }}
                        </span>
                    @else
                        <x-mk.badge intent="neutral">{{ $cityLabel }}</x-mk.badge>
                    @endif
                </button>
            @endforeach
        </nav>

        <nav aria-label="Filter jenis lokasi" class="mb-6 flex flex-wrap justify-center gap-2">
            <button
                type="button"
                wire:click="$set('type', '')"
                @if ($type === '') aria-current="true" @endif
                class="touch-target inline-flex items-center justify-center rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
            >
                @if ($type === '')
                    <span class="inline-flex items-center gap-1 rounded-sm border border-primary-300 bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-800">
                        <x-dynamic-component component="icon.check" class="size-3.5 shrink-0" aria-hidden="true" />
                        Semua Jenis
                    </span>
                @else
                    <x-mk.badge intent="neutral">Semua Jenis</x-mk.badge>
                @endif
            </button>

            @foreach ($types as $typeCode => $typeLabel)
                <button
                    type="button"
                    wire:click="$set('type', @js($typeCode))"
                    @if ($type === $typeCode) aria-current="true" @endif
                    class="touch-target inline-flex items-center justify-center rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
                >
                    @if ($type === $typeCode)
                        <span class="inline-flex items-center gap-1 rounded-sm border border-primary-300 bg-primary-100 px-2 py-0.5 text-xs font-medium text-primary-800">
                            <x-dynamic-component component="icon.check" class="size-3.5 shrink-0" aria-hidden="true" />
                            {{ $typeLabel }}
                        </span>
                    @else
                        <x-mk.badge intent="neutral">{{ $typeLabel }}</x-mk.badge>
                    @endif
                </button>
            @endforeach
        </nav>

        {{-- §6.3 Validation error — an unknown ?city= / ?type= is stated
             plainly instead of silently returning zero results. See
             CemeteryDirectoryIndex's doc block for why that distinction
             matters on this particular page. --}}
        @if ($errors->has('city') || $errors->has('type'))
            <div class="mx-auto mb-6 max-w-content">
                <x-mk.alert intent="danger" title="Filter tidak dikenali" icon="alert-circle" live="assertive">
                    <ul class="space-y-1">
                        @foreach (['city', 'type'] as $filterField)
                            @error($filterField)
                                <li>{{ $message }}</li>
                            @enderror
                        @endforeach
                    </ul>
                </x-mk.alert>
            </div>
        @endif

        {{-- §6.5 Provider unavailable — capability resolution degraded for at
             least one cemetery. AC4's safe defaults are in use; the page says
             so rather than presenting a fallback as the operator's answer. --}}
        @if ($capabilitiesDegraded)
            <div class="mx-auto mb-6 max-w-content">
                <x-mk.alert intent="pending" title="Sebagian informasi ketersediaan sedang tidak dapat dimuat" icon="exclamation-triangle" live="polite">
                    Kami menampilkan nilai paling aman (perlu konfirmasi) untuk lokasi yang datanya belum dapat dibaca.
                    Silakan konfirmasi langsung ke pengelola, atau
                    <a href="{{ route('bantuan.index') }}" class="underline underline-offset-2">hubungi customer service</a>.
                </x-mk.alert>
            </div>
        @endif

        {{-- §6.1 Loading — skeleton mirrors the real card grid so the layout
             does not jump when results arrive (tasks.md: "reserve exact
             heights, CLS < 0.1"). wire:target lists only the filter actions,
             so unrelated component activity does not blank the list. --}}
        <div
            wire:loading.delay
            wire:target="city,type,resetFilters"
            class="grid gap-4 md:grid-cols-2 xl:grid-cols-3"
            aria-busy="true"
        >
            @for ($i = 0; $i < 3; $i++)
                <div class="h-72 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
            @endfor
            <span class="sr-only">Memuat daftar lokasi…</span>
        </div>

        <div wire:loading.remove.delay wire:target="city,type,resetFilters">
            @if ($directoryUnavailable)
                {{-- §6.5 — the PRIMARY content is unreachable. There is no
                     smaller thing to degrade to, so this states what is
                     wrong, what it does not affect, and where to go next.
                     Never a blank page and never a generic 404. --}}
                <div class="mx-auto max-w-content">
                    <x-mk.alert intent="danger" title="Direktori lokasi sedang tidak dapat dimuat" icon="alert-circle" live="assertive">
                        <p>
                            Daftar TPU/TPS tidak dapat ditampilkan saat ini. Ini adalah gangguan sementara pada sistem kami,
                            bukan tanda bahwa lokasi tersebut tidak tersedia.
                        </p>
                        <p class="mt-2">
                            Silakan muat ulang halaman beberapa saat lagi, atau hubungi customer service jika Anda
                            membutuhkan bantuan segera.
                        </p>
                        <div class="mt-3 flex flex-wrap gap-2">
                            <x-mk.button variant="secondary" :href="route('bantuan.index')">
                                Hubungi Customer Service
                            </x-mk.button>
                        </div>
                    </x-mk.alert>
                </div>
            @else
                <p class="sr-only" role="status" aria-live="polite">
                    {{ $cards->count() }} lokasi ditemukan.
                </p>

                @if ($cards->isEmpty())
                    {{-- §6.2 Empty — three parts: what is empty, why, what to
                         do next. Never a bare "Tidak ada data". --}}
                    <div class="flex flex-col items-center gap-3 py-12 text-center">
                        <h2 class="text-lg font-semibold text-neutral-800">
                            Belum ada lokasi yang cocok dengan filter ini.
                        </h2>
                        <p class="max-w-prose text-base text-neutral-600">
                            @if ($filtersActive)
                                Kombinasi kota dan jenis lokasi yang Anda pilih belum memiliki TPU/TPS yang terpublikasi.
                                Area layanan kami tetap mencakup seluruh kota di atas — data lokasinya sedang kami lengkapi.
                            @else
                                Data lokasi sedang kami lengkapi bersama pengelola pemakaman.
                            @endif
                        </p>
                        <div class="flex flex-wrap justify-center gap-2 pt-2">
                            @if ($filtersActive)
                                <x-mk.button variant="primary" wire:click="resetFilters">
                                    Reset filter
                                </x-mk.button>
                            @endif
                            <x-mk.button variant="secondary" :href="route('bantuan.index')">
                                Hubungi Customer Service
                            </x-mk.button>
                        </div>
                    </div>
                @else
                    <ul class="grid gap-4 md:grid-cols-2 xl:grid-cols-3" aria-label="Daftar TPU dan TPS">
                        @foreach ($cards as $card)
                            @php
                                $cemetery = $card['cemetery'];
                                $capabilities = $card['capabilities'];
                                // AC4 — a card whose capability resolution
                                // failed still renders; it just falls back to
                                // the most cautious availability state. The
                                // page-level alert above already told the user
                                // this happened.
                                $availabilityMode = $capabilities?->availabilityMode ?? '';
                                $availability = CemeteryAvailabilityIntent::forCemetery($availabilityMode);
                                $priceRange = CemeteryPresenter::priceRange($cemetery);
                                $priceAttribution = CemeteryPresenter::priceAttribution($cemetery);
                                $photoUrl = CemeteryPresenter::photoUrl($cemetery);
                                $mapUrl = CemeteryPresenter::mapUrl($cemetery);
                                $facilities = CemeteryPresenter::facilities($cemetery);
                            @endphp

                            <li>
                                {{-- design-system.md §3.3 cemetery-card variant.
                                     tasks.md's primitives table specifies
                                     exactly this: "Cemetery card |
                                     <x-mk.card as=a interactive>".

                                     CONSEQUENCE, flagged rather than hidden: an
                                     interactive <x-mk.card> IS the one anchor
                                     and must not contain another <a> or
                                     <button> — card.blade.php's own doc block is
                                     explicit ("never nest another <a> or
                                     <button> inside an interactive card's
                                     slot"). So the Google Maps link cannot live
                                     on the card and appears on the DETAIL page
                                     instead. AC3 permits this ("WHEN a user
                                     views a cemetery card OR detail"), but it is
                                     a real trade-off between tasks.md's
                                     specified primitive and putting every AC3
                                     field on the card itself. Resolving it the
                                     other way means dropping `interactive` and
                                     giving the card two explicit links
                                     ("Lihat detail" + "Buka di Google Maps"),
                                     which contradicts tasks.md's table — a
                                     design call, not an implementation one.

                                     AC11 is unaffected either way: the card
                                     renders the full textual address
                                     unconditionally. --}}
                                <x-mk.card
                                    as="a"
                                    interactive
                                    :href="route('cemeteries.show', ['cemeterySlug' => $cemetery->slug])"
                                    class="h-full touch-target"
                                >
                                    <x-slot:media>
                                        @if ($photoUrl)
                                            <img
                                                src="{{ $photoUrl }}"
                                                alt="Ilustrasi {{ $cemetery->name }}"
                                                loading="lazy"
                                                class="h-40 w-full object-cover"
                                            >
                                        @else
                                            {{-- No photo is a real state (the seed
                                                 migration ships some rows without
                                                 one). A labelled placeholder beats
                                                 a broken <img> or a silent gap. --}}
                                            <div class="flex h-40 w-full items-center justify-center bg-neutral-100">
                                                <span class="text-sm text-neutral-600">Foto belum tersedia</span>
                                            </div>
                                        @endif
                                    </x-slot:media>

                                    <div class="space-y-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            {{-- AC3: type badge --}}
                                            <x-mk.badge intent="neutral">{{ $cemetery->type }}</x-mk.badge>

                                            {{-- AC3 + AC5: availability, resolved
                                                 through CemeteryAvailabilityIntent
                                                 — neutral + "Perlu konfirmasi"
                                                 while indicative, never success. --}}
                                            <x-mk.badge :intent="$availability['intent']" :icon="$availability['icon']">
                                                {{ $availability['label'] }}
                                            </x-mk.badge>
                                        </div>

                                        {{-- AC3: name --}}
                                        <h2 class="text-lg font-semibold text-neutral-900">{{ $cemetery->name }}</h2>

                                        {{-- AC3 + AC11: address, rendered
                                             unconditionally. Nothing about the map
                                             provider gates this element. --}}
                                        <p class="text-base text-neutral-600">{{ $cemetery->address }}</p>

                                        {{-- AC3: facilities --}}
                                        @if ($facilities !== [])
                                            <ul class="flex flex-wrap gap-1.5" aria-label="Fasilitas">
                                                @foreach ($facilities as $facility)
                                                    <li>
                                                        <x-mk.badge intent="neutral" size="sm">{{ $facility }}</x-mk.badge>
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif

                                        {{-- AC3: price range WITH source. The two
                                             are rendered by one block so a figure
                                             can never appear without its
                                             attribution (design-system.md §2.3). --}}
                                        <div class="space-y-0.5">
                                            @if ($priceRange !== null && $priceAttribution !== null)
                                                <p class="text-base font-medium text-neutral-900">{{ $priceRange }}</p>
                                                <p class="text-sm text-[var(--mk-text-muted)]">
                                                    Sumber: {{ $priceAttribution['source'] }}@if ($priceAttribution['effective']) &middot; per {{ $priceAttribution['effective'] }}@endif
                                                </p>
                                                <p class="text-sm text-[var(--mk-text-muted)]">
                                                    Kisaran indikatif, {{ CemeteryAvailabilityIntent::NEEDS_CONFIRMATION_LABEL }}.
                                                </p>
                                            @else
                                                <p class="text-sm text-[var(--mk-text-muted)]">
                                                    Kisaran biaya belum tersedia untuk lokasi ini.
                                                </p>
                                            @endif
                                        </div>
                                    </div>
                                </x-mk.card>
                            </li>
                        @endforeach
                    </ul>
                @endif

                {{-- §6.10 Customer-service escape hatch, present regardless of
                     which branch above rendered. --}}
                <div class="mt-10 flex flex-col items-center gap-2 border-t border-[var(--mk-border-subtle)] pt-6 text-center">
                    <p class="text-base text-neutral-600">
                        Butuh bantuan memilih lokasi, atau ingin memastikan ketersediaan hari ini?
                    </p>
                    <x-mk.button variant="secondary" :href="route('bantuan.index')">
                        Hubungi Customer Service
                    </x-mk.button>
                </div>
            @endif
        </div>
    </div>
</div>

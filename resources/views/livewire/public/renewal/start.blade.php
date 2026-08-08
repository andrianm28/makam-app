{{--
    resources/views/livewire/public/renewal/start.blade.php

    App\Livewire\Public\Renewal\RenewalStart's view — `/perpanjangan`,
    screen PUB-030 ("Renewal Step 1-2 | city/cemetery selection"). Sprint 4
    S4-T7, `.kiro/specs/renewal-and-grave-registry` AC1 (steps 1-2 of six)
    and AC2 (all five MVP launch cities). See that class's own doc block for
    why `G-DATA-01` produces a dismissible BANNER on this screen and a full
    explanatory PAGE on the next one.

    --- Buttons go through <x-mk.button> ---
    Unlike resources/views/livewire/public/faq/index.blade.php, which
    hand-writes button markup, every button on this page uses the primitive.
    That hand-written markup was the correct response to finding N-14
    ("Undefined variable $loading") at the time; N-14 is now fixed and the
    fix VERIFIED (8 Aug 2026) against real Laravel 13.22.0 + Livewire 4.3.3,
    all three variants including `loading`. design-system.md §9.2 MUST #2
    ("Use the <x-mk.*> primitives in §3. Extend them rather than forking")
    therefore applies to this new page without exception. The pre-existing
    hand-written call sites elsewhere are left alone — migrating them is
    optional cleanup, not this batch's scope.

    --- <x-mk.stepper> gets an explicit aria-label ---
    The primitive's default group name is "Progres pemesanan" (booking).
    Its own doc block anticipates exactly this: "a non-booking journey that
    supplies its own `labels` can also supply its own accessible group name
    instead of emitting a duplicate aria-label attribute on the root
    element." The six labels come from App\Domain\Renewal\
    RenewalJourneyStep, never retyped here — retyped labels are how a
    product contract drifts, and AGENTS.md forbids renaming or reordering a
    documented step.
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">

        {{-- AC1 — all six steps, always visible, even though only 1-3 are
             built this sprint. §6.9's rule that a closed gate never removes
             a required MVP step applies equally to a not-yet-built one: a
             visitor must be able to see the whole journey they are
             entering. --}}
        <x-mk.stepper
            :labels="$stepLabels"
            :step="$currentStep"
            aria-label="Progres perpanjangan makam"
            class="mb-8"
        />

        @php
            $graveSearchFallback = $graveSearchMode->fallback();
        @endphp

        {{-- §6.9 gated fallback banner. Server-resolved via ModeResolver;
             `intent` and `dismissible` both come from GraveSearchMode::
             fallback(), never decided at this call site. Placed above the
             page's own heading so a visitor learns the search step needs a
             manual path BEFORE investing in two selections. --}}
        @if ($graveSearchFallback)
            <div class="mb-8">
                <x-mk.alert
                    :intent="$graveSearchFallback->intent"
                    icon="alert-circle"
                    title="Pencarian Data Makam Belum Tersedia Online"
                    :dismissible="$graveSearchFallback->dismissible"
                    live="polite"
                >
                    Anda tetap dapat memilih kota dan TPU/TPS di halaman ini. Namun pencarian data makam secara
                    online belum kami aktifkan, sehingga langkah berikutnya akan mengarahkan Anda ke bantuan
                    petugas kami. Ini bukan berarti data makam yang Anda cari tidak ada.
                </x-mk.alert>
            </div>
        @endif

        <div class="mx-auto mb-8 max-w-prose space-y-2 text-center">
            <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                Perpanjangan Makam
            </h1>
            <p class="text-base text-neutral-600">
                Pilih kota dan TPU/TPS tempat makam berada untuk memulai proses perpanjangan masa sewa.
            </p>
        </div>

        {{-- ============ AC1 STEP 1 — city ============ --}}
        <section aria-labelledby="renewal-step-1-heading" class="mb-10">
            <h2 id="renewal-step-1-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                Langkah 1 &mdash; Pilih Kota
            </h2>

            {{-- AC2 / AGENTS.md §Mandatory MVP UX: all five launch cities,
                 always, in LaunchCityCode's own order. A city with no
                 published TPU/TPS is still listed — it produces an honest
                 §6.2 empty state at step 2 rather than being hidden, which
                 would be the "hidden omission of a required MVP city" the
                 directory spec names as a negative criterion. --}}
            {{-- `aria-current` is a BOUND attribute resolving to `null` when
                 the city is not selected, never an `@if ... @endif` wrapped
                 around a literal attribute. Blade's ComponentTagCompiler
                 parses a component tag's attribute list with a regex whose
                 name pattern (`[\w\-:.@%]+`) matches `@if` as an ATTRIBUTE
                 NAME — a directive written inside an `<x-...>` tag does not
                 execute, it compiles into a junk attribute. Verified
                 against the installed framework source, not assumed.
                 `Illuminate\View\ComponentAttributeBag::__toString()` skips
                 any attribute whose value is `null` or `false`, so the
                 ternary below renders nothing at all when not selected. --}}
            <ul class="flex flex-wrap gap-3" aria-label="Kota peluncuran">
                @foreach ($cities as $cityOption)
                    @php($isSelectedCity = $city === $cityOption['code'])
                    <li>
                        <x-mk.button
                            :variant="$isSelectedCity ? 'primary' : 'secondary'"
                            wire:click="selectCity('{{ $cityOption['code'] }}')"
                            wire:loading.attr="disabled"
                            wire:target="selectCity"
                            :aria-current="$isSelectedCity ? 'step' : null"
                        >
                            {{ $cityOption['label'] }}
                        </x-mk.button>
                    </li>
                @endforeach
            </ul>

            @if ($city !== '')
                <p class="mt-3 text-sm text-neutral-600">
                    Kota terpilih: <span class="font-medium text-neutral-900">{{ $selectedCityLabel }}</span>.
                    <button
                        type="button"
                        wire:click="resetCity"
                        class="underline underline-offset-2 hover:text-neutral-900 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
                    >
                        Ganti kota
                    </button>
                </p>
            @endif
        </section>

        {{-- ============ AC1 STEP 2 — TPU/TPS ============ --}}
        <section aria-labelledby="renewal-step-2-heading">
            <h2 id="renewal-step-2-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                Langkah 2 &mdash; Pilih TPU/TPS
            </h2>

            @if ($city === '')
                {{-- Not an error and not an empty result — step 2 simply has
                     no input yet. Stated plainly so the section is never a
                     blank region with a heading above it. --}}
                <p class="text-base text-neutral-600">
                    Pilih kota terlebih dahulu untuk melihat daftar TPU/TPS yang tersedia.
                </p>
            @else
                {{-- §6.1 Loading — skeleton rows reserve the real card
                     height so the layout does not jump when the list
                     arrives (tasks.md: "skeletons reserve exact heights").
                     Scoped with wire:target so it reacts only to a city
                     change, not to unrelated component activity. --}}
                <div wire:loading.delay wire:target="selectCity,resetCity" class="grid gap-4 md:grid-cols-2" aria-busy="true">
                    <div class="h-28 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
                    <div class="h-28 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
                    <span class="sr-only">Memuat daftar TPU/TPS&hellip;</span>
                </div>

                <div wire:loading.remove.delay wire:target="selectCity,resetCity">
                    @if ($cemeteryListUnavailable)
                        {{-- §6.5 Provider unavailable. Distinct from "this
                             city has no TPU/TPS": the list could not be
                             read, which says nothing about whether one
                             exists. --}}
                        <x-mk.alert intent="pending" title="Daftar TPU/TPS sedang tidak dapat dimuat" live="polite">
                            Kami tidak dapat memuat daftar TPU/TPS untuk {{ $selectedCityLabel }} saat ini. Silakan coba
                            beberapa saat lagi, atau
                            <a href="/bantuan" class="font-medium underline underline-offset-2">hubungi Bantuan</a>
                            agar petugas kami membantu langsung.
                        </x-mk.alert>
                    @elseif ($cemeteries->isEmpty())
                        {{-- §6.2 Empty — three parts: what is empty, why,
                             what to do next. Never a bare "Tidak ada data". --}}
                        <div class="flex flex-col items-center gap-3 py-12 text-center">
                            <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />
                            <h3 class="text-lg font-semibold text-neutral-800">
                                Belum ada TPU/TPS terdaftar di {{ $selectedCityLabel }}.
                            </h3>
                            <p class="max-w-prose text-base text-neutral-600">
                                Data TPU/TPS untuk kota ini belum lengkap di sistem kami. Ini tidak berarti tidak ada
                                TPU/TPS di {{ $selectedCityLabel }} &mdash; hanya belum terdaftar di sini. Silakan pilih
                                kota lain, atau hubungi Bantuan agar petugas kami membantu pencarian Anda.
                            </p>
                            <x-mk.button variant="secondary" href="/bantuan" class="mt-2">
                                Hubungi Bantuan
                            </x-mk.button>
                        </div>
                    @else
                        <p class="sr-only" role="status" aria-live="polite">
                            {{ $cemeteries->count() }} TPU/TPS ditemukan di {{ $selectedCityLabel }}.
                        </p>

                        <ul class="grid gap-4 md:grid-cols-2" aria-label="Daftar TPU/TPS">
                            @foreach ($cemeteries as $cemetery)
                                <li>
                                    <x-mk.card class="flex h-full flex-col gap-2">
                                        <h3 class="text-lg font-semibold text-neutral-900">{{ $cemetery->name }}</h3>
                                        <p class="text-sm text-neutral-600">{{ $cemetery->address }}</p>

                                        @if ($cemetery->operator_name)
                                            <p class="text-sm text-neutral-600">
                                                Pengelola: {{ $cemetery->operator_name }}
                                            </p>
                                        @endif

                                        <div class="mt-auto pt-3">
                                            {{-- Step 3. `tpu` is the query
                                                 parameter GraveSearch binds
                                                 to (#[Url(as: 'tpu')]). --}}
                                            <x-mk.button
                                                variant="primary"
                                                href="/perpanjangan/cari?tpu={{ urlencode($cemetery->id) }}"
                                            >
                                                Lanjut ke Pencarian Makam
                                            </x-mk.button>
                                        </div>
                                    </x-mk.card>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endif
        </section>

        {{-- §6.10 support escape hatch — required on every transactional
             screen, and product-brief.md §5 point 10 requires it on every
             page. --}}
        <p class="mt-10 text-center text-sm text-neutral-600">
            Kesulitan menemukan TPU/TPS yang Anda cari?
            <a href="/bantuan" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>
            dan petugas kami akan membantu.
        </p>
    </div>
</div>

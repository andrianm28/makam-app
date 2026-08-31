{{--
    resources/views/livewire/public/renewal/start.blade.php

    App\Livewire\Public\Renewal\RenewalStart's view — `/perpanjangan`,
    Screen 1 "Cari Makam" of the consolidated renewal journey (journey steps
    1-3: Kota, TPU/TPS, Cari Makam). Merge of the former start.blade.php
    (steps 1-2) and grave-search.blade.php (step 3) — every state each one
    rendered is preserved verbatim below, only regrouped into one
    progressively-revealed screen instead of two routes.

    THE THREE EMPTY SEARCH STATES ARE STILL NOT INTERCHANGEABLE — see the
    former grave-search.blade.php's own header, carried over in spirit: gate
    closed (§6.4 explanatory page, search never ran) vs. privacy-limited
    (§6.2, matched but withheld) vs. no-result (§6.2, three parts, the only
    branch allowed to say nothing was found). Every branch below still reads
    a separate fact off `App\Domain\GraveRegistry\GraveSearchOutcome`.

    --- Never use the inline `@php(...)` shorthand in this file ---
    `Illuminate\View\Compilers\BladeCompiler::storePhpBlocks()` extracts
    every `@php` block with `/(?<!@)@php(.*?)@endphp/s` — non-greedy but
    DOTALL, so it pairs the FIRST unmatched `@php` it finds with the
    NEAREST `@endphp` ANYWHERE LATER IN THE WHOLE FILE, not the next line.
    The inline `@php($x = ...)` shorthand has no `@endphp` of its own, so
    once this file gained a real `@php ... @endphp` block further down (the
    `$resultHeaders`/`$resultRows` block below), an earlier inline
    `@php($isSelectedCity = ...)` got paired with THAT block's `@endphp`
    instead, silently swallowing every line in between — HTML, directives,
    `<x-mk.*>` tags and all — as literal PHP, and breaking the whole view
    with an opaque "unexpected token" parse error. Found by rendering this
    file for real, the same way the former grave-search.blade.php's own
    "NOTE FOR EDITORS" trap (git history has it) was found. Both `@php`
    usages below are therefore the explicit `@php ... @endphp` block form
    with an immediately-adjacent `@endphp`, never the inline shorthand.
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">

        <x-mk.stepper
            :labels="$stepLabels"
            :step="$currentStep"
            aria-label="Progres perpanjangan makam"
            class="mb-8"
        />

        @php
            $graveSearchFallback = $graveSearchMode->fallback();
        @endphp

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
                Cari makam yang akan diperpanjang masa sewanya.
            </p>
        </div>

        {{-- ============ Step 1 — city ============ --}}
        <section aria-labelledby="renewal-step-1-heading" class="mb-10">
            <h2 id="renewal-step-1-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                Langkah 1 &mdash; Pilih Kota
            </h2>

            <ul class="flex flex-wrap gap-3" aria-label="Kota peluncuran">
                @foreach ($cities as $cityOption)
                    @php
                        $isSelectedCity = $city === $cityOption['code'];
                    @endphp
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
                    <x-mk.button variant="link" wire:click="resetCity">
                        Ganti kota
                    </x-mk.button>
                </p>
            @endif
        </section>

        {{-- ============ Step 2 — TPU/TPS ============ --}}
        @if ($city !== '')
        <section aria-labelledby="renewal-step-2-heading" class="mb-10">
            <h2 id="renewal-step-2-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                Langkah 2 &mdash; Pilih TPU/TPS
            </h2>

            <div wire:loading.delay wire:target="selectCity,resetCity" class="grid gap-4 md:grid-cols-2" aria-busy="true">
                <div class="h-28 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
                <div class="h-28 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
                <span class="sr-only">Memuat daftar TPU/TPS&hellip;</span>
            </div>

            <div wire:loading.remove.delay wire:target="selectCity,resetCity">
                @if ($cemeteryListUnavailable)
                    <x-mk.alert intent="pending" title="Daftar TPU/TPS sedang tidak dapat dimuat" live="polite">
                        Kami tidak dapat memuat daftar TPU/TPS untuk {{ $selectedCityLabel }} saat ini. Silakan coba
                        beberapa saat lagi, atau
                        <a href="/bantuan" class="font-medium underline underline-offset-2">hubungi Bantuan</a>
                        agar petugas kami membantu langsung.
                    </x-mk.alert>
                @elseif ($cemeteries->isEmpty())
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
                            @php
                                $isSelectedCemetery = $cemeteryId === $cemetery->id;
                            @endphp
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
                                        <x-mk.button
                                            :variant="$isSelectedCemetery ? 'primary' : 'secondary'"
                                            wire:click="selectCemetery('{{ $cemetery->id }}')"
                                            wire:loading.attr="disabled"
                                            wire:target="selectCemetery"
                                        >
                                            {{ $isSelectedCemetery ? 'Terpilih' : 'Lanjut ke Pencarian Makam' }}
                                        </x-mk.button>
                                    </div>
                                </x-mk.card>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </div>
        </section>
        @endif

        {{-- ============ Step 3 — Cari Makam ============ --}}
        @if ($selectedCemetery !== null)
        <section aria-labelledby="renewal-step-3-heading">
            <h2 id="renewal-step-3-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                Langkah 3 &mdash; Cari Makam
            </h2>

            @if ($gateClosed)
                <x-mk.gate-closed-page heading="Pencarian Data Makam Belum Tersedia" icon="inbox">
                    <p>
                        Pencarian data makam secara online belum kami aktifkan. Basis data registri makam sedang kami
                        siapkan bersama pengelola TPU/TPS, dan kami belum dapat membukanya untuk pencarian mandiri.
                    </p>
                    <p class="mt-3">
                        <strong class="font-semibold">Ini tidak berarti data makam yang Anda cari tidak ada.</strong>
                        Petugas kami dapat membantu memeriksakan data makam dan proses perpanjangannya secara manual.
                    </p>

                    <x-slot:fallback>
                        <x-mk.button variant="primary" href="/bantuan">
                            Hubungi Bantuan
                        </x-mk.button>
                    </x-slot:fallback>

                    <x-slot:support>
                        Anda juga dapat membaca
                        <a href="/faq" class="font-medium underline underline-offset-2">pertanyaan yang sering diajukan</a>.
                    </x-slot:support>
                </x-mk.gate-closed-page>
            @else
                <p class="mb-6 text-base text-neutral-600">
                    Mencari di <span class="font-medium text-neutral-900">{{ $selectedCemetery->name }}</span>.
                </p>

                <form wire:submit.prevent="search" role="search" aria-label="Cari data makam" class="mx-auto mb-8 max-w-form">
                    <div class="flex flex-col gap-4">
                        <div class="flex flex-col gap-1.5">
                            <label for="grave-search-name" class="text-base font-medium text-neutral-800">
                                Nama almarhum
                            </label>
                            <input
                                type="search"
                                id="grave-search-name"
                                name="name"
                                wire:model="name"
                                autocomplete="off"
                                placeholder="Contoh: Budi Santoso"
                                @if ($errors->has('name')) aria-invalid="true" aria-describedby="grave-search-name-error" @endif
                                class="h-11 w-full rounded-md border bg-neutral-0 px-4 text-base text-neutral-900
                                    placeholder:text-neutral-500
                                    transition-[border-color,box-shadow] duration-fast ease-standard
                                    focus:outline-none focus:ring-2 focus:ring-offset-1
                                    {{ $errors->has('name')
                                        ? 'border-danger-600 focus:border-danger-600 focus:ring-danger-600'
                                        : 'border-neutral-450 hover:border-neutral-600 focus:border-primary-600 focus:ring-primary-600' }}"
                            >
                            <p class="text-sm text-neutral-600">
                                Pencarian memaklumi perbedaan ejaan dan tanda baca, jadi tidak harus persis sama.
                            </p>
                            @error('name')
                                <p id="grave-search-name-error" class="text-sm text-danger-700">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex flex-col gap-4 sm:flex-row">
                            <div class="flex flex-1 flex-col gap-1.5">
                                <label for="grave-search-block" class="text-base font-medium text-neutral-800">
                                    Blok <span class="font-normal text-neutral-600">(opsional)</span>
                                </label>
                                <input
                                    type="text"
                                    id="grave-search-block"
                                    name="block"
                                    wire:model="block"
                                    autocomplete="off"
                                    placeholder="Contoh: A-12"
                                    @if ($errors->has('block')) aria-invalid="true" aria-describedby="grave-search-block-error" @endif
                                    class="h-11 w-full rounded-md border bg-neutral-0 px-4 text-base text-neutral-900
                                        placeholder:text-neutral-500
                                        transition-[border-color,box-shadow] duration-fast ease-standard
                                        focus:outline-none focus:ring-2 focus:ring-offset-1
                                        {{ $errors->has('block')
                                            ? 'border-danger-600 focus:border-danger-600 focus:ring-danger-600'
                                            : 'border-neutral-450 hover:border-neutral-600 focus:border-primary-600 focus:ring-primary-600' }}"
                                >
                                @error('block')
                                    <p id="grave-search-block-error" class="text-sm text-danger-700">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="flex flex-1 flex-col gap-1.5">
                                <label for="grave-search-death-date" class="text-base font-medium text-neutral-800">
                                    Tanggal wafat <span class="font-normal text-neutral-600">(opsional)</span>
                                </label>
                                <input
                                    type="date"
                                    id="grave-search-death-date"
                                    name="death_date"
                                    wire:model="deathDate"
                                    @if ($errors->has('deathDate')) aria-invalid="true" aria-describedby="grave-search-death-date-error" @endif
                                    class="h-11 w-full rounded-md border bg-neutral-0 px-4 text-base text-neutral-900
                                        placeholder:text-neutral-500
                                        transition-[border-color,box-shadow] duration-fast ease-standard
                                        focus:outline-none focus:ring-2 focus:ring-offset-1
                                        {{ $errors->has('deathDate')
                                            ? 'border-danger-600 focus:border-danger-600 focus:ring-danger-600'
                                            : 'border-neutral-450 hover:border-neutral-600 focus:border-primary-600 focus:ring-primary-600' }}"
                                >
                                @error('deathDate')
                                    <p id="grave-search-death-date-error" class="text-sm text-danger-700">{{ $message }}</p>
                                @enderror
                            </div>
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <x-mk.button
                                variant="primary"
                                type="submit"
                                wire:loading.attr="disabled"
                                wire:target="search"
                            >
                                Cari Data Makam
                            </x-mk.button>

                            @if ($name !== '' || $block !== '' || $deathDate !== '')
                                <x-mk.button variant="secondary" wire:click="resetSearch">
                                    Reset pencarian
                                </x-mk.button>
                            @endif
                        </div>
                    </div>
                </form>

                <div wire:loading.delay wire:target="search" class="space-y-3" aria-busy="true">
                    <div class="h-16 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
                    <div class="h-16 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
                    <div class="h-16 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
                    <span class="sr-only">Mencari data makam&hellip;</span>
                </div>

                <div wire:loading.remove.delay wire:target="search">
                    @if ($searchUnavailable)
                        <x-mk.alert intent="pending" title="Pencarian sedang tidak dapat diproses" live="polite">
                            Sistem pencarian data makam sedang tidak dapat diakses. Ini bukan hasil pencarian &mdash; kami
                            belum sempat memeriksa data apa pun. Silakan coba lagi beberapa saat lagi, atau
                            <a href="/bantuan" class="font-medium underline underline-offset-2">hubungi Bantuan</a>
                            agar petugas kami memeriksakan secara manual.
                        </x-mk.alert>

                    @elseif ($resultsShown)
                        <p class="sr-only" role="status" aria-live="polite">
                            @if ($outcome->isNoResult())
                                Data makam tidak ditemukan di {{ $selectedCemetery->name }}. Registri makam kami belum tentu lengkap, jadi hasil ini belum tentu berarti makam yang Anda cari tidak ada. Lanjutkan lewat tombol Input manual atau Hubungi bantuan di bawah.
                            @else
                                {{ $outcome->matchCount() }} data makam cocok dengan pencarian Anda.
                            @endif
                        </p>

                        @if ($outcome->hasOpenResults())
                            <ul class="flex flex-col gap-3" aria-label="Hasil pencarian data makam">
                                @foreach ($outcome->openResults as $index => $row)
                                    <li wire:key="grave-result-{{ $index }}">
                                        <x-mk.card class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                                            <dl class="grid grid-cols-2 gap-x-4 gap-y-1 text-sm">
                                                <dt class="text-neutral-600">Nama Almarhum</dt>
                                                <dd class="font-medium text-neutral-900">{{ $row->deceasedName }}</dd>
                                                <dt class="text-neutral-600">Blok</dt>
                                                <dd class="font-medium text-neutral-900">{{ $row->block }}</dd>
                                                <dt class="text-neutral-600">Tanggal Wafat</dt>
                                                <dd class="font-medium text-neutral-900">{{ $row->deathDate ?? 'Tidak tercatat' }}</dd>
                                                <dt class="text-neutral-600">Jatuh Tempo</dt>
                                                <dd class="font-medium text-neutral-900">{{ $row->dueDate ?? 'Tidak tercatat' }}</dd>
                                            </dl>
                                            <x-mk.button
                                                variant="primary"
                                                wire:click="selectGraveForRenewal({{ $index }})"
                                                wire:loading.attr="disabled"
                                                wire:target="selectGraveForRenewal"
                                            >
                                                Lanjutkan ke Pembayaran
                                            </x-mk.button>
                                        </x-mk.card>
                                    </li>
                                @endforeach
                            </ul>

                            @if ($outcome->matchCount() >= $maxResults)
                                <p class="mt-3 text-sm text-neutral-600">
                                    Menampilkan {{ $maxResults }} data teratas. Persempit pencarian dengan menambahkan blok
                                    atau tanggal wafat bila makam yang Anda cari belum terlihat.
                                </p>
                            @endif
                        @endif

                        @if ($outcome->isPrivacyLimited())
                            <div class="mt-6">
                                <x-mk.card intent="info" class="flex flex-col gap-3">
                                    <div class="flex items-start gap-3">
                                        <x-dynamic-component component="icon.shield-check" class="size-6 shrink-0 text-neutral-600" aria-hidden="true" />
                                        <div class="space-y-2">
                                            <h2 class="text-lg font-semibold text-neutral-900">
                                                {{ $outcome->restrictedCount() }} data makam cocok, tetapi aksesnya dibatasi.
                                            </h2>
                                            <p class="max-w-prose text-base text-neutral-700">
                                                Data makam tersebut <span class="font-semibold">ada di sistem kami</span>.
                                                Pengelola TPU/TPS membatasi informasi yang boleh ditampilkan untuk pencarian
                                                publik, sehingga kami tidak dapat menampilkan nama, tanggal wafat, dan
                                                jatuh temponya di halaman ini.
                                            </p>
                                            <p class="max-w-prose text-base text-neutral-700">
                                                Petugas kami dapat memverifikasi data ini bersama Anda sebagai ahli waris
                                                dan melanjutkan proses perpanjangan.
                                            </p>
                                        </div>
                                    </div>

                                    @php
                                        $restrictedBlocks = collect($outcome->restrictedResults)
                                            ->filter(fn ($row) => $row->block !== null)
                                            ->pluck('block')
                                            ->unique()
                                            ->values();
                                    @endphp

                                    @if ($restrictedBlocks->isNotEmpty())
                                        <p class="text-sm text-neutral-700">
                                            Blok yang cocok:
                                            <span class="font-medium">{{ $restrictedBlocks->implode(', ') }}</span>.
                                        </p>
                                    @endif

                                    <div class="flex flex-wrap gap-2 pt-1">
                                        <x-mk.button variant="primary" href="/bantuan">
                                            Verifikasi lewat Bantuan
                                        </x-mk.button>
                                    </div>
                                </x-mk.card>
                            </div>
                        @endif

                        @if ($outcome->hasExampleData())
                            <p class="mt-3 text-sm text-neutral-600">
                                Sebagian hasil pencarian ini adalah <span class="font-medium">data contoh</span> untuk
                                keperluan uji coba, bukan data makam yang sebenarnya.
                            </p>
                        @endif

                        @if ($outcome->isNoResult())
                            <div class="flex flex-col items-center gap-3 py-12 text-center">
                                <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />

                                <h2 class="text-lg font-semibold text-neutral-800">
                                    Data makam tidak ditemukan.
                                </h2>

                                <p class="max-w-prose text-base text-neutral-600">
                                    Tidak ada data yang cocok dengan pencarian Anda di {{ $selectedCemetery->name }}.
                                    <span class="font-medium text-neutral-800">Registri makam kami belum tentu lengkap</span>
                                    &mdash; banyak data lama belum kami terima dari pengelola TPU/TPS.
                                    <span class="font-medium text-neutral-800">Hasil ini belum tentu berarti makam yang Anda cari tidak ada.</span>
                                </p>

                                <p class="max-w-prose text-base text-neutral-600">
                                    Coba ejaan lain atau kosongkan blok/tanggal wafat, atau lanjutkan lewat jalur di bawah
                                    ini agar petugas kami mencarikan secara manual.
                                </p>

                                <div class="flex flex-wrap justify-center gap-2 pt-2">
                                    <x-mk.button variant="primary" href="/bantuan">
                                        Input manual
                                    </x-mk.button>
                                    <x-mk.button variant="secondary" href="/bantuan">
                                        Hubungi bantuan
                                    </x-mk.button>
                                </div>
                            </div>
                        @endif

                    @else
                        <p class="py-8 text-center text-base text-neutral-600">
                            Isi minimal satu kolom di atas, lalu tekan &ldquo;Cari Data Makam&rdquo;.
                        </p>
                    @endif
                </div>
            @endif
        </section>
        @endif

        <p class="mt-10 text-center text-sm text-neutral-600">
            Butuh bantuan menelusuri data makam?
            <a href="/bantuan" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>.
        </p>
    </div>
</div>

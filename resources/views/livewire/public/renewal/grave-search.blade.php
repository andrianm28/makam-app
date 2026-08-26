{{--
    resources/views/livewire/public/renewal/grave-search.blade.php

    App\Livewire\Public\Renewal\GraveSearch's view — `/perpanjangan/cari`,
    screen PUB-031 ("Grave search | results, no result, privacy-limited").
    Sprint 4 S4-T7, `.kiro/specs/renewal-and-grave-registry` AC3, AC5, AC14,
    AC16.

    ===========================================================================
    THE THREE EMPTY STATES ARE NOT INTERCHANGEABLE
    ===========================================================================
    tasks.md calls collapsing them into one message a defect, and gives the
    reason in one sentence: "a family searching for a grave record and
    finding nothing must not conclude the grave does not exist." Read that
    before editing any copy below.

      GATE CLOSED   §6.4 explanatory page, never a 404. The search never
                    ran. This is the DEFAULT state — every feature gate
                    seeds `closed`.
      PRIVACY-      §6.2. Records MATCHED but their access mode withholds
      LIMITED       fields. The words "tidak ditemukan" must never appear
                    in this branch.
      NO-RESULT     §6.2, three parts. The only branch permitted to say
                    nothing was found, and even then it must say the
                    registry may be incomplete (AC5).

    The branches below are mutually exclusive by construction, and their
    ORDER is load-bearing: gate first (the capability does not exist), then
    provider-unavailable (the search could not run), then privacy-limited
    (matches exist), then no-result (nothing matched). Any reordering makes
    a more specific truth get swallowed by a vaguer one.

    Every branch's `@if` reads a separate fact off
    App\Domain\GraveRegistry\GraveSearchOutcome rather than a single
    `isEmpty()`, so the distinction is enforced in data, not in this file's
    discipline alone.

    --- Buttons and the stepper ---
    Same two notes as start.blade.php: <x-mk.button> is used directly
    (finding N-14 is fixed and the fix verified 8 Aug 2026), and
    <x-mk.stepper> receives this journey's own six labels from
    App\Domain\Renewal\RenewalJourneyStep plus its own accessible group
    name, exactly as that primitive's doc block anticipates.

    --- Why the search inputs are hand-written and not <x-mk.field> ---
    Not a style choice and not a fork. <x-mk.field> merges $attributes onto
    its OUTER wrapping <div> only; its inner <input> has a closed attribute
    list and never spreads $attributes, so a `wire:model` passed to it lands
    on a <div> that never fires an input/change event and Livewire's binding
    silently does nothing. That limitation is documented at length in
    resources/views/livewire/public/faq/index.blade.php, which hit it first.
    Every class on the inputs below is copied verbatim from
    field.blade.php's own $controlBase/$stateClasses strings — no new design
    value is introduced here.
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">

        <x-mk.stepper
            :labels="$stepLabels"
            :step="$currentStep"
            aria-label="Progres perpanjangan makam"
            class="mb-8"
        />

        {{-- ================================================================
             STATE 1 of 3 — GATE CLOSED (AC16, design-system.md §6.4)
             ================================================================
             `G-DATA-01` is closed, so the search capability is unavailable
             to everyone and no search is run. §6.4: "Never render a raw
             403/404 for a gated route"; information-architecture.md §4:
             "Route unavailable karena gate harus memberi explanatory page,
             bukan 404 generik."

             This is the state an unmodified environment renders, because
             2026_07_26_120400_seed_feature_gate_registry.php seeds every
             gate closed.

             The copy is explicit that this says NOTHING about whether the
             record exists — the same discipline the privacy-limited state
             needs, for the same reason.

             icon="inbox", not icon="slash": `icon.slash` is StatusIntent's
             DIBATALKAN/cancelled badge glyph (app/Support/Design/
             StatusIntent.php) — a single bare diagonal stroke, legitimate
             inline next to badge text but unreadable as a lone size-12
             page icon (UI/UX audit, 26 Aug 2026). `icon.inbox` is the
             glyph this same file already uses for its other empty/no-data
             state (STATE 3 of 3 below) and renders a real, recognizable
             outline — reused here for consistency. --}}
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
                    Anda juga dapat kembali ke
                    <a href="/perpanjangan" class="font-medium underline underline-offset-2">pemilihan TPU/TPS</a>
                    atau membaca
                    <a href="/faq" class="font-medium underline underline-offset-2">pertanyaan yang sering diajukan</a>.
                </x-slot:support>
            </x-mk.gate-closed-page>

        {{-- ================================================================
             The gate is OPEN from here down.
             ================================================================ --}}
        @elseif ($cemetery === null)
            {{-- Not one of the three empty states: the visitor has no valid
                 published TPU/TPS selected (never chose one, or arrived
                 with a stale/tampered `?tpu=`). A 404 would be wrong —
                 `/perpanjangan/cari` is a real route — and so would running
                 an unscoped registry-wide search. Send them back to step 2. --}}
            <div class="flex flex-col items-center gap-3 py-12 text-center">
                <x-dynamic-component component="icon.question-mark-circle" class="size-12 text-neutral-400" aria-hidden="true" />
                <h1 class="text-lg font-semibold text-neutral-800">Pilih TPU/TPS terlebih dahulu.</h1>
                <p class="max-w-prose text-base text-neutral-600">
                    Pencarian data makam dilakukan di dalam satu TPU/TPS. Silakan pilih kota dan TPU/TPS pada langkah
                    sebelumnya, lalu kembali ke halaman ini.
                </p>
                <x-mk.button variant="primary" href="/perpanjangan" class="mt-2">
                    Pilih Kota dan TPU/TPS
                </x-mk.button>
            </div>

        @else
            <div class="mx-auto mb-8 max-w-prose space-y-2 text-center">
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                    Cari Data Makam
                </h1>
                <p class="text-base text-neutral-600">
                    Mencari di <span class="font-medium text-neutral-900">{{ $cemetery->name }}</span>.
                    <a href="/perpanjangan" class="underline underline-offset-2">Ganti TPU/TPS</a>
                </p>
            </div>

            {{-- AC3 — deceased name, block, death date. Field names match
                 docs/contracts/openapi.yaml's own `name` / `block` /
                 `death_date` query parameters for
                 GET /grave-records/search. --}}
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

            {{-- §6.1 Loading — three skeleton rows at the real result-row
                 height so the layout does not shift when results arrive
                 (tasks.md: "skeletons reserve exact heights ... CLS < 0.1").
                 NOT VERIFIED: CLS cannot be measured on this host (no
                 browser) — see this batch's report. --}}
            <div wire:loading.delay wire:target="search" class="space-y-3" aria-busy="true">
                <div class="h-16 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
                <div class="h-16 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
                <div class="h-16 rounded-lg bg-[var(--mk-skeleton-base)] animate-pulse"></div>
                <span class="sr-only">Mencari data makam&hellip;</span>
            </div>

            <div wire:loading.remove.delay wire:target="search">
                @if ($searchUnavailable)
                    {{-- §6.5 Provider unavailable. Checked BEFORE the two
                         result-driven empty states, and deliberately so: a
                         backend failure produces an empty outcome, and if
                         this branch came later that empty outcome would
                         render as "data makam tidak ditemukan" — telling a
                         family their relative is not in the registry
                         because a database was down. --}}
                    <x-mk.alert intent="pending" title="Pencarian sedang tidak dapat diproses" live="polite">
                        Sistem pencarian data makam sedang tidak dapat diakses. Ini bukan hasil pencarian &mdash; kami
                        belum sempat memeriksa data apa pun. Silakan coba lagi beberapa saat lagi, atau
                        <a href="/bantuan" class="font-medium underline underline-offset-2">hubungi Bantuan</a>
                        agar petugas kami memeriksakan secara manual.
                    </x-mk.alert>

                @elseif ($resultsShown)
                    {{-- The ONLY programmatic announcement of a search
                         outcome. It used to be a bare count for every
                         outcome, which meant a screen-reader user whose
                         search matched nothing heard "0 data makam cocok
                         dengan pencarian Anda" — a bare no-data
                         announcement, which design-system.md §6.2 forbids in
                         terms ("Three parts, always: what is empty · why ·
                         what to do next. Never a bare 'Tidak ada data'").
                         The visible no-result card below carries all three
                         parts; the announcement carried none of them.

                         Kept shorter than the visible copy on purpose — this
                         is heard, not read — but it carries the same three
                         parts, because the whole point of this screen is
                         that "nothing matched" must never be heard as "the
                         grave does not exist".

                         Long sentences stay on ONE source line each, per
                         this file's note further down: wrapping them inserts
                         a newline into the rendered HTML and an assertSee()
                         on the phrase silently fails. --}}
                    <p class="sr-only" role="status" aria-live="polite">
                        @if ($outcome->isNoResult())
                            Data makam tidak ditemukan di {{ $cemetery->name }}. Registri makam kami belum tentu lengkap, jadi hasil ini belum tentu berarti makam yang Anda cari tidak ada. Lanjutkan lewat tombol Input manual atau Hubungi bantuan di bawah.
                        @else
                            {{ $outcome->matchCount() }} data makam cocok dengan pencarian Anda.
                        @endif
                    </p>

                    {{-- ------------------------------------------------------
                         Readable results (access_mode = open).
                         <x-mk.table> renders a real <table> at md+ and
                         stacked cards below it, per §3.5/§4.3 — result
                         tables become cards below --breakpoint-md.
                         ------------------------------------------------------ --}}
                    @if ($outcome->hasOpenResults())
                        {{-- Rows are built in the PHP block below rather
                             than inline in the `:rows` attribute. Blade does
                             support a multi-line PHP expression there, but a
                             closure full of quotes and `->` inside a
                             component tag's attribute list is exactly the
                             shape that is hard to eyeball for a quoting
                             mistake, and a mistake there fails at compile
                             time with an opaque message.

                             NOTE FOR EDITORS — a real trap, hit while
                             building this file. The two directive names
                             that open and close a raw-PHP block must NEVER
                             be written out inside a Blade comment, in this
                             or any other view. `BladeCompiler::
                             compileString()` calls `storeUncompiledBlocks()`
                             BEFORE `compileComments()`, and that method
                             matches those two names non-greedily across the
                             whole template. A comment merely MENTIONING the
                             opening one therefore starts a genuine raw-PHP
                             block right there and swallows everything up to
                             the next closing one — its own comment
                             terminator included. Here that silently ate this
                             comment, the block below, and the entire result
                             table, and surfaced 300 lines away as
                             "unexpected token else". Comments are stripped
                             before ordinary directives compile, so `@if` and
                             friends are safe to name; these two are not. --}}
                        @php
                            $resultHeaders = [
                                ['key' => 'deceased_name', 'label' => 'Nama Almarhum'],
                                ['key' => 'block', 'label' => 'Blok'],
                                ['key' => 'death_date', 'label' => 'Tanggal Wafat'],
                                ['key' => 'due_date', 'label' => 'Jatuh Tempo'],
                            ];

                            $resultRows = [];

                            foreach ($outcome->openResults as $row) {
                                $resultRows[] = [
                                    'deceased_name' => $row->deceasedName,
                                    'block' => $row->block,
                                    // "Not recorded" is the honest rendering
                                    // of a null date: the registry genuinely
                                    // may not hold one. Never a blank cell,
                                    // which reads as a rendering bug, and
                                    // never an invented date.
                                    'death_date' => $row->deathDate ?? 'Tidak tercatat',
                                    'due_date' => $row->dueDate ?? 'Tidak tercatat',
                                ];
                            }
                        @endphp

                        <x-mk.table
                            :caption="'Hasil pencarian data makam di ' . $cemetery->name"
                            :headers="$resultHeaders"
                            :rows="$resultRows"
                        />

                        @if ($outcome->matchCount() >= $maxResults)
                            <p class="mt-3 text-sm text-neutral-600">
                                Menampilkan {{ $maxResults }} data teratas. Persempit pencarian dengan menambahkan blok
                                atau tanggal wafat bila makam yang Anda cari belum terlihat.
                            </p>
                        @endif
                    @endif

                    {{-- ======================================================
                         STATE 2 of 3 — PRIVACY-LIMITED (AC14, §6.2)
                         ======================================================
                         Records MATCHED. Their configured access mode
                         withholds fields. design-system.md §6.2: "privacy-
                         limited is a distinct state from not found: when a
                         gate restricts the field projection, say so, rather
                         than implying the record does not exist."

                         The words "tidak ditemukan" MUST NOT appear in this
                         branch. The record exists; we are declining to show
                         it. Those are different sentences and a family
                         reading this screen deserves the right one.

                         Rendered whether or not there are also readable
                         results, so a mixed search never quietly
                         under-reports its own match count.
                         ------------------------------------------------------ --}}
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

                                {{-- What `limited` mode does permit: the
                                     location. Enough for a family to
                                     recognise the record and quote
                                     something concrete to a customer-service
                                     agent, without disclosing the
                                     deceased's identity to whoever typed
                                     the name. `closed` rows contribute to
                                     the count above and nothing more. --}}
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

                    {{-- Fabricated seed rows must announce themselves on any
                         surface that shows them — the same honesty
                         discipline the seed migration's "Contoh" name prefix
                         implements at the data layer.

                         Rendered HERE, below both result cards, and read off
                         GraveSearchOutcome::hasExampleData() rather than
                         recomputed from $outcome->openResults. Both facts
                         matter. It used to sit inside the open-results
                         branch and be computed from the open rows alone, so
                         a search whose every match was restricted (the
                         all-restricted example fixture —
                         CemeteryExampleData::ALL_RESTRICTED_SLUG) showed
                         fictional data with no disclosure at all.

                         The copy says "hasil pencarian ini", not "hasil di
                         atas": the label now covers the readable table AND
                         the privacy-limited card, and "di atas" would be
                         wrong the moment only one of the two carries the
                         example rows. --}}
                    @if ($outcome->hasExampleData())
                        <p class="mt-3 text-sm text-neutral-600">
                            Sebagian hasil pencarian ini adalah <span class="font-medium">data contoh</span> untuk
                            keperluan uji coba, bukan data makam yang sebenarnya.
                        </p>
                    @endif

                    {{-- ======================================================
                         STATE 3 of 3 — NO-RESULT (AC5, §6.2)
                         ======================================================
                         Reached only when NOTHING matched at all — not when
                         matches exist but are restricted (that is state 2,
                         above and mutually exclusive with this one via
                         GraveSearchOutcome::isNoResult()).

                         §6.2's three parts, in order: what is empty, WHY
                         (the registry may be incomplete — the registry's
                         fault, not the family's), and what to do next. AC5:
                         "an honest manual-entry or customer-service path
                         where allowed."

                         "Input manual" links to /bantuan rather than to a
                         manual-entry FORM because no such form exists —
                         Sprint 13 owns it. AC5 asks for a manual-entry OR
                         customer-service path; offering a form that goes
                         nowhere would be the dishonest reading. Reported
                         as a known gap rather than faked.
                         ------------------------------------------------------ --}}
                    @if ($outcome->isNoResult())
                        <div class="flex flex-col items-center gap-3 py-12 text-center">
                            <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />

                            <h2 class="text-lg font-semibold text-neutral-800">
                                Data makam tidak ditemukan.
                            </h2>

                            {{-- Long sentences are kept on ONE source line
                                 each. Wrapping them reads better in the
                                 editor but inserts a newline and indentation
                                 into the rendered HTML, so the sentence
                                 never exists as a contiguous string — and
                                 an `assertSee()` on the phrase that carries
                                 this screen's whole meaning silently fails.
                                 Found by rendering this view for real. --}}
                            <p class="max-w-prose text-base text-neutral-600">
                                Tidak ada data yang cocok dengan pencarian Anda di {{ $cemetery->name }}.
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
                    {{-- Arrived without searching yet. Explicitly NOT the
                         no-result state: nothing has been searched for, so
                         nothing can be reported as not found. Rendering
                         "data makam tidak ditemukan" here would be the
                         defect at its worst — telling a family their
                         relative was not found before they typed a name. --}}
                    <p class="py-8 text-center text-base text-neutral-600">
                        Isi minimal satu kolom di atas, lalu tekan &ldquo;Cari Data Makam&rdquo;.
                    </p>
                @endif
            </div>

            {{-- §6.10 support escape hatch. --}}
            <p class="mt-10 text-center text-sm text-neutral-600">
                Butuh bantuan menelusuri data makam?
                <a href="/bantuan" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>.
            </p>
        @endif
    </div>
</div>

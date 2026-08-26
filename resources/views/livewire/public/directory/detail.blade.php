{{--
    resources/views/livewire/public/directory/detail.blade.php

    App\Livewire\Public\Directory\CemeteryDetail's view — route name
    `cemeteries.show`. Sprint 4 S4-T6,
    .kiro/specs/cemetery-directory-and-availability AC3, AC4, AC5, AC6,
    AC11, AC12. Screen PUB-011's "detail" state.

    --- The three rules this file exists to hold ---

    1. AC12: $capabilities is a
       App\Livewire\Public\Directory\Support\PublicCapabilityProjection, NOT
       a CemeteryCapabilityProfile. It has exactly four modes. `registry_mode`
       and `certificate_mode` are absent from openapi.yaml's
       PublicCapabilityProfile schema, are absent from the projection class,
       and must never appear in this file — not as a value, not as a label,
       not as a conditional.

    2. design-system.md §3.7: no `match`/`@if` on a capability string here.
       Availability badges take an already-resolved (intent, icon, label)
       triple from CemeteryAvailabilityIntent.

    Editing these comments: never write Blade's raw-PHP open/close directives
    as literal text in a Blade comment here. `compileString()` runs
    `storeUncompiledBlocks()` before `compileComments()`, so a mention inside
    a comment pairs with this file's real closing token and silently swallows
    everything between — often without a syntax error. See
    directory/index.blade.php's header for the full note (finding N-14's
    actual root cause).

    3. AC11: the textual address renders UNCONDITIONALLY. The Google Maps
       button is a separate element guarded by its own @if — its absence
       never hides the address. Cemetery::googleMapsUrl() returning null is
       an ordinary, expected state.
--}}
@php
    use App\Livewire\Public\Directory\Support\CemeteryAvailabilityIntent;
    use App\Livewire\Public\Directory\Support\CemeteryPresenter;

    $availability = CemeteryAvailabilityIntent::forCemetery($capabilities->availabilityMode);
    $isIndicative = CemeteryAvailabilityIntent::isIndicative($capabilities->availabilityMode);
    $priceRange = CemeteryPresenter::priceRange($cemetery);
    $priceAttribution = CemeteryPresenter::priceAttribution($cemetery);
    $photoUrl = CemeteryPresenter::photoUrl($cemetery);
    $mapUrl = CemeteryPresenter::mapUrl($cemetery);
    $embedMapUrl = CemeteryPresenter::embedMapUrl($cemetery);
    $facilities = CemeteryPresenter::facilities($cemetery);
@endphp

<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">
        <nav aria-label="Navigasi kembali" class="mb-6">
            <a
                href="{{ route('cemeteries.index') }}"
                class="touch-target inline-flex items-center text-base text-primary-700 underline underline-offset-2 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
            >
                Kembali ke direktori
            </a>
        </nav>

        {{-- §6.5 Provider unavailable — capability resolution failed and AC4's
             safe defaults are standing in. Stated before any availability
             figure is shown, never after. --}}
        @if ($capabilitiesDegraded)
            <div class="mb-6">
                <x-mk.alert intent="pending" title="Informasi ketersediaan sedang tidak dapat dimuat" icon="exclamation-triangle" live="polite">
                    Kami menampilkan nilai paling aman untuk lokasi ini, bukan jawaban terbaru dari pengelola.
                    Silakan konfirmasi langsung, atau
                    <a href="{{ route('bantuan.index') }}" class="underline underline-offset-2">hubungi customer service</a>.
                </x-mk.alert>
            </div>
        @endif

        <article class="space-y-8">
            <header class="space-y-3">
                <div class="flex flex-wrap items-center gap-2">
                    {{-- AC3: type --}}
                    <x-mk.badge intent="neutral">{{ $cemetery->type }}</x-mk.badge>

                    {{-- AC3 + AC5: availability. Resolved through
                         CemeteryAvailabilityIntent — neutral + "Perlu
                         konfirmasi" while indicative, never success. --}}
                    <x-mk.badge :intent="$availability['intent']" :icon="$availability['icon']">
                        {{ $availability['label'] }}
                    </x-mk.badge>
                </div>

                {{-- AC3: name --}}
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">{{ $cemetery->name }}</h1>

                @if ($cemetery->operator_name)
                    <p class="text-base text-neutral-600">Dikelola oleh {{ $cemetery->operator_name }}</p>
                @endif
            </header>

            {{-- AC3: photo --}}
            @if ($photoUrl)
                <img
                    src="{{ $photoUrl }}"
                    alt="Ilustrasi {{ $cemetery->name }}"
                    class="h-56 w-full rounded-lg object-cover md:h-72"
                >
            @else
                <div class="flex h-56 w-full items-center justify-center rounded-lg bg-neutral-100 md:h-72">
                    <span class="text-sm text-neutral-600">Foto belum tersedia untuk lokasi ini</span>
                </div>
            @endif

            <section aria-labelledby="alamat-heading" class="space-y-3">
                <h2 id="alamat-heading" class="text-lg font-semibold text-neutral-900">Alamat</h2>

                {{-- AC11 — UNCONDITIONAL. Do not wrap this in the $mapUrl
                     check below. A map-provider failure must never block
                     viewing of the textual address; this element is the
                     guarantee. --}}
                <address class="not-italic text-base text-neutral-700">{{ $cemetery->address }}</address>

                @if ($mapUrl)
                    <x-mk.button variant="secondary" :href="$mapUrl" target="_blank" rel="noopener noreferrer">
                        Buka di Google Maps
                    </x-mk.button>

                    {{-- Enhancement, not a replacement for the link above --
                         $embedMapUrl can independently be null even when
                         $mapUrl is not (this codebase never fabricates a
                         fallback pin), so this is its own conditional, and
                         the address/link above render unconditionally on
                         $mapUrl already, unaffected by this block being
                         absent. --}}
                    @if ($embedMapUrl)
                        <div class="mt-3 overflow-hidden rounded-lg border border-neutral-200">
                            <iframe
                                src="{{ $embedMapUrl }}"
                                title="Peta lokasi {{ $cemetery->name }}"
                                loading="lazy"
                                referrerpolicy="no-referrer-when-downgrade"
                                class="h-64 w-full border-0"
                            ></iframe>
                        </div>
                    @endif
                @else
                    {{-- The map link is genuinely unavailable for this row (no
                         operator URL and no coordinates). Said plainly, so the
                         absence reads as "we do not have this yet" rather than
                         as a broken page. --}}
                    <p class="text-sm text-[var(--mk-text-muted)]">
                        Tautan peta belum tersedia untuk lokasi ini. Alamat di atas tetap dapat digunakan untuk navigasi manual.
                    </p>
                @endif
            </section>

            {{-- AC3: facilities --}}
            <section aria-labelledby="fasilitas-heading" class="space-y-3">
                <h2 id="fasilitas-heading" class="text-lg font-semibold text-neutral-900">Fasilitas</h2>
                @if ($facilities !== [])
                    <ul class="flex flex-wrap gap-2">
                        @foreach ($facilities as $facility)
                            <li>
                                <x-mk.badge intent="neutral">{{ $facility }}</x-mk.badge>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="text-base text-neutral-600">
                        Daftar fasilitas untuk lokasi ini belum kami terima dari pengelola.
                    </p>
                @endif
            </section>

            {{-- AC3: price range WITH source (design-system.md §2.3 — a fee
                 figure always carries its named source and effective date). --}}
            <section aria-labelledby="biaya-heading" class="space-y-2">
                <h2 id="biaya-heading" class="text-lg font-semibold text-neutral-900">Kisaran biaya</h2>
                @if ($priceRange !== null && $priceAttribution !== null)
                    <p class="text-xl font-semibold text-neutral-900">{{ $priceRange }}</p>
                    <p class="text-sm text-[var(--mk-text-muted)]">
                        Sumber: {{ $priceAttribution['source'] }}@if ($priceAttribution['effective']) &middot; per {{ $priceAttribution['effective'] }}@endif
                    </p>
                    <div>
                        {{-- The indicative qualifier is a badge, not just prose,
                             so it carries the same visual weight as the figure
                             it qualifies. neutral — an indicative price styled
                             as success is a false promise (§2.3). --}}
                        <x-mk.badge intent="neutral" icon="clock">
                            {{ CemeteryAvailabilityIntent::NEEDS_CONFIRMATION_LABEL }}
                        </x-mk.badge>
                    </div>
                    <p class="text-base text-neutral-600">
                        Kisaran ini bukan penawaran final. Biaya sebenarnya dikonfirmasi oleh pengelola setelah
                        kebutuhan Anda diketahui.
                    </p>
                @else
                    <p class="text-base text-neutral-600">
                        Kisaran biaya untuk lokasi ini belum tersedia. Silakan hubungi customer service untuk
                        mendapatkan perkiraan sesuai kebutuhan Anda.
                    </p>
                @endif
            </section>

            {{-- AC6: package/class-level availability. --}}
            <section aria-labelledby="paket-heading" class="space-y-3">
                <h2 id="paket-heading" class="text-lg font-semibold text-neutral-900">Ketersediaan paket dan kelas</h2>

                @if ($isIndicative)
                    {{-- AC5 — stated ONCE for the whole section, so each row's
                         own status label ("Tersedia", "Terbatas") is never read
                         as a guarantee. §6.7: a pending state must say what is
                         being waited on and who acts next. --}}
                    <div class="flex flex-wrap items-center gap-2">
                        <x-mk.badge intent="neutral" icon="clock">
                            {{ CemeteryAvailabilityIntent::NEEDS_CONFIRMATION_LABEL }}
                        </x-mk.badge>
                        <p class="text-sm text-[var(--mk-text-muted)]">
                            Seluruh status di bawah bersifat indikatif dan dikonfirmasi ulang oleh pengelola.
                        </p>
                    </div>
                @endif

                @if ($packagesUnavailable)
                    {{-- §6.5 — distinct from "no packages configured" below.
                         Conflating a read failure with an empty result would
                         tell a family a package does not exist when we simply
                         could not read it. --}}
                    <x-mk.alert intent="pending" title="Daftar paket sedang tidak dapat dimuat" icon="exclamation-triangle" live="polite">
                        Informasi paket dan kelas untuk lokasi ini tidak dapat ditampilkan saat ini. Ini bukan berarti
                        paket tersebut tidak tersedia. Silakan
                        <a href="{{ route('bantuan.index') }}" class="underline underline-offset-2">hubungi customer service</a>.
                    </x-mk.alert>
                @elseif ($packages->isEmpty())
                    {{-- §6.2 — an ordinary, expected state: the seed migration
                         populates packages on two of the ten cemeteries because
                         "AC6 only requires that package/class-level availability
                         be EXPRESSIBLE, not that every seeded cemetery have it
                         populated." What is empty, why, what to do next. --}}
                    <div class="rounded-lg border border-[var(--mk-border-subtle)] p-4">
                        <p class="text-base font-medium text-neutral-800">
                            Rincian paket dan kelas belum tersedia untuk lokasi ini.
                        </p>
                        <p class="mt-1 text-base text-neutral-600">
                            Pengelola belum mendaftarkan rincian per paket atau kelas di platform kami. Ketersediaan
                            tetap dapat ditanyakan langsung melalui customer service.
                        </p>
                        <div class="mt-3">
                            <x-mk.button variant="secondary" :href="route('bantuan.index')">
                                Tanyakan ketersediaan
                            </x-mk.button>
                        </div>
                    </div>
                @else
                    <ul class="divide-y divide-[var(--mk-border-subtle)] rounded-lg border border-[var(--mk-border-subtle)]">
                        @foreach ($packages as $package)
                            @php
                                // Resolved against the CEMETERY's availability
                                // mode, not the package status alone — a package
                                // can only be presented as confirmed when the
                                // owning cemetery has an AC7-gated authoritative
                                // mode. See CemeteryAvailabilityIntent.
                                $packageIntent = CemeteryAvailabilityIntent::forPackage(
                                    $capabilities->availabilityMode,
                                    (string) $package->availability_status,
                                );
                                // Package/class-level price — the finer-grained
                                // counterpart to the cemetery-level range above.
                                // Same "Perlu konfirmasi" framing, same honest
                                // null-when-unset behaviour: see
                                // CemeteryPresenter::packagePriceRange()'s own
                                // doc block.
                                $packagePriceRange = CemeteryPresenter::packagePriceRange($package);
                                $packagePriceAttribution = CemeteryPresenter::packagePriceAttribution($package);
                            @endphp
                            <li class="flex flex-wrap items-start justify-between gap-3 p-4">
                                <div class="space-y-1">
                                    <p class="text-base font-medium text-neutral-900">
                                        {{ $package->name }}@if ($package->class_label) &middot; {{ $package->class_label }}@endif
                                    </p>
                                    @if ($package->description)
                                        <p class="text-sm text-neutral-600">{{ $package->description }}</p>
                                    @endif
                                    @if ($packagePriceRange !== null && $packagePriceAttribution !== null)
                                        <p class="text-base font-semibold text-neutral-900">{{ $packagePriceRange }}</p>
                                        <p class="text-xs text-[var(--mk-text-muted)]">
                                            Sumber: {{ $packagePriceAttribution['source'] }}@if ($packagePriceAttribution['effective']) &middot; per {{ $packagePriceAttribution['effective'] }}@endif
                                        </p>
                                    @else
                                        <p class="text-xs text-[var(--mk-text-muted)]">Harga paket ini belum tersedia.</p>
                                    @endif
                                </div>
                                <x-mk.badge :intent="$packageIntent['intent']" :icon="$packageIntent['icon']">
                                    {{ $packageIntent['label'] }}
                                </x-mk.badge>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            {{-- §6.4 Authorization / privacy-limited. This is the honest
                 counterpart to AC12 and the negative criterion "No public
                 exposure of restricted plot data": rather than silently
                 rendering nothing where plot-level detail would go, the page
                 states that this level of detail is not publicly available
                 for this cemetery. design-system.md §6.2: "privacy-limited is
                 a distinct state from not found" — on this product that is
                 the difference between "we cannot show you this" and implying
                 the data does not exist.

                 Only `map_mode` is consulted, and only through the AC12
                 projection's named predicate — never a string comparison in
                 this file. `registry_mode`/`certificate_mode` are not in the
                 projection at all and must never be referenced here. --}}
            @unless ($capabilities->hasPublicPlotMap())
                <section aria-labelledby="peta-heading" class="space-y-2">
                    <h2 id="peta-heading" class="text-lg font-semibold text-neutral-900">Peta blok dan petak</h2>
                    <p class="text-base text-neutral-600">
                        Denah blok dan petak untuk lokasi ini tidak ditampilkan secara publik. Data petak hanya
                        dibuka melalui pengelola bersama registri resmi, sehingga informasi yang Anda lihat di sini
                        terbatas pada titik lokasi dan alamat.
                    </p>
                </section>
            @endunless

            {{-- §6.7 Pending — what is being waited on, who acts next.
                 booking_mode comes from the AC12 projection, read through its
                 named predicate rather than compared as a string here. --}}
            <section aria-labelledby="langkah-heading" class="space-y-2">
                <h2 id="langkah-heading" class="text-lg font-semibold text-neutral-900">Langkah berikutnya</h2>
                <p class="text-base text-neutral-600">
                    @if ($capabilities->isRequestConfirmationBooking())
                        Pemesanan di lokasi ini berjalan melalui permintaan konfirmasi: Anda mengirim permintaan,
                        lalu pengelola mengonfirmasi ketersediaan sebelum ada biaya yang perlu dibayar. Tidak ada
                        petak yang terkunci sebelum konfirmasi tersebut.
                    @else
                        Hubungi customer service untuk mengetahui langkah pemesanan yang berlaku di lokasi ini.
                    @endif
                </p>
            </section>

            {{-- §6.10 Customer-service escape hatch. --}}
            <section class="flex flex-col items-start gap-2 border-t border-[var(--mk-border-subtle)] pt-6">
                <p class="text-base text-neutral-600">
                    Ada pertanyaan tentang lokasi ini, atau perlu memastikan ketersediaan hari ini?
                </p>
                <x-mk.button variant="secondary" :href="route('bantuan.index')">
                    Hubungi Customer Service
                </x-mk.button>
            </section>
        </article>
    </div>
</div>

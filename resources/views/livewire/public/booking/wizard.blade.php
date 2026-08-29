{{--
    resources/views/livewire/public/booking/wizard.blade.php

    --- Why the Step 6/7/8 form controls are hand-written, not <x-mk.field> ---
    Exactly the reason resources/views/livewire/public/renewal/grave-search.blade.php
    gives for its own inputs, and this file follows that precedent rather
    than inventing a third pattern: <x-mk.field> merges $attributes onto its
    OUTER wrapping <div> only; its inner <input>/<select>/<textarea> has a
    closed attribute list and never spreads $attributes, so a `wire:model`
    handed to it lands on a <div> that never fires an input/change event and
    the binding silently does nothing.

    Every class string in $mkControl / $mkControlTextarea / $mkControlIdle /
    $mkControlError / $mkCheckbox below is copied verbatim from
    resources/views/components/mk/field.blade.php's own
    $controlBase / $stateClasses / checkbox composition. No new design value
    is introduced here, and in particular the idle border is
    `border-neutral-450` — NOT `neutral-300`, which measures 1.71:1 and fails
    WCAG 1.4.11 for a control boundary (design-system.md §7.1 finding #2).
    They live in one block instead of being retyped per field so the whole
    step 6-8 form cannot drift field by field; they are still literal strings
    in this file, which is what Tailwind's scanner needs.
--}}
@php
    use App\Livewire\Public\Directory\Support\CemeteryAvailabilityIntent;
    use App\Livewire\Public\Directory\Support\CemeteryPresenter;
    use App\Domain\ServiceCatalog\FulfillmentOwner;
    use App\Platform\FinancialLedger\Money;
@endphp
<div class="py-8 md:py-12">
    @php
        $mkControl = 'h-11 w-full rounded-md border bg-neutral-0 px-4 text-base text-neutral-900
            placeholder:text-neutral-500
            transition-[border-color,box-shadow] duration-fast ease-standard
            focus:outline-none focus:ring-2 focus:ring-offset-1';

        $mkControlTextarea = 'min-h-24 w-full rounded-md border bg-neutral-0 px-4 py-3 text-base text-neutral-900
            placeholder:text-neutral-500
            transition-[border-color,box-shadow] duration-fast ease-standard
            focus:outline-none focus:ring-2 focus:ring-offset-1';

        $mkControlIdle = 'border-neutral-450 hover:border-neutral-600 focus:border-primary-600 focus:ring-primary-600';

        $mkControlError = 'border-danger-600 focus:border-danger-600 focus:ring-danger-600';

        // `accent-primary-600` is the real fix, not `text-primary-600` alone
        // — this repo has no `@tailwindcss/forms` plugin and no
        // `appearance-none` reset anywhere (checked directly against
        // resources/css/*.css before writing this), so `text-primary-600`
        // by itself never recolours a native checkbox's checked-state fill
        // in any evergreen browser; only the CSS `accent-color` property
        // does. `accent-*` is a core Tailwind utility generated from the
        // same `--color-*` theme scale as `text-*`, so this stays
        // token-only. Mirrors field.blade.php's own checkbox recipe (that
        // file's doc comment records the same finding) — this variable's
        // own doc comment below still promises it is copied verbatim from
        // there, so both are kept in sync.
        $mkCheckbox = 'size-5 shrink-0 rounded-xs border bg-neutral-0 text-primary-600 accent-primary-600
            transition-[border-color,box-shadow] duration-fast ease-standard
            focus:outline-none focus:ring-2 focus:ring-offset-1 focus:ring-primary-600
            disabled:cursor-not-allowed disabled:bg-neutral-100 disabled:border-neutral-300';

        $mkFieldState = static fn (bool $invalid): string => $invalid ? $mkControlError : $mkControlIdle;
    @endphp

    <div class="mx-auto max-w-content px-4">

        {{-- NO `:labels` — see stepper.blade.php's own file header: passing
             `labels` from a booking screen is forbidden by AGENTS.md and
             design-system.md §9.2 MUST-NOT 9. The primitive's default IS the
             nine canonical booking labels. --}}
        <x-mk.stepper :step="$currentStep" class="mb-8" />

        {{-- Autosave indicator — design-system.md §3.9 ("a quiet inline
             indicator NEAR THE STEPPER, not a toast") and §7.4 ("Autosave:
             aria-live='polite' region; never steals focus").

             THE WRAPPER IS ALWAYS PRESENT and always the live region; the
             conditional alert goes INSIDE it. A region that only appears
             together with its message is often not announced at all, because
             the assistive technology never observed it becoming non-empty.
             BookingWizardAccessibilityTest guards both halves of this: the
             region exists, and it is never a toast role.

             The alerts inside carry `live="off"` on purpose. <x-mk.alert>
             would otherwise emit its own role="status" aria-live="polite"
             (or role="alert" for `assertive`) INSIDE this region, and a live
             region nested in a live region is announced twice — the exact
             regression this replaces. One region, one announcement. It also
             replaces the separate bottom-of-page banner AND the duplicate
             "Draft tersimpan" alert that step 9 rendered for the same state. --}}
        <div aria-live="polite" class="mb-6">
            @if ($autosaveState === 'saved')
                <x-mk.alert intent="success" title="Tersimpan" icon="check-circle" live="off">
                    Data pemesanan Anda telah disimpan. Anda dapat kembali ke langkah sebelumnya tanpa kehilangan data.
                </x-mk.alert>
            @elseif ($autosaveState === 'failed')
                <x-mk.alert intent="danger" title="Gagal menyimpan" icon="exclamation-triangle" live="off">
                    Data Anda belum tersimpan. Silakan periksa isian di bawah, lalu coba lagi.
                </x-mk.alert>
            @endif
        </div>

        <div class="mx-auto mb-8 max-w-prose space-y-2 text-center">
            <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                Pemesanan Makam
            </h1>
            <p class="text-base text-neutral-600">
                Pilih lokasi, TPU/TPS, dan jenis layanan untuk memulai pemesanan makam.
            </p>
        </div>

        @if ($currentStep === \App\Domain\Booking\BookingWizardStep::LOCATION)
            <section aria-labelledby="booking-step-1-heading">
                <h2 id="booking-step-1-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                    Langkah 1 &mdash; Pilih Lokasi
                </h2>

                @if ($cities === [])
                    <div class="flex flex-col items-center gap-3 py-12 text-center">
                        <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />
                        <h3 class="text-lg font-semibold text-neutral-800">
                            Belum ada kota yang tersedia.
                        </h3>
                        <p class="max-w-prose text-base text-neutral-600">
                            Saat ini belum ada kota yang melayani pemesanan. Silakan hubungi
                            <a href="/bantuan" class="underline">Bantuan</a> untuk informasi lebih lanjut.
                        </p>
                    </div>
                @else
                    <ul class="flex flex-wrap gap-3" aria-label="Kota peluncuran">
                        @foreach ($cities as $cityOption)
                            <li>
                                <x-mk.button
                                    variant="secondary"
                                    wire:click="saveStep1('{{ $cityOption['code'] }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="saveStep1"
                                >
                                    {{ $cityOption['label'] }}
                                </x-mk.button>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @error('city_code')
                    <p class="mt-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                @enderror
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::CEMETERY)
            <section aria-labelledby="booking-step-2-heading">
                <h2 id="booking-step-2-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                    Langkah 2 &mdash; Pilih TPU/TPS
                </h2>

                @if ($cemeteryListUnavailable)
                    <x-mk.alert intent="pending" title="Daftar TPU/TPS sedang tidak dapat dimuat" live="polite">
                        Kami tidak dapat memuat daftar TPU/TPS untuk kota ini saat ini. Silakan coba beberapa saat
                        lagi, atau
                        <a href="/bantuan" class="font-medium underline underline-offset-2">hubungi Bantuan</a>
                        agar petugas kami membantu langsung.
                    </x-mk.alert>
                @elseif ($cemeteries->isEmpty())
                    <div class="flex flex-col items-center gap-3 py-12 text-center">
                        <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />
                        <h3 class="text-lg font-semibold text-neutral-800">
                            Belum ada TPU/TPS terdaftar di kota ini.
                        </h3>
                        <p class="max-w-prose text-base text-neutral-600">
                            Data TPU/TPS untuk kota ini belum lengkap di sistem kami. Silakan pilih kota lain, atau
                            hubungi Bantuan agar petugas kami membantu pencarian Anda.
                        </p>
                        <x-mk.button variant="secondary" href="/bantuan" class="mt-2">
                            Hubungi Bantuan
                        </x-mk.button>
                    </div>
                @else
                    {{-- design-system.md §3.3's normative Cemetery card spec
                         (PUB-011): type badge, name, primary photo, address,
                         facilities, price range WITH source, and availability
                         status. This is the SAME card content
                         `resources/views/livewire/public/directory/index.blade.php`
                         renders one click away, resolved via the same
                         presenter classes (`CemeteryPresenter`,
                         `CemeteryAvailabilityIntent`) — Step 2 used to show
                         only the type badge and name. No shared
                         `<x-mk.card as="a" interactive>` here, though:
                         card.blade.php's own doc block forbids nesting a
                         second `<a>`/`<button>` inside an interactive card,
                         and this step needs a `wire:click` ACTION (not a
                         navigation link), sometimes several — one per
                         package/class. So the card renders as a plain,
                         non-interactive `<x-mk.card>` (content only, no
                         `interactive`/`as="a"`), with the real selection
                         control(s) as an explicit element inside it, same
                         two-level choice as before: a single "Pilih ..."
                         button for a cemetery with no packages, or one
                         button per package/class. --}}
                    <ul class="grid gap-4 md:grid-cols-2" aria-label="Daftar TPU/TPS">
                        @foreach ($cemeteries as $cemetery)
                            @php
                                $packages = $packagesByCemetery[$cemetery->id] ?? collect();
                                $capabilities = $cemeteryCapabilities[$cemetery->id] ?? null;
                                $availabilityMode = $capabilities?->availabilityMode
                                    ?? \App\Domain\CemeteryCapability\AvailabilityMode::INDICATIVE;
                                $availability = CemeteryAvailabilityIntent::forCemetery($availabilityMode);
                                $priceRange = CemeteryPresenter::priceRange($cemetery);
                                $priceAttribution = CemeteryPresenter::priceAttribution($cemetery);
                                $photoUrl = CemeteryPresenter::photoUrl($cemetery);
                                $facilities = CemeteryPresenter::facilities($cemetery);
                            @endphp
                            <li>
                                <x-mk.card class="h-full">
                                    <x-slot:media>
                                        @if ($photoUrl)
                                            <img
                                                src="{{ $photoUrl }}"
                                                alt="Ilustrasi {{ $cemetery->name }}"
                                                loading="lazy"
                                                class="h-40 w-full object-cover"
                                            >
                                        @else
                                            <div class="flex h-40 w-full items-center justify-center bg-neutral-100">
                                                <span class="text-sm text-neutral-600">Foto belum tersedia</span>
                                            </div>
                                        @endif
                                    </x-slot:media>

                                    <div class="space-y-3">
                                        <div class="flex flex-wrap items-center gap-2">
                                            <x-mk.badge intent="neutral">{{ $cemetery->type }}</x-mk.badge>
                                            <x-mk.badge :intent="$availability['intent']" :icon="$availability['icon']">
                                                {{ $availability['label'] }}
                                            </x-mk.badge>
                                        </div>

                                        <h3 class="text-lg font-semibold text-neutral-900">{{ $cemetery->name }}</h3>

                                        <p class="text-base text-neutral-600">{{ $cemetery->address }}</p>

                                        @if ($facilities !== [])
                                            <ul class="flex flex-wrap gap-1.5" aria-label="Fasilitas">
                                                @foreach ($facilities as $facility)
                                                    <li><x-mk.badge intent="neutral" size="sm">{{ $facility }}</x-mk.badge></li>
                                                @endforeach
                                            </ul>
                                        @endif

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

                                    @if ($packages->isEmpty())
                                        @if ($this->pickerAppliesTo($cemetery->id))
                                            <x-mk.button
                                                variant="primary"
                                                full
                                                wire:click="openPickerFor('{{ $cemetery->id }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="openPickerFor"
                                            >
                                                Pilih {{ $cemetery->name }} &mdash; Lihat Peta Plot
                                            </x-mk.button>
                                        @else
                                            <x-mk.button
                                                variant="primary"
                                                full
                                                wire:click="saveStep2('{{ $cemetery->id }}')"
                                                wire:loading.attr="disabled"
                                                wire:target="saveStep2"
                                            >
                                                Pilih {{ $cemetery->name }}
                                            </x-mk.button>
                                        @endif
                                    @else
                                        <div>
                                            <p id="cemetery-{{ $cemetery->id }}-packages-label" class="text-sm text-neutral-600">
                                                Pilih paket/kelas untuk TPU/TPS ini:
                                            </p>

                                            <ul class="mt-2 flex flex-wrap gap-2" aria-labelledby="cemetery-{{ $cemetery->id }}-packages-label">
                                                @foreach ($packages as $package)
                                                    <li>
                                                        @if ($this->pickerAppliesTo($cemetery->id))
                                                            <x-mk.button
                                                                variant="secondary"
                                                                wire:click="openPickerFor('{{ $cemetery->id }}', {{ $package->id }})"
                                                                wire:loading.attr="disabled"
                                                                wire:target="openPickerFor"
                                                            >
                                                                {{ $package->name }}@if ($package->class_label) &mdash; {{ $package->class_label }}@endif
                                                                &mdash; Lihat Peta Plot
                                                            </x-mk.button>
                                                        @else
                                                            <x-mk.button
                                                                variant="secondary"
                                                                wire:click="saveStep2('{{ $cemetery->id }}', {{ $package->id }})"
                                                                wire:loading.attr="disabled"
                                                                wire:target="saveStep2"
                                                            >
                                                                {{ $package->name }}@if ($package->class_label) &mdash; {{ $package->class_label }}@endif
                                                            </x-mk.button>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                    @endif
                                </x-mk.card>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if ($this->pickerCemeteryId !== null)
                    <section aria-labelledby="plot-picker-heading" class="mt-6 border-t border-neutral-200 pt-6">
                        <h3 id="plot-picker-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                            Pilih plot
                        </h3>

                        @php $hold = $this->activeDraftPlotHold(); @endphp

                        @if ($hold !== null)
                            <x-mk.alert intent="pending" title="Plot ditahan sementara" live="polite" wire:poll.5s>
                                Plot Anda ditahan agar tidak diambil pengunjung lain
                                @if ($hold->expires_at !== null)
                                    hingga pukul {{ $hold->expires_at->format('H:i') }}.
                                @else
                                    untuk sementara waktu.
                                @endif
                                Selesaikan langkah berikutnya sebelum waktu habis.
                                <x-mk.badge intent="{{ \App\Support\Design\StatusIntent::intent($hold->state, \App\Support\Design\StatusIntent::FAMILY_PLOT_RESERVATION) }}"
                                            :icon="\App\Support\Design\StatusIntent::icon($hold->state, \App\Support\Design\StatusIntent::FAMILY_PLOT_RESERVATION)">
                                    {{ \App\Support\Design\StatusIntent::label($hold->state, \App\Support\Design\StatusIntent::FAMILY_PLOT_RESERVATION) }}
                                </x-mk.badge>
                            </x-mk.alert>
                        @endif

                        @error('plot')
                            <p class="mb-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                        @enderror

                        <div class="grid gap-y-6">
                            @forelse ($this->pickerBlocks() as $block)
                                <div>
                                    <p class="mb-2 text-sm font-medium text-neutral-900">{{ $block->code }} &mdash; {{ $block->name }}</p>
                                    <ul class="flex flex-wrap gap-2" aria-label="Plot di {{ $block->code }}">
                                        @foreach ($block->plots as $plot)
                                            <li wire:key="plot-{{ $plot->id }}">
                                                <x-mk.button
                                                    variant="secondary"
                                                    :disabled="$plot->plot_state !== \App\Domain\PlotInventory\PlotState::AVAILABLE"
                                                    wire:click="holdPlotForStep2('{{ $this->pickerCemeteryId }}', {{ $this->pickerCemeteryPackageId ?? 'null' }}, '{{ $plot->id }}')"
                                                    wire:loading.attr="disabled"
                                                    wire:target="holdPlotForStep2"
                                                >
                                                    {{ $plot->slot }}
                                                    <x-mk.badge
                                                        intent="{{ \App\Support\Design\StatusIntent::intent($plot->plot_state, \App\Support\Design\StatusIntent::FAMILY_PLOT_STATE) }}"
                                                        :icon="\App\Support\Design\StatusIntent::icon($plot->plot_state, \App\Support\Design\StatusIntent::FAMILY_PLOT_STATE)"
                                                        size="sm"
                                                    >
                                                        {{ \App\Support\Design\StatusIntent::label($plot->plot_state, \App\Support\Design\StatusIntent::FAMILY_PLOT_STATE) }}
                                                    </x-mk.badge>
                                                </x-mk.button>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @empty
                                <div class="flex flex-col items-center gap-3 py-12 text-center">
                                    <x-dynamic-component component="icon.inbox" class="size-12 text-neutral-400" aria-hidden="true" />
                                    <h4 class="text-lg font-semibold text-neutral-800">
                                        Belum ada plot terdaftar untuk TPU/TPS ini.
                                    </h4>
                                    <p class="max-w-prose text-base text-neutral-600">
                                        Data plot untuk TPU/TPS ini belum disiapkan di sistem kami. Silakan
                                        hubungi Bantuan agar petugas kami membantu Anda, atau kembali dan pilih
                                        TPU/TPS lain.
                                    </p>
                                    <x-mk.button variant="secondary" href="/bantuan" class="mt-2">
                                        Hubungi Bantuan
                                    </x-mk.button>
                                </div>
                            @endforelse
                        </div>
                    </section>
                @endif

                @error('cemetery_id')
                    <p class="mt-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                @enderror
                @error('cemetery_package_id')
                    <p class="mt-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                @enderror

                <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::LOCATION }})" class="mt-4">
                    Kembali
                </x-mk.button>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::SERVICE_TYPE)
            <section aria-labelledby="booking-step-3-heading">
                <h2 id="booking-step-3-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                    Langkah 3 &mdash; Pilih Jenis Layanan
                </h2>

                <ul class="flex flex-wrap gap-3" aria-label="Jenis layanan">
                    @foreach (\App\Domain\Booking\BookingServiceType::KNOWN_CODES as $type)
                        <li>
                            <x-mk.button
                                variant="secondary"
                                wire:click="saveStep3('{{ $type }}')"
                                wire:loading.attr="disabled"
                                wire:target="saveStep3"
                            >
                                {{ \App\Domain\Booking\BookingServiceType::label($type) }}
                            </x-mk.button>
                        </li>
                    @endforeach
                </ul>

                @error('service_type')
                    <p class="mt-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                @enderror

                <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::CEMETERY }})" class="mt-4">
                    Kembali
                </x-mk.button>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::SERVICES)
            <section aria-labelledby="booking-step-4-heading">
                <h2 id="booking-step-4-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                    Langkah 4 &mdash; Pilih Layanan
                </h2>

                {{-- design-system.md §3.3's normative Service/add-on row
                     spec (PUB-013): name, description, fulfillment owner
                     (platform / operator / vendor), price, availability,
                     quantity/variant control. Rows used to show only the
                     checkbox and service name, even though the price and
                     fulfillment-owner data was already sitting on the same
                     `ServiceDefinition` rows Step 5's summary correctly
                     reads (`ServiceDefinition::currentPriceVersion()` —
                     see `BookingDraftQuery::summary()`, the exact method
                     Step 5 calls). This block reuses that same method
                     rather than a new query. Quantity is fixed at 1 for
                     every row — the checkbox IS the quantity/variant
                     control this batch ships; a real quantity/variant
                     picker is out of scope here (see
                     `BookingWizard::continueFromStep4()`'s own doc block,
                     which already flags that scope line and predates this
                     fix). --}}
                <fieldset class="flex flex-col gap-6">
                    <legend class="sr-only">Pilih layanan</legend>

                    <div>
                        <p class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-600">
                            Layanan dasar
                        </p>
                        {{-- The catalogue's own `name` is the visible label;
                             `code` stays the submitted value, so the payload
                             shape saveStep4() receives is unchanged. --}}
                        <ul class="flex flex-col gap-2">
                            @foreach ($basicServices as $service)
                                @php($priceVersion = $service->currentPriceVersion())
                                <li>
                                    <label
                                        for="service-{{ $service->code }}"
                                        class="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border border-neutral-200 bg-neutral-50 p-3 select-none"
                                    >
                                        <input
                                            type="checkbox"
                                            id="service-{{ $service->code }}"
                                            value="{{ $service->code }}"
                                            wire:model="stagedServiceCodes"
                                            checked
                                            disabled
                                            class="{{ $mkCheckbox }} mt-0.5 border-neutral-450"
                                        />
                                        <span class="flex flex-1 flex-col gap-1">
                                            <span class="flex flex-wrap items-center gap-2">
                                                <span class="text-base font-medium text-neutral-900">{{ $service->name }}</span>
                                                <x-mk.badge intent="neutral">Wajib</x-mk.badge>
                                            </span>
                                            @if ($service->description)
                                                <span class="text-sm text-neutral-600">{{ $service->description }}</span>
                                            @endif
                                            <span class="text-sm text-[var(--mk-text-muted)]">
                                                Dipenuhi oleh: {{ $service->fulfillment_owner !== null ? FulfillmentOwner::label($service->fulfillment_owner) : 'Belum ditentukan' }}
                                            </span>
                                        </span>
                                        <span class="shrink-0 text-right">
                                            @if ($priceVersion !== null)
                                                <span class="block text-base font-medium text-neutral-900">
                                                    {{ (new Money(Money::fromDecimal((string) $priceVersion->amount)))->format() }}
                                                </span>
                                            @else
                                                <x-mk.badge intent="pending" size="sm">Harga belum tersedia</x-mk.badge>
                                            @endif
                                        </span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </div>

                    <div>
                        <p class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-600">
                            Layanan tambahan
                        </p>
                        <ul class="flex flex-col gap-2">
                            @foreach ($additionalServices as $service)
                                @php($priceVersion = $service->currentPriceVersion())
                                <li>
                                    <label
                                        for="service-{{ $service->code }}"
                                        class="flex min-h-11 cursor-pointer items-start gap-3 rounded-lg border border-neutral-200 bg-neutral-0 p-3 select-none"
                                    >
                                        <input
                                            type="checkbox"
                                            id="service-{{ $service->code }}"
                                            value="{{ $service->code }}"
                                            wire:model="stagedServiceCodes"
                                            class="{{ $mkCheckbox }} mt-0.5 border-neutral-450"
                                        />
                                        <span class="flex flex-1 flex-col gap-1">
                                            <span class="text-base font-medium text-neutral-900">{{ $service->name }}</span>
                                            @if ($service->description)
                                                <span class="text-sm text-neutral-600">{{ $service->description }}</span>
                                            @endif
                                            <span class="text-sm text-[var(--mk-text-muted)]">
                                                Dipenuhi oleh: {{ $service->fulfillment_owner !== null ? FulfillmentOwner::label($service->fulfillment_owner) : 'Belum ditentukan' }}
                                            </span>
                                        </span>
                                        <span class="shrink-0 text-right">
                                            @if ($priceVersion !== null)
                                                <span class="block text-base font-medium text-neutral-900">
                                                    {{ (new Money(Money::fromDecimal((string) $priceVersion->amount)))->format() }}
                                                </span>
                                            @else
                                                <x-mk.badge intent="pending" size="sm">Harga belum tersedia</x-mk.badge>
                                            @endif
                                        </span>
                                    </label>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                </fieldset>

                @error('selected_services')
                    <p class="mt-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                @enderror

                <div class="mt-4 flex gap-3">
                    <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::SERVICE_TYPE }})">
                        Kembali
                    </x-mk.button>
                    <x-mk.button
                        variant="primary"
                        wire:click="continueFromStep4"
                        wire:loading.attr="disabled"
                        wire:target="continueFromStep4"
                    >
                        Lanjutkan
                    </x-mk.button>
                </div>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::SUMMARY)
            <section aria-labelledby="booking-step-5-heading">
                <h2 id="booking-step-5-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                    Langkah 5 &mdash; Ringkasan Pesanan
                </h2>

                @if ($summary !== null)
                    <x-mk.table
                        caption="Ringkasan layanan yang dipilih"
                        :headers="[
                            ['key' => 'label', 'label' => 'Layanan'],
                            ['key' => 'quantity', 'label' => 'Jumlah', 'numeric' => true],
                            ['key' => 'price', 'label' => 'Harga', 'numeric' => true],
                        ]"
                        :rows="collect($summary['lines'])->map(fn ($line) => [
                            'label' => $line['label'],
                            'quantity' => $line['quantity'],
                            'price' => $line['line_total'] !== null
                                ? (new \App\Platform\FinancialLedger\Money($line['line_total']))->format()
                                : 'Harga belum tersedia',
                        ])->all()"
                    />

                    <p class="mt-4 text-base font-semibold text-neutral-900">
                        @if ($summary['total'] !== null)
                            Total: {{ (new \App\Platform\FinancialLedger\Money($summary['total']))->format() }}
                        @else
                            Total belum dapat dihitung &mdash; sebagian harga layanan belum tersedia.
                        @endif
                    </p>
                @endif

                <div class="mt-4 flex items-center gap-3">
                    <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::SERVICES }})">
                        Kembali
                    </x-mk.button>
                    <x-mk.button
                        variant="primary"
                        wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::CUSTOMER_DATA }})"
                    >
                        Lanjut ke Data Pemesan
                    </x-mk.button>
                </div>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::CUSTOMER_DATA)
            <section aria-labelledby="booking-step-6-heading">
                <h2 id="booking-step-6-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                    Langkah 6 &mdash; Data Pemesan
                </h2>

                <p class="mb-5 max-w-prose text-base text-neutral-600">
                    Data ini kami gunakan untuk menghubungi Anda dan mengurus pemesanan.
                    Isian bertanda <span class="text-danger-600" aria-hidden="true">*</span> wajib diisi.
                </p>

                <form wire:submit="saveStep6" class="flex max-w-form flex-col gap-5">
                    <div class="flex flex-col gap-1.5">
                        <label for="customer-full-name" class="text-base font-medium text-neutral-800">
                            Nama Lengkap
                            <span class="text-danger-600" aria-hidden="true">*</span>
                            <span class="sr-only">(wajib diisi)</span>
                        </label>
                        <input
                            type="text"
                            id="customer-full-name"
                            wire:model="customerFullName"
                            autocomplete="name"
                            @if ($errors->has('customer_full_name')) aria-invalid="true" aria-describedby="customer-full-name-error" @endif
                            class="{{ $mkControl }} {{ $mkFieldState($errors->has('customer_full_name')) }}"
                        >
                        @error('customer_full_name')
                            <p id="customer-full-name-error" class="flex items-start gap-1.5 text-sm text-danger-700" role="alert">
                                <x-dynamic-component component="icon.alert-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                <span>{{ $message }}</span>
                            </p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="customer-mobile" class="text-base font-medium text-neutral-800">
                            Nomor HP
                            <span class="text-danger-600" aria-hidden="true">*</span>
                            <span class="sr-only">(wajib diisi)</span>
                        </label>
                        <p id="customer-mobile-hint" class="text-sm text-neutral-600">
                            Nomor yang aktif dan dapat kami hubungi, contoh 0812xxxxxxx atau +62812xxxxxxx.
                        </p>
                        <input
                            type="tel"
                            id="customer-mobile"
                            wire:model="customerMobile"
                            autocomplete="tel"
                            inputmode="tel"
                            aria-describedby="customer-mobile-hint{{ $errors->has('customer_mobile') ? ' customer-mobile-error' : '' }}"
                            @if ($errors->has('customer_mobile')) aria-invalid="true" @endif
                            class="{{ $mkControl }} {{ $mkFieldState($errors->has('customer_mobile')) }}"
                        >
                        @error('customer_mobile')
                            <p id="customer-mobile-error" class="flex items-start gap-1.5 text-sm text-danger-700" role="alert">
                                <x-dynamic-component component="icon.alert-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                <span>{{ $message }}</span>
                            </p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="customer-email" class="text-base font-medium text-neutral-800">
                            Email
                            <span class="text-danger-600" aria-hidden="true">*</span>
                            <span class="sr-only">(wajib diisi)</span>
                        </label>
                        <input
                            type="email"
                            id="customer-email"
                            wire:model="customerEmail"
                            autocomplete="email"
                            inputmode="email"
                            @if ($errors->has('customer_email')) aria-invalid="true" aria-describedby="customer-email-error" @endif
                            class="{{ $mkControl }} {{ $mkFieldState($errors->has('customer_email')) }}"
                        >
                        @error('customer_email')
                            <p id="customer-email-error" class="flex items-start gap-1.5 text-sm text-danger-700" role="alert">
                                <x-dynamic-component component="icon.alert-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                <span>{{ $message }}</span>
                            </p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="customer-address" class="text-base font-medium text-neutral-800">
                            Alamat Lengkap
                            <span class="text-danger-600" aria-hidden="true">*</span>
                            <span class="sr-only">(wajib diisi)</span>
                        </label>
                        <textarea
                            id="customer-address"
                            wire:model="customerAddress"
                            rows="3"
                            autocomplete="street-address"
                            @if ($errors->has('customer_address')) aria-invalid="true" aria-describedby="customer-address-error" @endif
                            class="{{ $mkControlTextarea }} {{ $mkFieldState($errors->has('customer_address')) }}"
                        ></textarea>
                        @error('customer_address')
                            <p id="customer-address-error" class="flex items-start gap-1.5 text-sm text-danger-700" role="alert">
                                <x-dynamic-component component="icon.alert-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                <span>{{ $message }}</span>
                            </p>
                        @enderror
                    </div>

                    {{-- The stored codes are SCREAMING_SNAKE (`ORANG_TUA`);
                         `value=` keeps them, the visible text never shows
                         them. Same rule for every other closed list below. --}}
                    <div class="flex flex-col gap-1.5">
                        <label for="customer-relationship" class="text-base font-medium text-neutral-800">
                            Hubungan dengan Almarhum
                            <span class="text-danger-600" aria-hidden="true">*</span>
                            <span class="sr-only">(wajib diisi)</span>
                        </label>
                        <select
                            id="customer-relationship"
                            wire:model="customerRelationship"
                            @if ($errors->has('customer_relationship')) aria-invalid="true" aria-describedby="customer-relationship-error" @endif
                            class="{{ $mkControl }} {{ $mkFieldState($errors->has('customer_relationship')) }}"
                        >
                            <option value="">Pilih hubungan</option>
                            @foreach (\App\Domain\Booking\BookingRelationshipCode::KNOWN_CODES as $rel)
                                <option value="{{ $rel }}">{{ \App\Domain\Booking\BookingRelationshipCode::label($rel) }}</option>
                            @endforeach
                        </select>
                        @error('customer_relationship')
                            <p id="customer-relationship-error" class="flex items-start gap-1.5 text-sm text-danger-700" role="alert">
                                <x-dynamic-component component="icon.alert-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                <span>{{ $message }}</span>
                            </p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="customer-contact-channel" class="text-base font-medium text-neutral-800">
                            Saluran Kontak yang Disukai
                            <span class="text-danger-600" aria-hidden="true">*</span>
                            <span class="sr-only">(wajib diisi)</span>
                        </label>
                        <p id="customer-contact-channel-hint" class="text-sm text-neutral-600">
                            Tim kami akan menghubungi Anda lebih dahulu melalui saluran ini.
                        </p>
                        <select
                            id="customer-contact-channel"
                            wire:model="customerContactChannel"
                            aria-describedby="customer-contact-channel-hint{{ $errors->has('customer_contact_channel') ? ' customer-contact-channel-error' : '' }}"
                            @if ($errors->has('customer_contact_channel')) aria-invalid="true" @endif
                            class="{{ $mkControl }} {{ $mkFieldState($errors->has('customer_contact_channel')) }}"
                        >
                            <option value="">Pilih saluran</option>
                            @foreach (\App\Domain\Booking\BookingContactChannel::KNOWN_CODES as $ch)
                                <option value="{{ $ch }}">{{ \App\Domain\Booking\BookingContactChannel::label($ch) }}</option>
                            @endforeach
                        </select>
                        @error('customer_contact_channel')
                            <p id="customer-contact-channel-error" class="flex items-start gap-1.5 text-sm text-danger-700" role="alert">
                                <x-dynamic-component component="icon.alert-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                <span>{{ $message }}</span>
                            </p>
                        @enderror
                    </div>

                    {{-- field.blade.php's checkbox rule: a 20px visual box
                         (`size-5`) inside a 44px clickable row, and the WHOLE
                         row is the label target, not just the box. --}}
                    <div class="flex flex-col gap-1.5">
                        <label for="privacy-notice-accepted" class="flex min-h-11 cursor-pointer items-start gap-3 py-2 select-none">
                            <input
                                type="checkbox"
                                id="privacy-notice-accepted"
                                wire:model="privacyNoticeAccepted"
                                aria-describedby="privacy-notice-accepted-hint{{ $errors->has('privacy_notice_accepted') ? ' privacy-notice-accepted-error' : '' }}"
                                @if ($errors->has('privacy_notice_accepted')) aria-invalid="true" @endif
                                class="{{ $mkCheckbox }} mt-0.5 {{ $errors->has('privacy_notice_accepted') ? 'border-danger-600 accent-danger-600' : 'border-neutral-450' }}"
                            >
                            <span class="text-base text-neutral-800">
                                Saya menyetujui
                                <a href="{{ route('legal.privacy') }}" target="_blank" rel="noopener" class="font-medium text-primary-700 underline underline-offset-2">
                                    Pemberitahuan Privasi
                                </a>
                                dan pemrosesan data pribadi saya serta data almarhum untuk keperluan pemesanan ini.
                                <span class="text-danger-600" aria-hidden="true">*</span>
                                <span class="sr-only">(wajib diisi)</span>
                            </span>
                        </label>
                        <p id="privacy-notice-accepted-hint" class="pl-8 text-sm text-neutral-600">
                            Persetujuan ini dapat Anda tarik kembali dengan menghubungi Bantuan.
                        </p>
                        @error('privacy_notice_accepted')
                            <p id="privacy-notice-accepted-error" class="flex items-start gap-1.5 pl-8 text-sm text-danger-700" role="alert">
                                <x-dynamic-component component="icon.alert-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                <span>{{ $message }}</span>
                            </p>
                        @enderror
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <x-mk.button
                            variant="tertiary"
                            type="button"
                            wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::SUMMARY }})"
                            wire:loading.attr="disabled"
                            wire:target="saveStep6"
                        >
                            Kembali
                        </x-mk.button>
                        {{-- `wire:target` names the method this control really
                             calls. Without it the disable is either too broad
                             or, worse, silently never applies — and a live
                             submit button on a grief-adjacent form is a double
                             submission waiting to happen. --}}
                        <x-mk.button
                            variant="primary"
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="saveStep6"
                        >
                            Lanjutkan
                        </x-mk.button>
                        <span wire:loading wire:target="saveStep6" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                            <x-mk.spinner class="size-4" aria-hidden="true" />
                            Menyimpan data pemesan&hellip;
                        </span>
                    </div>
                </form>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::DECEASED_DATA)
            <section aria-labelledby="booking-step-7-heading">
                <h2 id="booking-step-7-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                    Langkah 7 &mdash; Data Almarhum
                </h2>

                <p class="mb-5 max-w-prose text-base text-neutral-600">
                    Isi sebisa Anda. Jika ada data yang belum Anda ketahui secara pasti, tim kami akan
                    membantu melengkapinya saat menghubungi Anda.
                    Isian bertanda <span class="text-danger-600" aria-hidden="true">*</span> wajib diisi.
                </p>

                <form wire:submit="saveStep7" class="flex max-w-form flex-col gap-5">
                    <div class="flex flex-col gap-1.5">
                        <label for="deceased-full-name" class="text-base font-medium text-neutral-800">
                            Nama Lengkap Almarhum
                            <span class="text-danger-600" aria-hidden="true">*</span>
                            <span class="sr-only">(wajib diisi)</span>
                        </label>
                        <input
                            type="text"
                            id="deceased-full-name"
                            wire:model="deceasedFullName"
                            @if ($errors->has('deceased_full_name')) aria-invalid="true" aria-describedby="deceased-full-name-error" @endif
                            class="{{ $mkControl }} {{ $mkFieldState($errors->has('deceased_full_name')) }}"
                        >
                        @error('deceased_full_name')
                            <p id="deceased-full-name-error" class="flex items-start gap-1.5 text-sm text-danger-700" role="alert">
                                <x-dynamic-component component="icon.alert-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                <span>{{ $message }}</span>
                            </p>
                        @enderror
                    </div>

                    {{-- One column below `sm`: two date inputs side by side at
                         320px leaves neither usable (§7.6, 320px first). --}}
                    <div class="grid gap-5 sm:grid-cols-2">
                        <div class="flex flex-col gap-1.5">
                            <label for="deceased-date-of-birth" class="text-base font-medium text-neutral-800">
                                Tanggal Lahir
                                <span class="text-danger-600" aria-hidden="true">*</span>
                                <span class="sr-only">(wajib diisi)</span>
                            </label>
                            <input
                                type="date"
                                id="deceased-date-of-birth"
                                wire:model="deceasedDateOfBirth"
                                @if ($errors->has('deceased_date_of_birth')) aria-invalid="true" aria-describedby="deceased-date-of-birth-error" @endif
                                class="{{ $mkControl }} {{ $mkFieldState($errors->has('deceased_date_of_birth')) }}"
                            >
                            @error('deceased_date_of_birth')
                                <p id="deceased-date-of-birth-error" class="flex items-start gap-1.5 text-sm text-danger-700" role="alert">
                                    <x-dynamic-component component="icon.alert-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                    <span>{{ $message }}</span>
                                </p>
                            @enderror
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <label for="deceased-date-of-death" class="text-base font-medium text-neutral-800">
                                Tanggal Meninggal
                                <span class="text-danger-600" aria-hidden="true">*</span>
                                <span class="sr-only">(wajib diisi)</span>
                            </label>
                            <input
                                type="date"
                                id="deceased-date-of-death"
                                wire:model="deceasedDateOfDeath"
                                @if ($errors->has('deceased_date_of_death')) aria-invalid="true" aria-describedby="deceased-date-of-death-error" @endif
                                class="{{ $mkControl }} {{ $mkFieldState($errors->has('deceased_date_of_death')) }}"
                            >
                            @error('deceased_date_of_death')
                                <p id="deceased-date-of-death-error" class="flex items-start gap-1.5 text-sm text-danger-700" role="alert">
                                    <x-dynamic-component component="icon.alert-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                    <span>{{ $message }}</span>
                                </p>
                            @enderror
                        </div>
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="deceased-relationship" class="text-base font-medium text-neutral-800">
                            Hubungan dengan Pemesan
                            <span class="text-danger-600" aria-hidden="true">*</span>
                            <span class="sr-only">(wajib diisi)</span>
                        </label>
                        <select
                            id="deceased-relationship"
                            wire:model="deceasedRelationship"
                            @if ($errors->has('deceased_relationship')) aria-invalid="true" aria-describedby="deceased-relationship-error" @endif
                            class="{{ $mkControl }} {{ $mkFieldState($errors->has('deceased_relationship')) }}"
                        >
                            <option value="">Pilih hubungan</option>
                            @foreach (\App\Domain\Booking\BookingRelationshipCode::KNOWN_CODES as $rel)
                                <option value="{{ $rel }}">{{ \App\Domain\Booking\BookingRelationshipCode::label($rel) }}</option>
                            @endforeach
                        </select>
                        @error('deceased_relationship')
                            <p id="deceased-relationship-error" class="flex items-start gap-1.5 text-sm text-danger-700" role="alert">
                                <x-dynamic-component component="icon.alert-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                <span>{{ $message }}</span>
                            </p>
                        @enderror
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <label for="deceased-gender" class="text-base font-medium text-neutral-800">
                            Jenis Kelamin
                            <span class="font-normal text-neutral-600">(opsional)</span>
                        </label>
                        <select
                            id="deceased-gender"
                            wire:model="deceasedGender"
                            @if ($errors->has('deceased_gender')) aria-invalid="true" aria-describedby="deceased-gender-error" @endif
                            class="{{ $mkControl }} {{ $mkFieldState($errors->has('deceased_gender')) }}"
                        >
                            <option value="">Tidak diisi</option>
                            @foreach (\App\Domain\Booking\BookingGender::KNOWN_CODES as $g)
                                <option value="{{ $g }}">{{ \App\Domain\Booking\BookingGender::label($g) }}</option>
                            @endforeach
                        </select>
                        @error('deceased_gender')
                            <p id="deceased-gender-error" class="flex items-start gap-1.5 text-sm text-danger-700" role="alert">
                                <x-dynamic-component component="icon.alert-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                <span>{{ $message }}</span>
                            </p>
                        @enderror
                    </div>

                    {{-- This block used to list a death certificate, a KTP and
                         a KK, and to say "Dokumen akan diunggah pada langkah
                         selanjutnya." There is no upload step anywhere in this
                         wizard, and `saveStep7()` deliberately sends no
                         document keys at all. Telling a bereaved family to go
                         and find paperwork for a screen that does not exist is
                         a real cost paid by someone in the worst week of their
                         life, so the list and the promised upload step are
                         both gone. What is left is only what is true today. --}}
                    <x-mk.alert intent="info" title="Anda belum perlu menyiapkan dokumen" live="off">
                        Pada tahap ini kami tidak meminta unggahan dokumen apa pun. Bila nanti ada dokumen yang
                        diperlukan, tim kami akan memberitahukannya langsung kepada Anda saat menghubungi Anda,
                        beserta cara mengirimkannya.
                    </x-mk.alert>

                    <div class="flex flex-wrap items-center gap-3">
                        <x-mk.button
                            variant="tertiary"
                            type="button"
                            wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::CUSTOMER_DATA }})"
                            wire:loading.attr="disabled"
                            wire:target="saveStep7"
                        >
                            Kembali
                        </x-mk.button>
                        <x-mk.button
                            variant="primary"
                            type="submit"
                            wire:loading.attr="disabled"
                            wire:target="saveStep7"
                        >
                            Lanjutkan
                        </x-mk.button>
                        <span wire:loading wire:target="saveStep7" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                            <x-mk.spinner class="size-4" aria-hidden="true" />
                            Menyimpan data almarhum&hellip;
                        </span>
                    </div>
                </form>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::PAYMENT)
            <section aria-labelledby="booking-step-8-heading">
                <h2 id="booking-step-8-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                    Langkah 8 &mdash; Pembayaran
                </h2>

                @if ($paymentMode !== \App\Platform\FeatureGate\Modes\PaymentMode::Online)
                    <x-mk.gate-closed-banner intent="info" :dismissible="false" title="Pembayaran online belum tersedia">
                        Pembayaran online belum tersedia untuk saat ini. Silakan gunakan metode manual di bawah ini.
                    </x-mk.gate-closed-banner>
                @endif

                {{-- Returning to step 8 after a save must not look like a
                     blank slate: the method is already stored on the draft.
                     `pending`, never `success` — choosing a method is not the
                     same as having paid, and no verification exists yet. --}}
                @if ($paymentMethod !== '')
                    <x-mk.alert
                        intent="pending"
                        icon="clock"
                        title="Metode pembayaran tersimpan: {{ \App\Domain\Booking\BookingPaymentMethod::label($paymentMethod) }}"
                        live="off"
                        class="mt-4"
                    >
                        Pembayaran Anda belum kami terima dan belum diverifikasi. Tim kami akan mengonfirmasi
                        langkah pembayaran saat menghubungi Anda. Anda masih dapat mengubah pilihan di bawah ini.
                    </x-mk.alert>
                @endif

                <div class="mt-4 flex flex-col gap-4">
                    @if ($paymentMode === \App\Platform\FeatureGate\Modes\PaymentMode::Online)
                        @if ($onlineSessionState === \App\Platform\Payment\SessionState::Paid)
                            <x-mk.card>
                                <x-mk.alert
                                    intent="success"
                                    icon="check-circle"
                                    title="Pembayaran Anda telah kami terima"
                                    live="polite"
                                >
                                    <p class="text-sm">
                                        Konfirmasi resmi dari penyedia pembayaran sudah kami terima.
                                        Lanjutkan ke Langkah 9 untuk melihat ringkasan pemesanan Anda.
                                    </p>
                                </x-mk.alert>
                            </x-mk.card>
                        @elseif ($onlineSessionState === \App\Platform\Payment\SessionState::Failed)
                            <x-mk.card>
                                <x-mk.alert
                                    intent="danger"
                                    icon="alert-circle"
                                    title="Transaksi pembayaran tidak berhasil"
                                    live="assertive"
                                >
                                    <p class="text-sm">
                                        Tidak ada pembayaran yang kami catat untuk transaksi ini.
                                        Anda dapat mencoba pembayaran online lagi dari awal, atau
                                        gunakan pembayaran manual di bawah ini.
                                    </p>
                                </x-mk.alert>
                            </x-mk.card>
                        @elseif ($onlineSessionState === \App\Platform\Payment\SessionState::Expired)
                            <x-mk.card>
                                <x-mk.alert
                                    intent="neutral"
                                    icon="clock"
                                    title="Sesi pembayaran telah kedaluwarsa"
                                    live="polite"
                                >
                                    <p class="text-sm">
                                        Sesi pembayaran Anda telah kedaluwarsa tanpa pembayaran yang kami
                                        terima. Gunakan pembayaran manual di bawah ini, atau hubungi tim kami.
                                    </p>
                                </x-mk.alert>
                            </x-mk.card>
                        @else
                            <x-mk.card>
                                <div class="flex flex-col gap-2">
                                    <h3 class="text-base font-semibold text-neutral-900">Pembayaran Online</h3>
                                    <p class="text-sm text-neutral-600">
                                        Anda akan diarahkan ke halaman pembayaran untuk menyelesaikan transaksi.
                                    </p>
                                </div>

                                {{-- ADR-0035 item 1's mitigation: unmissable
                                     labelling before any redirect to the
                                     sandbox — a real customer could otherwise
                                     complete a real booking, "pay" through a
                                     sandbox that moves no money, and have the
                                     order marked paid with no real settlement
                                     behind it. Deliberately placed on the
                                     button's own card, not only in a
                                     dismissible site-wide banner a grieving
                                     user is unlikely to read carefully. --}}
                                @if ($isSandboxPayment)
                                    <x-mk.alert
                                        intent="urgent"
                                        icon="exclamation-triangle"
                                        title="ANDA TIDAK AKAN MENGIRIM UANG SUNGGUHAN"
                                        live="off"
                                        class="mt-3"
                                    >
                                        <p class="text-sm">
                                            Makam.co.id masih dalam masa uji coba publik (beta). Halaman pembayaran
                                            di bawah ini adalah <strong>simulasi (sandbox)</strong> milik penyedia
                                            pembayaran &mdash; tidak ada transaksi finansial nyata yang terjadi,
                                            berapa pun nominal yang tertera. Pesanan Anda tetap tercatat, dan tim
                                            kami akan menghubungi Anda secara langsung untuk menyelesaikan
                                            pembayaran sesungguhnya secara manual.
                                        </p>
                                    </x-mk.alert>
                                @endif

                                @if ($onlinePaymentError !== null)
                                    <x-mk.alert
                                        intent="danger"
                                        title="Pembayaran online belum dapat diproses"
                                        live="assertive"
                                        class="mt-3"
                                    >
                                        <p class="text-sm">{{ $onlinePaymentError }}</p>
                                        <x-slot name="action">
                                            <x-mk.button
                                                variant="secondary"
                                                size="sm"
                                                href="{{ route('bantuan.index') }}"
                                            >
                                                Butuh bantuan?
                                            </x-mk.button>
                                        </x-slot>
                                    </x-mk.alert>
                                @endif

                                <div class="mt-3 flex flex-wrap items-center gap-3">
                                    <x-mk.button
                                        variant="primary"
                                        wire:click="openOnlinePayment"
                                        wire:loading.attr="disabled"
                                        wire:target="openOnlinePayment"
                                    >
                                        Bayar Sekarang
                                    </x-mk.button>
                                    <span wire:loading wire:target="openOnlinePayment" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                                        <x-mk.spinner class="size-4" aria-hidden="true" />
                                        Membuka halaman pembayaran&hellip;
                                    </span>
                                </div>
                            </x-mk.card>

                            @if ($onlineSessionState !== null && $onlinePaymentLinkUrl !== null)
                                {{-- AwaitingPayment/Created — a session was opened
                                     earlier in this journey and checkout has not
                                     completed. Re-point at the SAME hosted
                                     checkout rather than opening a second one. --}}
                                <x-mk.alert
                                    intent="pending"
                                    icon="clock"
                                    title="Menunggu pembayaran Anda"
                                    live="off"
                                >
                                    <p class="text-sm">
                                        Sesi pembayaran telah kami buka namun belum selesai. Lanjutkan
                                        dari halaman pembayaran yang sama untuk menyelesaikan transaksi.
                                    </p>
                                    <x-slot name="action">
                                        <x-mk.button
                                            variant="secondary"
                                            size="sm"
                                            href="{{ $onlinePaymentLinkUrl }}"
                                        >
                                            Lanjutkan pembayaran
                                        </x-mk.button>
                                    </x-slot>
                                </x-mk.alert>
                            @endif
                        @endif
                    @endif

                    {{-- assumptions-and-gates.md §2 / `PaymentMode`'s own doc
                         block: manual coordination is `G-PAY-01`'s FALLBACK,
                         never a second option offered alongside a live or
                         untried online path. `$showManualPayment` (computed
                         in `BookingWizard::render()`) is true when the gate
                         is closed, or once the online attempt has actually
                         failed (`onlinePaymentError`, or a Failed/Expired
                         session) — the honest recovery route, not a
                         permanent alternative. --}}
                    @if ($showManualPayment)
                        <x-mk.card>
                            <div class="flex flex-col gap-2">
                                <h3 class="text-base font-semibold text-neutral-900">Pembayaran Manual</h3>
                                <p class="text-sm text-neutral-600">
                                    Transfer sejumlah total pesanan ke rekening tujuan di bawah ini, lalu masukkan
                                    nomor referensi transfer Anda. Mohon siapkan bukti transfer untuk verifikasi.
                                </p>
                            </div>

                            {{-- Admin-configured via SiteSettingsResource
                                 (`App\Support\BankTransferInfo`) — never a
                                 fabricated placeholder. `$bankTransferConfigured`
                                 is true only once bank name, account number,
                                 AND account holder are all set; a partial
                                 configuration falls into the honest "belum
                                 dikonfigurasi" branch below rather than
                                 showing an incomplete destination. --}}
                            @if ($bankTransferConfigured)
                                <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 rounded-lg bg-neutral-50 p-4 text-sm text-neutral-700">
                                    <dt class="font-medium text-neutral-700">Bank</dt>
                                    <dd>{{ $bankTransferBankName }}</dd>
                                    <dt class="font-medium text-neutral-700">Nomor Rekening</dt>
                                    <dd>{{ $bankTransferAccountNumber }}</dd>
                                    <dt class="font-medium text-neutral-700">Atas Nama</dt>
                                    <dd>{{ $bankTransferAccountHolder }}</dd>
                                </dl>
                            @else
                                <x-mk.alert intent="pending" icon="clock" title="Rekening tujuan belum dikonfigurasi" live="off">
                                    Rekening tujuan transfer belum tersedia di sistem. Hubungi admin melalui
                                    kontak dukungan sebelum melakukan transfer, agar dana Anda terkirim ke
                                    rekening yang benar.
                                </x-mk.alert>
                            @endif

                            <div class="flex max-w-form flex-col gap-1.5">
                                <label for="payment-reference" class="text-base font-medium text-neutral-800">
                                    Referensi Pembayaran
                                    <span class="text-danger-600" aria-hidden="true">*</span>
                                    <span class="sr-only">(wajib diisi untuk pembayaran manual)</span>
                                </label>
                                <p id="payment-reference-hint" class="text-sm text-neutral-600">
                                    Nomor referensi transfer atau nama pengirim, agar tim kami dapat mencocokkan pembayaran Anda.
                                    Wajib diisi bila Anda memilih pembayaran manual.
                                </p>
                                <input
                                    type="text"
                                    id="payment-reference"
                                    wire:model="paymentReference"
                                    aria-describedby="payment-reference-hint{{ $errors->has('payment_reference') ? ' payment-reference-error' : '' }}"
                                    @if ($errors->has('payment_reference')) aria-invalid="true" @endif
                                    class="{{ $mkControl }} {{ $mkFieldState($errors->has('payment_reference')) }}"
                                >
                                @error('payment_reference')
                                    <p id="payment-reference-error" class="flex items-start gap-1.5 text-sm text-danger-700" role="alert">
                                        <x-dynamic-component component="icon.alert-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                                        <span>{{ $message }}</span>
                                    </p>
                                @enderror
                            </div>

                            <div class="flex flex-wrap items-center gap-3">
                                <x-mk.button
                                    variant="secondary"
                                    wire:click="saveStep8('{{ \App\Domain\Booking\BookingPaymentMethod::MANUAL }}')"
                                    wire:loading.attr="disabled"
                                    wire:target="saveStep8"
                                >
                                    Saya Akan Bayar Manual
                                </x-mk.button>
                            </div>
                        </x-mk.card>

                        {{-- One indicator for both buttons: they call the same
                             method, and `wire:target="saveStep8"` is what keeps
                             the disable attached to the request actually in
                             flight — the double-submission guard that matters
                             most on the payment step. --}}
                        <span wire:loading wire:target="saveStep8" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                            <x-mk.spinner class="size-4" aria-hidden="true" />
                            Menyimpan pilihan pembayaran&hellip;
                        </span>
                    @endif
                </div>

                @error('payment_method')
                    <p class="mt-3 flex items-start gap-1.5 text-sm text-danger-700" role="alert">
                        <x-dynamic-component component="icon.alert-circle" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                        <span>{{ $message }}</span>
                    </p>
                @enderror

                <x-mk.button
                    variant="tertiary"
                    wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::DECEASED_DATA }})"
                    wire:loading.attr="disabled"
                    wire:target="saveStep8"
                    class="mt-4"
                >
                    Kembali
                </x-mk.button>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::CONFIRMATION)
            <section aria-labelledby="booking-step-9-heading">
                <h2 id="booking-step-9-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                    Langkah 9 &mdash; Konfirmasi
                </h2>

                {{-- The "Draft tersimpan" alert that used to sit here is gone,
                     not lost: it said exactly what the always-present autosave
                     region below the stepper already says, so a screen-reader
                     user heard the same sentence twice. Saving a draft IS a
                     genuine success and that alert keeps its `success` intent
                     up there — but this SCREEN's overall status is pending,
                     and that is what the status block below states. --}}

                @if ($confirmationData !== null)
                    {{-- The webhook-driven online session state, when this
                         journey opened one — read from the session row, never
                         from any URL. Without it the confirmation card below
                         would say "pembayaran belum diverifikasi" even after
                         the webhook settled it. --}}
                    @if ($onlineSessionState === \App\Platform\Payment\SessionState::Paid)
                        <x-mk.alert
                            intent="success"
                            icon="check-circle"
                            title="Pembayaran Anda telah kami terima"
                            live="polite"
                            class="mb-4"
                        >
                            <p class="text-base">
                                Konfirmasi resmi dari penyedia pembayaran sudah kami terima untuk
                                pemesanan ini. Tim kami akan memproses pesanan Anda.
                            </p>
                        </x-mk.alert>
                    @elseif ($onlineSessionState === \App\Platform\Payment\SessionState::Failed)
                        <x-mk.alert
                            intent="danger"
                            icon="alert-circle"
                            title="Transaksi pembayaran tidak berhasil"
                            live="assertive"
                            class="mb-4"
                        >
                            <p class="text-base">
                                Tidak ada pembayaran yang kami catat untuk transaksi ini. Anda dapat
                                mencoba pembayaran online lagi dari Langkah 8, atau gunakan pembayaran
                                manual.
                            </p>
                        </x-mk.alert>
                    @elseif ($onlineSessionState === \App\Platform\Payment\SessionState::Expired)
                        <x-mk.alert
                            intent="neutral"
                            icon="clock"
                            title="Sesi pembayaran telah kedaluwarsa"
                            live="polite"
                            class="mb-4"
                        >
                            <p class="text-base">
                                Sesi pembayaran Anda telah kedaluwarsa tanpa pembayaran yang kami
                                terima. Gunakan pembayaran manual di Langkah 8, atau hubungi tim kami.
                            </p>
                        </x-mk.alert>
                    @elseif ($onlineSessionState !== null)
                        <x-mk.alert
                            intent="pending"
                            icon="clock"
                            title="Menunggu pembayaran online Anda"
                            live="off"
                            class="mb-4"
                        >
                            <p class="text-base">
                                Sesi pembayaran online belum selesai. Status pembayaran hanya
                                diperbarui setelah kami menerima konfirmasi resmi dari penyedia
                                pembayaran.
                            </p>
                        </x-mk.alert>
                    @endif

                    {{-- §6.7: "Pending is the most common state in this product
                         and the easiest to get wrong… Never style a pending
                         state as success." The order itself now exists as soon
                         as a manual submission is saved (`saveStep8()`), but
                         payment has not been verified — that stays a separate,
                         later, staff-driven step — so this card keeps `pending`
                         intent and a clock icon either way. Only the COPY
                         branches on whether `order_reference` is set: the rare
                         case where order creation itself failed mid-request
                         (reported, not surfaced as an error) falls back to the
                         original honest "not yet processed" wording rather than
                         claiming a reference that does not exist. --}}
                    <x-mk.card intent="pending">
                        <div class="flex flex-col gap-3">
                            <x-mk.badge intent="pending" icon="clock" class="self-start">
                                Menunggu diproses
                            </x-mk.badge>

                            @if ($confirmationData['order_reference'] !== null)
                                <h3 class="text-lg font-semibold text-neutral-900">
                                    Pesanan Anda diterima dengan nomor {{ $confirmationData['order_reference'] }}
                                </h3>

                                <p class="text-base text-neutral-800">
                                    Terima kasih. Tim kami akan menghubungi Anda melalui
                                    {{ $confirmationData['contact_channel_label'] }}
                                    untuk mengonfirmasi pesanan dan langkah pembayaran.
                                </p>

                                <p class="text-sm text-neutral-700">
                                    Pembayaran Anda belum diverifikasi. Tim kami akan memverifikasi setelah
                                    transfer Anda kami terima dan cocokkan dengan referensi pembayaran yang
                                    Anda kirimkan. Anda tidak perlu melakukan apa pun sampai kami menghubungi
                                    Anda.
                                </p>
                            @else
                                <h3 class="text-lg font-semibold text-neutral-900">
                                    Data pemesanan Anda telah tersimpan dan menunggu diproses
                                </h3>

                                <p class="text-base text-neutral-800">
                                    Terima kasih. Tim kami akan menghubungi Anda melalui
                                    {{ $confirmationData['contact_channel_label'] }}
                                    untuk mengonfirmasi pesanan dan langkah pembayaran.
                                </p>

                                <p class="text-sm text-neutral-700">
                                    Pesanan Anda belum dibuat secara resmi dan pembayaran belum diverifikasi.
                                    Nomor pesanan resmi akan kami berikan setelah tim kami memproses pemesanan ini.
                                    Anda tidak perlu melakukan apa pun sampai kami menghubungi Anda.
                                </p>
                            @endif
                        </div>
                    </x-mk.card>

                    <div class="mt-6 flex flex-col gap-4">
                        {{-- §6.8 / AGENTS.md: "Do not claim WhatsApp/email
                             delivery without delivery state." No delivery
                             record exists anywhere in this lane, so no channel
                             may be shown as "Terkirim"; each one is shown as
                             not yet sent. Whether WhatsApp is a channel we have
                             at all is G-WA-01's answer, read server-side and
                             handed here as `$whatsAppMode` — when it is the
                             fallback mode, WhatsApp is not promised. --}}
                        <x-mk.card>
                            <h3 class="text-base font-semibold text-neutral-900">Pemberitahuan</h3>

                            <ul class="flex flex-col gap-3">
                                <li class="flex flex-wrap items-center justify-between gap-2">
                                    <span class="text-sm text-neutral-700">Email ke alamat yang Anda isi</span>
                                    <x-mk.badge intent="pending" icon="clock">Belum dikirim</x-mk.badge>
                                </li>

                                @if ($whatsAppMode === \App\Platform\FeatureGate\Modes\WhatsAppMode::WhatsApp)
                                    <li class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="text-sm text-neutral-700">WhatsApp ke nomor yang Anda isi</span>
                                        <x-mk.badge intent="pending" icon="clock">Belum dikirim</x-mk.badge>
                                    </li>
                                @else
                                    <li class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="text-sm text-neutral-700">WhatsApp</span>
                                        <x-mk.badge intent="neutral" icon="slash">Belum tersedia</x-mk.badge>
                                    </li>
                                @endif
                            </ul>

                            <p class="text-sm text-neutral-600">
                                @if ($whatsAppMode === \App\Platform\FeatureGate\Modes\WhatsAppMode::WhatsApp)
                                    Belum ada pesan yang kami kirimkan. Pemberitahuan dikirim setelah tim kami
                                    memproses pemesanan ini; bila pesan tidak sampai, tim kami tetap menghubungi
                                    Anda melalui {{ $confirmationData['contact_channel_label'] }}.
                                @else
                                    Pemberitahuan melalui WhatsApp belum kami aktifkan, jadi kami tidak menjanjikannya.
                                    Belum ada pesan yang kami kirimkan. Konfirmasi akan kami sampaikan melalui email
                                    dan saat tim kami menghubungi Anda melalui
                                    {{ $confirmationData['contact_channel_label'] }}.
                                @endif
                            </p>
                        </x-mk.card>

                        @if ($confirmationData['summary'] !== null)
                            <x-mk.card>
                                <h3 class="text-base font-semibold text-neutral-900">Ringkasan Pesanan</h3>
                                <x-mk.table
                                    caption="Ringkasan layanan yang dipilih beserta jumlah dan biayanya"
                                    :headers="[
                                        ['key' => 'label', 'label' => 'Layanan'],
                                        ['key' => 'quantity', 'label' => 'Jumlah', 'numeric' => true],
                                        ['key' => 'price', 'label' => 'Harga', 'numeric' => true],
                                    ]"
                                    :rows="collect($confirmationData['summary']['lines'])->map(fn ($line) => [
                                        'label' => $line['label'],
                                        'quantity' => $line['quantity'],
                                        'price' => $line['line_total'] !== null
                                            ? (new \App\Platform\FinancialLedger\Money($line['line_total']))->format()
                                            : 'Harga belum tersedia',
                                    ])->all()"
                                />
                                {{-- <x-mk.table> has no total/footer row, so the
                                     total is a labelled pair beside it rather
                                     than an unlabelled number under a column. --}}
                                <dl class="flex flex-wrap items-baseline justify-between gap-2 border-t border-neutral-200 pt-3">
                                    <dt class="text-sm font-semibold text-neutral-900">Total</dt>
                                    <dd class="text-base font-semibold tabular-nums text-neutral-900">
                                        @if ($confirmationData['summary']['total'] !== null)
                                            {{ (new \App\Platform\FinancialLedger\Money($confirmationData['summary']['total']))->format() }}
                                        @else
                                            Belum dapat dihitung &mdash; sebagian harga layanan belum tersedia.
                                        @endif
                                    </dd>
                                </dl>
                            </x-mk.card>
                        @endif

                        <x-mk.card>
                            <h3 class="text-base font-semibold text-neutral-900">Data yang Anda kirimkan</h3>
                            <dl class="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm text-neutral-600">
                                <dt class="font-medium text-neutral-700">Pemesan</dt>
                                <dd>{{ $confirmationData['customer_name'] ?: '—' }}</dd>
                                <dt class="font-medium text-neutral-700">Almarhum</dt>
                                <dd>{{ $confirmationData['deceased_name'] ?: '—' }}</dd>
                                <dt class="font-medium text-neutral-700">Metode Pembayaran</dt>
                                <dd>{{ \App\Domain\Booking\BookingPaymentMethod::label($confirmationData['payment_method']) }}</dd>
                                @if ($confirmationData['payment_reference'])
                                    <dt class="font-medium text-neutral-700">Referensi Pembayaran</dt>
                                    <dd>{{ $confirmationData['payment_reference'] }}</dd>
                                @endif
                            </dl>
                        </x-mk.card>

                        {{-- The block that printed `draft_id` under the heading
                             "Nomor Referensi Sementara" is removed entirely.
                             That value is the draft's RESUME identifier, not an
                             order number, and presenting it as a reference
                             invited users to pass it around — the real order
                             reference (`confirmationData['order_reference']`),
                             when one exists, is shown by the status card above
                             instead; the draft id itself is printed nowhere. --}}
                        <x-mk.card>
                            <h3 class="text-base font-semibold text-neutral-900">Apa yang selanjutnya?</h3>
                            <ol class="flex list-inside list-decimal flex-col gap-1 text-sm text-neutral-600">
                                <li>Tim kami meninjau data pemesanan Anda.</li>
                                <li>
                                    Tim kami menghubungi Anda melalui
                                    {{ $confirmationData['contact_channel_label'] }}
                                    untuk mengonfirmasi detail dan langkah pembayaran.
                                </li>
                                @if ($confirmationData['order_reference'] === null)
                                    <li>Nomor pesanan resmi diberikan setelah pemesanan Anda diproses.</li>
                                @endif
                            </ol>
                            <p class="text-sm text-neutral-600">
                                Ada yang ingin ditanyakan atau diubah?
                                <a href="/bantuan" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>
                                dan sebutkan nama pemesan serta nama almarhum &mdash; tim kami akan menemukan data Anda.
                            </p>
                        </x-mk.card>
                    </div>
                @else
                    <x-mk.alert intent="pending" title="Sesi pemesanan tidak ditemukan" live="polite">
                        Data pemesanan tidak ditemukan. Silakan mulai pemesanan baru.
                    </x-mk.alert>
                    <x-mk.button variant="secondary" href="/pemesanan-makam" class="mt-4">
                        Mulai Pemesanan Baru
                    </x-mk.button>
                @endif
            </section>
        @endif

        {{-- Step-independent errors: an expired/unknown draft session, a
             draft changed in another tab, and the server-side "finish the
             previous step first" rejection. Rendered outside the per-step
             sections because every one of them can be raised from any step. --}}
        @error('draft')
            <p class="mt-4 text-sm text-danger-700" role="alert">{{ $message }}</p>
        @enderror
        @error('step')
            <p class="mt-4 text-sm text-danger-700" role="alert">{{ $message }}</p>
        @enderror

        {{-- Support escape hatch — design-system.md §6.10 requires a
             contextual support link in the footer of EVERY wizard step, not
             only on the steps that happen to have an empty state. Rendered
             outside the per-step sections so no step can ship without it. --}}
        <p class="mt-8 border-t border-neutral-200 pt-4 text-sm text-neutral-600">
            Butuh bantuan dengan pemesanan ini?
            <a href="/bantuan" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>
            &mdash; tim kami dapat membantu mengisikan data ini untuk Anda.
        </p>
    </div>
</div>

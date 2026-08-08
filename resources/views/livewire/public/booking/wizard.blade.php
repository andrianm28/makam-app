<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">

        <x-mk.stepper :step="$currentStep" :labels="$stepLabels" class="mb-8" />

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
                    <p class="text-base text-neutral-600">
                        Belum ada kota yang tersedia.
                    </p>
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
                    <ul class="grid gap-4 md:grid-cols-2" aria-label="Daftar TPU/TPS">
                        @foreach ($cemeteries as $cemetery)
                            <li>
                                <x-mk.card as="button" interactive wire:click="saveStep2('{{ $cemetery->id }}')">
                                    <span class="text-lg font-semibold text-neutral-900">{{ $cemetery->name }}</span>
                                    <x-mk.badge intent="neutral">{{ $cemetery->type }}</x-mk.badge>
                                </x-mk.card>
                            </li>
                        @endforeach
                    </ul>
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
                                {{ $type }}
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
        @endif

        {{-- Steps 4-5 are added by Task 10 --}}
    </div>
</div>

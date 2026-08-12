<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">

        {{-- NO `:labels` — see stepper.blade.php's own file header: passing
             `labels` from a booking screen is forbidden by AGENTS.md and
             design-system.md §9.2 MUST-NOT 9. The primitive's default IS the
             nine canonical booking labels. --}}
        <x-mk.stepper :step="$currentStep" class="mb-8" />

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
                    {{-- Two-level choice. A cemetery with active package/class
                         rows CANNOT be selected on its own — SaveBookingDraftStep
                         ::validateCemetery() requires a package id for it
                         (booking-wizard-fields.md §Step 2, "package/class when
                         applicable"), so those cards expand into one button per
                         package instead of a single whole-card button that could
                         only ever be rejected. Cemeteries with no packages keep
                         the plain whole-card button. --}}
                    <ul class="grid gap-4 md:grid-cols-2" aria-label="Daftar TPU/TPS">
                        @foreach ($cemeteries as $cemetery)
                            @php($packages = $packagesByCemetery[$cemetery->id] ?? collect())
                            <li>
                                @if ($packages->isEmpty())
                                    <button
                                        type="button"
                                        wire:click="saveStep2('{{ $cemetery->id }}')"
                                        wire:loading.attr="disabled"
                                        wire:target="saveStep2"
                                        class="block w-full rounded-lg border border-neutral-200 bg-neutral-0 p-4 text-left shadow-sm transition-[border-color,box-shadow] duration-fast ease-standard select-none hover:border-primary-300 hover:shadow-md focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2 md:p-6"
                                    >
                                        <span class="flex flex-col gap-4">
                                            <span class="text-lg font-semibold text-neutral-900">{{ $cemetery->name }}</span>
                                            <x-mk.badge intent="neutral">{{ $cemetery->type }}</x-mk.badge>
                                        </span>
                                    </button>
                                @else
                                    <div class="h-full rounded-lg border border-neutral-200 bg-neutral-0 p-4 shadow-sm md:p-6">
                                        <div class="flex flex-col gap-4">
                                            <span class="text-lg font-semibold text-neutral-900">{{ $cemetery->name }}</span>
                                            <x-mk.badge intent="neutral">{{ $cemetery->type }}</x-mk.badge>
                                        </div>

                                        <p id="cemetery-{{ $cemetery->id }}-packages-label" class="mt-4 text-sm text-neutral-600">
                                            Pilih paket/kelas untuk TPU/TPS ini:
                                        </p>

                                        <ul class="mt-2 flex flex-wrap gap-2" aria-labelledby="cemetery-{{ $cemetery->id }}-packages-label">
                                            @foreach ($packages as $package)
                                                <li>
                                                    <x-mk.button
                                                        variant="secondary"
                                                        wire:click="saveStep2('{{ $cemetery->id }}', {{ $package->id }})"
                                                        wire:loading.attr="disabled"
                                                        wire:target="saveStep2"
                                                    >
                                                        {{ $package->name }}@if ($package->class_label) &mdash; {{ $package->class_label }}@endif
                                                    </x-mk.button>
                                                </li>
                                            @endforeach
                                        </ul>
                                    </div>
                                @endif
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
                                <li class="flex items-center gap-3 rounded-lg border border-neutral-200 bg-neutral-50 p-3">
                                    <input
                                        type="checkbox"
                                        id="service-{{ $service->code }}"
                                        value="{{ $service->code }}"
                                        wire:model="stagedServiceCodes"
                                        checked
                                        disabled
                                        class="touch-target"
                                    />
                                    <label for="service-{{ $service->code }}" class="flex-1 text-base text-neutral-800">
                                        {{ $service->name }}
                                    </label>
                                    <x-mk.badge intent="neutral">Wajib</x-mk.badge>
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
                                <li class="flex items-center gap-3 rounded-lg border border-neutral-200 bg-neutral-0 p-3">
                                    <input
                                        type="checkbox"
                                        id="service-{{ $service->code }}"
                                        value="{{ $service->code }}"
                                        wire:model="stagedServiceCodes"
                                        class="touch-target"
                                    />
                                    <label for="service-{{ $service->code }}" class="flex-1 text-base text-neutral-800">
                                        {{ $service->name }}
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

                <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::SERVICES }})" class="mt-4">
                    Kembali
                </x-mk.button>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::CUSTOMER_DATA)
            <section aria-labelledby="booking-step-6-heading">
                <h2 id="booking-step-6-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                    Langkah 6 &mdash; Data Pemesan
                </h2>

                <form wire:submit="saveStep6" class="flex flex-col gap-5">
                    <div>
                        <label for="customer-full-name" class="mb-1 block text-sm font-medium text-neutral-700">Nama Lengkap</label>
                        <input
                            type="text"
                            id="customer-full-name"
                            wire:model="customerFullName"
                            class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-base"
                            autocomplete="name"
                        />
                        @error('customer_full_name')
                            <p class="mt-1 text-sm text-danger-700" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="customer-mobile" class="mb-1 block text-sm font-medium text-neutral-700">Nomor HP</label>
                        <input
                            type="tel"
                            id="customer-mobile"
                            wire:model="customerMobile"
                            class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-base"
                            autocomplete="tel"
                        />
                        @error('customer_mobile')
                            <p class="mt-1 text-sm text-danger-700" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="customer-email" class="mb-1 block text-sm font-medium text-neutral-700">Email</label>
                        <input
                            type="email"
                            id="customer-email"
                            wire:model="customerEmail"
                            class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-base"
                            autocomplete="email"
                        />
                        @error('customer_email')
                            <p class="mt-1 text-sm text-danger-700" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="customer-address" class="mb-1 block text-sm font-medium text-neutral-700">Alamat Lengkap</label>
                        <textarea
                            id="customer-address"
                            wire:model="customerAddress"
                            rows="3"
                            class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-base"
                            autocomplete="street-address"
                        ></textarea>
                        @error('customer_address')
                            <p class="mt-1 text-sm text-danger-700" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="customer-relationship" class="mb-1 block text-sm font-medium text-neutral-700">Hubungan dengan Almarhum</label>
                        <select
                            id="customer-relationship"
                            wire:model="customerRelationship"
                            class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-base"
                        >
                            <option value="">Pilih hubungan</option>
                            @foreach (\App\Domain\Booking\BookingRelationshipCode::KNOWN_CODES as $rel)
                                <option value="{{ $rel }}">{{ $rel }}</option>
                            @endforeach
                        </select>
                        @error('customer_relationship')
                            <p class="mt-1 text-sm text-danger-700" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="customer-contact-channel" class="mb-1 block text-sm font-medium text-neutral-700">Saluran Kontak yang Disukai</label>
                        <select
                            id="customer-contact-channel"
                            wire:model="customerContactChannel"
                            class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-base"
                        >
                            <option value="">Pilih saluran</option>
                            @foreach (\App\Domain\Booking\BookingContactChannel::KNOWN_CODES as $ch)
                                <option value="{{ $ch }}">{{ $ch }}</option>
                            @endforeach
                        </select>
                        @error('customer_contact_channel')
                            <p class="mt-1 text-sm text-danger-700" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <p class="text-sm text-neutral-600">
                        Dengan melanjutkan, Anda menyetujui bahwa data yang diberikan adalah benar dan akurat.
                    </p>
                    @error('privacy_notice_accepted_at')
                        <p class="mt-1 text-sm text-danger-700" role="alert">{{ $message }}</p>
                    @enderror

                    <div class="flex gap-3">
                        <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::SUMMARY }})" type="button">
                            Kembali
                        </x-mk.button>
                        <x-mk.button variant="primary" type="submit" wire:loading.attr="disabled">
                            Lanjutkan
                        </x-mk.button>
                    </div>
                </form>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::DECEASED_DATA)
            <section aria-labelledby="booking-step-7-heading">
                <h2 id="booking-step-7-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                    Langkah 7 &mdash; Data Almarhum
                </h2>

                <form wire:submit="saveStep7" class="flex flex-col gap-5">
                    <div>
                        <label for="deceased-full-name" class="mb-1 block text-sm font-medium text-neutral-700">Nama Lengkap Almarhum</label>
                        <input
                            type="text"
                            id="deceased-full-name"
                            wire:model="deceasedFullName"
                            class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-base"
                        />
                        @error('deceased_full_name')
                            <p class="mt-1 text-sm text-danger-700" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="deceased-date-of-birth" class="mb-1 block text-sm font-medium text-neutral-700">Tanggal Lahir</label>
                            <input
                                type="date"
                                id="deceased-date-of-birth"
                                wire:model="deceasedDateOfBirth"
                                class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-base"
                            />
                            @error('deceased_date_of_birth')
                                <p class="mt-1 text-sm text-danger-700" role="alert">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="deceased-date-of-death" class="mb-1 block text-sm font-medium text-neutral-700">Tanggal Meninggal</label>
                            <input
                                type="date"
                                id="deceased-date-of-death"
                                wire:model="deceasedDateOfDeath"
                                class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-base"
                            />
                            @error('deceased_date_of_death')
                                <p class="mt-1 text-sm text-danger-700" role="alert">{{ $message }}</p>
                            @enderror
                        </div>
                    </div>

                    <div>
                        <label for="deceased-relationship" class="mb-1 block text-sm font-medium text-neutral-700">Hubungan dengan Pemesan</label>
                        <select
                            id="deceased-relationship"
                            wire:model="deceasedRelationship"
                            class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-base"
                        >
                            <option value="">Pilih hubungan</option>
                            @foreach (\App\Domain\Booking\BookingRelationshipCode::KNOWN_CODES as $rel)
                                <option value="{{ $rel }}">{{ $rel }}</option>
                            @endforeach
                        </select>
                        @error('deceased_relationship')
                            <p class="mt-1 text-sm text-danger-700" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="deceased-gender" class="mb-1 block text-sm font-medium text-neutral-700">Jenis Kelamin</label>
                        <select
                            id="deceased-gender"
                            wire:model="deceasedGender"
                            class="w-full rounded-lg border border-neutral-300 px-3 py-2 text-base"
                        >
                            <option value="">Pilih (opsional)</option>
                            @foreach (\App\Domain\Booking\BookingGender::KNOWN_CODES as $g)
                                <option value="{{ $g }}">{{ $g }}</option>
                            @endforeach
                        </select>
                        @error('deceased_gender')
                            <p class="mt-1 text-sm text-danger-700" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-600">
                        <p class="font-medium text-neutral-700 mb-2">Dokumen yang diperlukan:</p>
                        <ul class="list-inside list-disc space-y-1">
                            <li>KTP almarhum/almarhumah</li>
                            <li>Kartu Keluarga (KK)</li>
                            <li>Surat Keterangan Kematian</li>
                        </ul>
                        <p class="mt-3">Dokumen akan diunggah pada langkah selanjutnya.</p>
                    </div>

                    <div class="flex gap-3">
                        <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::CUSTOMER_DATA }})" type="button">
                            Kembali
                        </x-mk.button>
                        <x-mk.button variant="primary" type="submit" wire:loading.attr="disabled">
                            Lanjutkan
                        </x-mk.button>
                    </div>
                </form>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::PAYMENT)
            <section aria-labelledby="booking-step-8-heading">
                <h2 id="booking-step-8-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                    Langkah 8 &mdash; Pembayaran
                </h2>

                @if (!$paymentGateOpen)
                    <x-mk.gate-closed-banner intent="info" :dismissible="false" title="Pembayaran online belum tersedia">
                        Pembayaran online belum tersedia untuk saat ini. Silakan gunakan metode manual di bawah ini.
                    </x-mk.gate-closed-banner>
                @endif

                <div class="mt-4 flex flex-col gap-4">
                    @if ($paymentGateOpen)
                        <div class="rounded-lg border border-primary-300 bg-primary-50 p-4">
                            <p class="font-medium text-primary-800">Pembayaran Online</p>
                            <p class="mt-1 text-sm text-primary-700">
                                Anda akan diarahkan ke halaman pembayaran untuk menyelesaikan transaksi.
                            </p>
                            <x-mk.button
                                variant="primary"
                                wire:click="saveStep8('{{ \App\Domain\Booking\BookingPaymentMethod::ONLINE }}')"
                                wire:loading.attr="disabled"
                                class="mt-3"
                            >
                                Bayar Sekarang
                            </x-mk.button>
                        </div>
                    @endif

                    <div class="rounded-lg border border-neutral-200 p-4">
                        <p class="font-medium text-neutral-800">Pembayaran Manual</p>
                        <p class="mt-1 text-sm text-neutral-600">
                            Transfer ke rekening yang akan diinformasikan setelah Anda melanjutkan.
                            Mohon siapkan bukti transfer untuk verifikasi.
                        </p>
                        <x-mk.button
                            variant="secondary"
                            wire:click="saveStep8('{{ \App\Domain\Booking\BookingPaymentMethod::MANUAL }}')"
                            wire:loading.attr="disabled"
                            class="mt-3"
                        >
                            Saya Akan Bayar Manual
                        </x-mk.button>
                    </div>
                </div>

                @error('payment_method')
                    <p class="mt-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                @enderror
                @error('payment_reference')
                    <p class="mt-3 text-sm text-danger-700" role="alert">{{ $message }}</p>
                @enderror

                <x-mk.button variant="tertiary" wire:click="goToStep({{ \App\Domain\Booking\BookingWizardStep::DECEASED_DATA }})" class="mt-4">
                    Kembali
                </x-mk.button>
            </section>
        @elseif ($currentStep === \App\Domain\Booking\BookingWizardStep::CONFIRMATION)
            <section aria-labelledby="booking-step-9-heading">
                <h2 id="booking-step-9-heading" class="mb-3 text-lg font-semibold text-neutral-900">
                    Langkah 9 &mdash; Konfirmasi
                </h2>

                <div class="rounded-lg border border-success-200 bg-success-50 p-6 text-center">
                    <p class="text-lg font-semibold text-success-800">Pesanan Anda sedang diproses</p>
                    <p class="mt-2 text-sm text-success-700">
                        Terima kasih. Pesanan Anda telah diterima dan sedang dalam proses verifikasi.
                    </p>
                    <p class="mt-2 text-sm text-success-600">
                        Anda akan menerima nomor pesanan dan detail lebih lanjut melalui email atau WhatsApp.
                    </p>
                </div>

                <div class="mt-6 rounded-lg border border-neutral-200 p-4 text-sm text-neutral-600">
                    <p class="font-medium text-neutral-800 mb-2">Apa yang selanjutnya?</p>
                    <ul class="list-inside list-disc space-y-1">
                        <li>Pesanan Anda akan diverifikasi dalam 1x24 jam kerja.</li>
                        <li>Anda akan dihubungi oleh tim kami untuk konfirmasi detail.</li>
                        <li>Untuk pertanyaan, silakan hubungi <a href="/bantuan" class="underline">Bantuan</a>.</li>
                    </ul>
                </div>
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

        <div aria-live="polite" class="mt-4 text-sm text-neutral-600">
            @if ($autosaveState === 'saved')
                <span>Tersimpan</span>
            @elseif ($autosaveState === 'failed')
                <span class="text-danger-700">Gagal menyimpan &mdash; coba lagi</span>
            @endif
        </div>
    </div>
</div>

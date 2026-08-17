{{--
    resources/views/livewire/public/pre-need/pre-need-interest-page.blade.php

    App\Livewire\Public\PreNeed\PreNeedInterestPage's view — `/preneed`, Task 5
    (docs/superpowers/plans/2026-08-16-p5a-certificates-preneed.md, Lane 3).
    The §6.9 InterestOnly public surface while G-LEGAL-01 is closed: register
    interest, request a consultation, or check a certificate's status
    (state-only). See that class's doc block for the mode banner's server-side
    resolution and the minimal-draft seam.

    --- Copy discipline ---
    Every fixed Indonesian sentence here is either the brief's exact text or
    the page's own neutral public copy — nothing is invented as if it were a
    canonical catalogue label (AGENTS.md: "Do not invent alternate labels
    without product change approval"). "Pre-Need" stays English, per
    App\Domain\Booking\BookingServiceType's own label note.
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">

        {{-- §6.9 gated fallback banner. Server-resolved via ModeResolver on
             every render; `intent` and `dismissible` both come from
             PreNeedMode::fallback() — the non-dismissible info banner for the
             InterestOnly mode that changes what the visitor receives (no
             payment/paid booking can be created). Never decided at this call
             site. --}}
        @if ($fallback)
            <div class="mb-8">
                <x-mk.alert
                    :intent="$fallback->intent"
                    icon="alert-circle"
                    title="Layanan Pre-Need"
                    :dismissible="$fallback->dismissible"
                    live="polite"
                >
                    {{-- The brief's fixed §6.9 copy, embedded verbatim so the
                         canonical phrase survives untouched. --}}
                    Saat ini layanan pra-pesan belum dapat diaktifkan; daftarkan minat atau minta konsultasi.
                </x-mk.alert>
            </div>
        @endif

        <div class="mx-auto mb-8 max-w-prose space-y-2 text-center">
            <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">
                Pre-Need
            </h1>
            <p class="text-base text-neutral-600">
                Daftarkan minat Anda untuk pemesanan makam di masa mendatang, atau minta konsultasi dengan tim kami.
            </p>
        </div>

        {{-- ============ Interest form / confirmation ============ --}}
        <section aria-labelledby="preneed-interest-heading" class="mb-6">
            <x-mk.card class="flex h-full flex-col gap-4 p-6">
                @if ($interestRegistered)
                    <x-mk.alert intent="success" title="Minat Anda terdaftar" live="polite">
                        <p class="text-sm">
                            Minat Anda terdaftar. Tim kami akan menghubungi Anda.
                        </p>
                        @if ($mode->value === 'interest_only')
                            <p class="mt-2 text-sm">
                                Seluruh proses pra-pesan belum dapat diaktifkan saat ini; minat Anda kami simpan untuk
                                dihubungi.
                            </p>
                        @endif
                    </x-mk.alert>
                @else
                    <h2 id="preneed-interest-heading" class="text-lg font-semibold text-neutral-900">
                        Daftarkan Minat
                    </h2>
                    <p class="text-sm text-neutral-600">
                        Isi data Anda dan wilayah layanan untuk mendaftarkan minat pra-pesan makam.
                    </p>

                    <div class="space-y-4">
                        <x-mk.field
                            type="select"
                            label="Kota (wilayah layanan)"
                            name="interest_city"
                            :required="true"
                            wire:model="interestCity"
                            :error="$errors->first('interestCity')"
                        >
                            <option value="">Pilih kota&hellip;</option>
                            @foreach ($cities as $cityOption)
                                <option value="{{ $cityOption['code'] }}">{{ $cityOption['label'] }}</option>
                            @endforeach
                        </x-mk.field>

                        <x-mk.field
                            label="Nama Anda"
                            name="interest_name"
                            :required="true"
                            autocomplete="name"
                            wire:model="interestName"
                            :error="$errors->first('interestName')"
                        />

                        <x-mk.field
                            label="Kontak (telepon atau email)"
                            name="interest_contact"
                            :required="true"
                            wire:model="interestContact"
                            :error="$errors->first('interestContact')"
                        />

                        <div class="flex flex-wrap items-center gap-3">
                            <x-mk.button
                                variant="primary"
                                wire:click="registerInterest"
                                wire:loading.attr="disabled"
                                wire:target="registerInterest"
                            >
                                Daftarkan Minat
                            </x-mk.button>
                            <span wire:loading wire:target="registerInterest" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                                <x-mk.spinner class="size-4" aria-hidden="true" />
                                Mendaftarkan minat&hellip;
                            </span>
                        </div>
                    </div>
                @endif
            </x-mk.card>
        </section>

        {{-- ============ Consultation form / confirmation ============ --}}
        <section aria-labelledby="preneed-consultation-heading" class="mb-6">
            <x-mk.card class="flex h-full flex-col gap-4 p-6">
                @if ($consultationSubmitted)
                    <x-mk.alert intent="success" title="Permintaan konsultasi diterima" live="polite">
                        <p class="text-sm">
                            Permintaan konsultasi diterima. Tim kami akan menghubungi Anda.
                        </p>
                    </x-mk.alert>
                @else
                    <h2 id="preneed-consultation-heading" class="text-lg font-semibold text-neutral-900">
                        Minta Konsultasi
                    </h2>
                    <p class="text-sm text-neutral-600">
                        Ceritakan kebutuhan Anda; tim kami akan menghubungi Anda untuk konsultasi lebih lanjut.
                    </p>

                    <div class="space-y-4">
                        <x-mk.field
                            label="Nama Anda"
                            name="consult_name"
                            :required="true"
                            autocomplete="name"
                            wire:model="consultName"
                            :error="$errors->first('consultName')"
                        />

                        <x-mk.field
                            label="Kontak (telepon atau email)"
                            name="consult_contact"
                            :required="true"
                            wire:model="consultContact"
                            :error="$errors->first('consultContact')"
                        />

                        <x-mk.field
                            type="textarea"
                            label="Pesan"
                            name="consult_message"
                            :rows="4"
                            :required="true"
                            wire:model="consultMessage"
                            :error="$errors->first('consultMessage')"
                        />

                        <div class="flex flex-wrap items-center gap-3">
                            <x-mk.button
                                variant="primary"
                                wire:click="requestConsultation"
                                wire:loading.attr="disabled"
                                wire:target="requestConsultation"
                            >
                                Kirim Permintaan Konsultasi
                            </x-mk.button>
                            <span wire:loading wire:target="requestConsultation" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                                <x-mk.spinner class="size-4" aria-hidden="true" />
                                Mengirim permintaan&hellip;
                            </span>
                        </div>
                    </div>
                @endif
            </x-mk.card>
        </section>

        {{-- ============ Certificate status (state-only, AC6) ============ --}}
        <section aria-labelledby="preneed-certificate-heading" class="mb-6">
            <x-mk.card class="flex h-full flex-col gap-4 p-6">
                <h2 id="preneed-certificate-heading" class="text-lg font-semibold text-neutral-900">
                    Cek Status Sertifikat
                </h2>
                <p class="text-sm text-neutral-600">
                    Periksa status sertifikat pemakaman Anda berdasarkan jenis dan nomor subjeknya.
                </p>

                <div class="space-y-4">
                    <x-mk.field
                        type="select"
                        label="Jenis subjek"
                        name="cert_subject_type"
                        :required="true"
                        wire:model="certSubjectType"
                        :error="$errors->first('certSubjectType')"
                    >
                        <option value="">Pilih jenis&hellip;</option>
                        @foreach ($certificateSubjectTypes as $key => $option)
                            <option value="{{ $key }}">{{ $option['label'] }}</option>
                        @endforeach
                    </x-mk.field>

                    <x-mk.field
                        label="ID subjek / nomor pesanan"
                        name="cert_subject_id"
                        :required="true"
                        wire:model="certSubjectId"
                        :error="$errors->first('certSubjectId')"
                    />

                    <x-mk.button
                        variant="primary"
                        wire:click="checkCertificateStatus"
                        wire:loading.attr="disabled"
                        wire:target="checkCertificateStatus"
                    >
                        Cek Status Sertifikat
                    </x-mk.button>
                </div>

                @if ($certChecked)
                    @if ($certificates === [])
                        {{-- §6.2 empty state — three parts: what is empty, why,
                             what to do next. Distinct from "not yet checked". --}}
                        <x-mk.alert intent="info" title="Belum ada sertifikat" live="polite">
                            Belum ada sertifikat untuk referensi tersebut. Ini dapat berarti referensi salah, atau
                            sertifikat belum diterbitkan. Silakan periksa kembali, atau
                            <a href="/bantuan" class="font-medium underline underline-offset-2">hubungi Bantuan</a>.
                        </x-mk.alert>
                    @else
                        <ul class="space-y-3" aria-label="Status sertifikat">
                            @foreach ($certificates as $certificate)
                                <li class="rounded-lg border border-neutral-200 p-4">
                                    {{-- AC6 pins these five fields and nothing else:
                                         type/status/version/effective_at/issued_by_role.
                                         The vault document reference and the
                                         certificate's own number NEVER leave the
                                         server through this section. --}}
                                    <dl class="grid grid-cols-1 gap-x-4 gap-y-1 text-sm sm:grid-cols-2">
                                        <div>
                                            <dt class="text-neutral-600">Jenis</dt>
                                            <dd class="font-medium text-neutral-900">{{ $certificate['type'] }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-neutral-600">Status</dt>
                                            <dd class="font-medium text-neutral-900">{{ $certificate['status'] }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-neutral-600">Versi</dt>
                                            <dd class="font-medium text-neutral-900">{{ $certificate['version'] }}</dd>
                                        </div>
                                        <div>
                                            <dt class="text-neutral-600">Berlaku sejak</dt>
                                            <dd class="font-medium text-neutral-900">
                                                {{ $certificate['effective_at'] !== null
                                                    ? \Illuminate\Support\Carbon::parse($certificate['effective_at'])->translatedFormat('d F Y')
                                                    : '-' }}
                                            </dd>
                                        </div>
                                        <div>
                                            <dt class="text-neutral-600">Diterbitkan oleh</dt>
                                            <dd class="font-medium text-neutral-900">{{ $certificate['issued_by_role'] }}</dd>
                                        </div>
                                    </dl>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endif
            </x-mk.card>
        </section>

        {{-- §6.10 support escape hatch — required on every transactional
             screen, and product-brief.md §5 point 10 requires it on every
             page. --}}
        <p class="mt-10 text-center text-sm text-neutral-600">
            Butuh bantuan atau konsultasi langsung?
            <a href="/bantuan" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>.
        </p>
    </div>
</div>
{{--
    resources/views/livewire/public/visitation/page.blade.php

    The public /kunjungan page — `.kiro/specs/visitation-booking` AC1/AC3/
    AC5/AC7, Task 2 of `docs/superpowers/plans/2026-08-16-p4-memorial-qr-
    visitation.md` (Lane 2 — Visitation).

    Mode-aware, per the kiro design's hard requirement ("The visual
    difference must be unmistakable"): INFORMATION_ONLY/NONE render the
    info banner (the policy's visiting hours when they exist, else the
    generic "jam kunjungan berlaku" line) and ZERO bookable controls;
    BOOKABLE renders the request form — date select with blackout dates
    visibly disabled WITH their reason and full dates disabled with
    "penuh", `inputmode="numeric"` visitor count, `autocomplete="tel"`
    contact, a generous accessibility textarea, allowlisted facility
    checkboxes. Submit renders the AC5 confirmation card (reference,
    instructions, change/cancel note, fallback contact) — and a duplicate
    submission of the same logical request renders the SAME confirmation
    (AC7).

    Every status surface here is a design-system primitive: `<x-mk.alert>`
    (§3.8) for the mode banners, `<x-mk.card>` (§3.3) for the form and the
    confirmation, `<x-mk.field>` (§3.2) for every control, `<x-mk.badge>`
    (§3.6) for the pending state, `<x-mk.button>` (§3.1) for actions.
    Tokens only — no hardcoded colour/spacing values, no Tailwind
    arbitrary values.
--}}

@if ($cemetery === null)
    {{-- =============================================================
         Picker state — /kunjungan (slug-less)
         ============================================================= --}}
    <div class="mx-auto w-full max-w-3xl px-4 py-10">
        <h1 class="text-2xl font-semibold text-neutral-900">Kunjungan Makam</h1>
        <p class="mt-2 text-base text-neutral-600">
            Pilih lokasi untuk melihat jam kunjungan atau mengajukan permintaan kunjungan.
        </p>

        <div class="mt-6 flex flex-col gap-3">
            @forelse ($cemeteries as $picked)
                <x-mk.card interactive href="{{ route('kunjungan.cemetery', ['cemeterySlug' => $picked->slug]) }}">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <p class="text-base font-semibold text-neutral-900">{{ $picked->name }}</p>
                            <p class="text-sm text-neutral-600">{{ $picked->city }}</p>
                        </div>
                        <x-mk.badge intent="neutral">Lihat</x-mk.badge>
                    </div>
                </x-mk.card>
            @empty
                <x-mk.alert intent="info" title="Belum ada lokasi kunjungan">
                    <p class="text-sm">
                        Direktori lokasi belum tersedia. Hubungi
                        <a href="{{ route('bantuan.index') }}" class="font-medium underline underline-offset-2">Bantuan</a>
                        untuk informasi lebih lanjut.
                    </p>
                </x-mk.alert>
            @endforelse
        </div>
    </div>
@else
    <div class="mx-auto w-full max-w-3xl px-4 py-10">
        <p class="text-sm text-neutral-600">
            <a href="{{ route('kunjungan.index') }}" class="font-medium underline underline-offset-2">Kunjungan</a>
            &rsaquo; {{ $cemetery->name }}
        </p>

        <h1 class="mt-2 text-2xl font-semibold text-neutral-900">Kunjungan Makam</h1>
        <p class="mt-1 text-base text-neutral-600">{{ $cemetery->name }}</p>

        @if ($capabilitiesDegraded)
            <x-mk.alert intent="pending" title="Informasi kunjungan belum dapat dimuat" class="mt-6">
                <p class="text-sm">
                    Informasi kunjungan untuk lokasi ini sedang tidak dapat ditampilkan.
                    Silakan coba kembali nanti atau hubungi
                    <a href="{{ route('bantuan.index') }}" class="font-medium underline underline-offset-2">Bantuan</a>.
                </p>
            </x-mk.alert>

        @elseif ($mode === 'BOOKABLE')
            {{-- =========================================================
                 BOOKABLE — the request form
                 ========================================================= --}}
            @if ($confirmation !== null)
                @include('livewire.public.visitation.partials.confirmation', [
                    'booking' => $confirmation,
                    'cemetery' => $cemetery,
                ])
            @else
                <x-mk.card class="mt-6">
                    <div class="flex flex-col gap-5">
                        <div>
                            <h2 class="text-lg font-semibold text-neutral-900">Ajukan Permintaan Kunjungan</h2>
                            <p class="mt-1 text-sm text-neutral-600">
                                Permintaan Anda dikirim ke pengelola lokasi dan menunggu konfirmasi.
                                Mengajukan permintaan tidak berarti jadwal terkunci.
                            </p>
                        </div>

                        @if ($policy !== null)
                            <dl class="rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-700">
                                <dt class="font-semibold text-neutral-900">Jam kunjungan</dt>
                                <dd class="mt-2 grid grid-cols-1 gap-1 sm:grid-cols-2">
                                    @foreach (App\Domain\Visitation\Models\CemeteryVisitationPolicy::WEEKDAY_KEYS as $key)
                                        <span>{{ App\Support\Design\IndonesianDate::weekdayLine($key, $policy->operating_hours[$key] ?? null) }}</span>
                                    @endforeach
                                </dd>
                            </dl>
                        @endif

                        @error('visitDate')
                            <x-mk.alert intent="danger" title="Periksa tanggal kunjungan" live="assertive">
                                <p class="text-sm">{{ $message }}</p>
                            </x-mk.alert>
                        @enderror
                        @error('visitorCount')
                            <x-mk.alert intent="danger" title="Periksa jumlah pengunjung" live="assertive">
                                <p class="text-sm">{{ $message }}</p>
                            </x-mk.alert>
                        @enderror
                        @error('facilityRequests')
                            <x-mk.alert intent="danger" title="Periksa permintaan fasilitas" live="assertive">
                                <p class="text-sm">{{ $message }}</p>
                            </x-mk.alert>
                        @enderror

                        @if ($bookableDates === [] && $blackoutDates === [])
                            {{-- §6.2 empty state: say WHY, offer the contact
                                 route — never a bare "tidak tersedia". --}}
                            <x-mk.alert intent="info" title="Tanggal belum tersedia">
                                <p class="text-sm">
                                    Belum ada tanggal kunjungan yang dapat dipesan untuk lokasi ini saat ini.
                                    Hubungi
                                    <a href="{{ route('bantuan.index') }}" class="font-medium underline underline-offset-2">Bantuan</a>
                                    untuk menanyakan ketersediaan.
                                </p>
                            </x-mk.alert>
                        @else
                            <div class="flex flex-col gap-5">
                                <x-mk.field
                                    type="select"
                                    label="Tanggal kunjungan"
                                    name="visit_date"
                                    :required="true"
                                    wire:model="visitDate"
                                >
                                    <option value="">Pilih tanggal</option>
                                    @foreach ($bookableDates as $date => $info)
                                        @php
                                            $label = App\Support\Design\IndonesianDate::longDate(\Carbon\CarbonImmutable::parse($date));
                                        @endphp
                                        <option
                                            value="{{ $date }}"
                                            @disabled($info['capacity_left'] < 1)
                                        >
                                            {{ $label }}
                                            @if ($info['capacity_left'] < 1)
                                                &mdash; penuh
                                            @else
                                                &mdash; sisa {{ $info['capacity_left'] }} kuota
                                            @endif
                                        </option>
                                    @endforeach
                                    @foreach ($blackoutDates as $date => $reason)
                                        @php
                                            $label = App\Support\Design\IndonesianDate::longDate(\Carbon\CarbonImmutable::parse($date));
                                        @endphp
                                        <option value="{{ $date }}" disabled title="{{ $reason }}">
                                            {{ $label }} &mdash; tutup: {{ $reason }}
                                        </option>
                                    @endforeach
                                </x-mk.field>

                                <x-mk.field
                                    type="number"
                                    label="Jumlah pengunjung"
                                    name="visitor_count"
                                    inputmode="numeric"
                                    min="1"
                                    :required="true"
                                    wire:model="visitorCount"
                                />

                                <x-mk.field
                                    type="tel"
                                    label="Nomor telepon kontak"
                                    name="contact_phone"
                                    autocomplete="tel"
                                    :required="true"
                                    wire:model="contactPhone"
                                />

                                <x-mk.field
                                    type="email"
                                    label="Email kontak"
                                    name="contact_email"
                                    autocomplete="email"
                                    :optional="true"
                                    wire:model="contactEmail"
                                />

                                <x-mk.field
                                    type="textarea"
                                    label="Kebutuhan aksesibilitas"
                                    name="accessibility_needs"
                                    :rows="4"
                                    :optional="true"
                                    wire:model="accessibilityNeeds"
                                />

                                <fieldset>
                                    <legend class="text-base font-medium text-neutral-800">
                                        Fasilitas yang diminta
                                        <span class="font-normal text-neutral-600">(opsional)</span>
                                    </legend>
                                    <div class="mt-1 flex flex-col gap-1">
                                        @foreach ($facilityOptions as $key => $label)
                                            <x-mk.field
                                                type="checkbox"
                                                label="{{ $label }}"
                                                :name="'facility_requests[]'"
                                                :value="$key"
                                                :checked="in_array($key, $facilityRequests, true)"
                                                wire:model="facilityRequests"
                                            />
                                        @endforeach
                                    </div>
                                </fieldset>

                                <div class="flex flex-wrap items-center gap-3">
                                    <x-mk.button
                                        variant="primary"
                                        wire:click="requestVisit"
                                        wire:loading.attr="disabled"
                                        wire:target="requestVisit"
                                    >
                                        Kirim Permintaan Kunjungan
                                    </x-mk.button>
                                    <span wire:loading wire:target="requestVisit" role="status" class="flex items-center gap-2 text-sm text-neutral-600">
                                        <x-mk.spinner class="size-4" aria-hidden="true" />
                                        Mengirim permintaan&hellip;
                                    </span>
                                </div>
                            </div>
                        @endif
                    </div>
                </x-mk.card>
            @endif

        @elseif ($mode === 'INFORMATION_ONLY' || $mode === 'NONE')
            {{-- =========================================================
                 INFORMATION_ONLY / NONE — the unmistakable non-bookable
                 banner. No slot picker, no confirm button, no control
                 that looks bookable (kiro design tasks.md).
                 ========================================================= --}}
            <x-mk.alert intent="info" title="Informasi kunjungan" class="mt-6">
                <p class="text-sm">
                    Pemesanan kunjungan belum dapat dipesan secara online untuk lokasi ini.
                    Kunjungan dilakukan sesuai jam kunjungan lokasi.
                </p>
                @if ($policy !== null)
                    <div class="mt-3 grid grid-cols-1 gap-1 sm:grid-cols-2">
                        @foreach (App\Domain\Visitation\Models\CemeteryVisitationPolicy::WEEKDAY_KEYS as $key)
                            <span>{{ App\Support\Design\IndonesianDate::weekdayLine($key, $policy->operating_hours[$key] ?? null) }}</span>
                        @endforeach
                    </div>
                @else
                    <p class="mt-3 text-sm">
                        Jam kunjungan berlaku sesuai ketentuan lokasi.
                    </p>
                @endif
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

        @else
            {{-- Unknown mode value — fail closed, never a form. --}}
            <x-mk.alert intent="pending" title="Informasi kunjungan belum dapat dimuat" class="mt-6">
                <p class="text-sm">
                    Informasi kunjungan untuk lokasi ini sedang tidak dapat ditampilkan.
                    Hubungi
                    <a href="{{ route('bantuan.index') }}" class="font-medium underline underline-offset-2">Bantuan</a>.
                </p>
            </x-mk.alert>
        @endif
    </div>
@endif

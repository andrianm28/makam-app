{{--
    resources/views/livewire/public/visitation/partials/confirmation.blade.php

    AC5's confirmation card: reference, instructions, change/cancel note,
    fallback contact — quiet, never styled as confirmed while the booking
    is still `requested` (`<x-mk.badge intent="pending">`, §6.7: a
    pending request must never read as confirmed). Reached after submit
    AND on session restore, so a refresh renders the same card.
--}}

<x-mk.card intent="info" class="mt-6">
    <div class="flex flex-col gap-4">
        <div>
            <h2 class="text-lg font-semibold text-neutral-900">Permintaan Kunjungan Terkirim</h2>
            <p class="mt-1 text-sm text-neutral-600">
                Permintaan Anda telah kami terima. Simpan nomor referensi berikut untuk
                ditunjukkan saat kedatangan.
            </p>
        </div>

        <dl class="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-sm text-neutral-600">Nomor referensi</dt>
                <dd class="mt-0.5 text-base font-semibold text-neutral-900">{{ $booking->reference }}</dd>
            </div>
            <div>
                <dt class="text-sm text-neutral-600">Lokasi</dt>
                <dd class="mt-0.5 text-base font-medium text-neutral-900">{{ $cemetery->name }}</dd>
            </div>
            <div>
                <dt class="text-sm text-neutral-600">Tanggal kunjungan</dt>
                <dd class="mt-0.5 text-base font-medium text-neutral-900">
                    {{ App\Support\Design\IndonesianDate::longDate($booking->visit_date) }}
                </dd>
            </div>
            <div>
                <dt class="text-sm text-neutral-600">Jumlah pengunjung</dt>
                <dd class="mt-0.5 text-base font-medium text-neutral-900">{{ $booking->visitor_count }}</dd>
            </div>
        </dl>

        <div class="flex items-center gap-2">
            <span class="text-sm text-neutral-600">Status:</span>
            <x-mk.badge intent="pending">Menunggu konfirmasi</x-mk.badge>
        </div>

        <div class="rounded-lg border border-neutral-200 bg-neutral-50 p-4 text-sm text-neutral-700">
            <p class="font-semibold text-neutral-900">Petunjuk kunjungan</p>
            <ul class="mt-2 list-inside list-disc space-y-1">
                <li>Hadir pada tanggal kunjungan yang dipilih sesuai jam operasional lokasi.</li>
                <li>Sampaikan nomor referensi di atas saat kedatangan.</li>
                <li>Konfirmasi dari pengelola masih menunggu; status permintaan dapat berubah.</li>
            </ul>
        </div>

        <p class="text-sm text-neutral-600">
            Untuk perubahan atau pembatalan kunjungan, hubungi
            <a href="{{ route('bantuan.index') }}" class="font-medium underline underline-offset-2">Bantuan</a>
            dengan menyertakan nomor referensi di atas.
        </p>

        <div class="flex flex-wrap items-center gap-3">
            <x-mk.button
                variant="secondary"
                size="sm"
                wire:click="startAnotherRequest"
            >
                Ajukan permintaan lain
            </x-mk.button>
            <x-mk.button
                variant="secondary"
                size="sm"
                href="{{ route('bantuan.index') }}"
            >
                Butuh bantuan?
            </x-mk.button>
        </div>
    </div>
</x-mk.card>

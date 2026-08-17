{{--
    resources/views/livewire/public/care-subscription/care-history-page.blade.php

    /riwayat-perawatan/{customerId} — customer care history view
    (P5b Lane 4). Shows work order history with SEPARATE billing + work
    status badges (AC2/AC6), evidence status, and acceptance/complaint actions.

    Billing status + work order status = TWO separate indicators, never
    collapsed. Missed/failed service shows an honest operational state,
    never styled as a customer error (AC6). Server-confirmed state only (AC4).
--}}

@php
    use App\Support\Design\StatusIntent;
@endphp

<div class="mx-auto w-full max-w-3xl px-4 py-10">
    <h1 class="text-2xl font-semibold text-neutral-900">Riwayat Perawatan</h1>
    <p class="mt-2 text-base text-neutral-600">
        Daftar riwayat layanan perawatan makam. Status pembayaran dan pekerjaan
        ditampilkan secara terpisah.
    </p>

    <div class="mt-6">
        @forelse ($workOrders as $wo)
            @php
                $billingIntent = StatusIntent::intent($wo->billing_status, StatusIntent::FAMILY_CARE_FULFILLMENT);
                $billingLabel = StatusIntent::label($wo->billing_status, StatusIntent::FAMILY_CARE_FULFILLMENT);

                $workIntent = StatusIntent::intent($wo->work_status, StatusIntent::FAMILY_CARE_WORK_ORDER);
                $workLabel = StatusIntent::label($wo->work_status, StatusIntent::FAMILY_CARE_WORK_ORDER);
            @endphp

            <x-mk.card class="mb-3">
                <div class="flex flex-col gap-3">
                    <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="font-medium text-neutral-500">Referensi</dt>
                            <dd class="mt-1 font-mono font-semibold text-neutral-900">{{ $wo->reference }}</dd>
                        </div>

                        <div>
                            <dt class="font-medium text-neutral-500">Periode</dt>
                            <dd class="mt-1 text-neutral-700">
                                {{ \Illuminate\Support\Carbon::parse($wo->cycle_start)->translatedFormat('j M Y') }}
                                –
                                {{ \Illuminate\Support\Carbon::parse($wo->cycle_end)->translatedFormat('j M Y') }}
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-neutral-500">Status Pembayaran</dt>
                            <dd class="mt-1">
                                <x-mk.badge :intent="$billingIntent" dot>{{ $billingLabel }}</x-mk.badge>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-neutral-500">Status Pekerjaan</dt>
                            <dd class="mt-1">
                                <x-mk.badge :intent="$workIntent" dot>{{ $workLabel }}</x-mk.badge>
                            </dd>
                        </div>

                        @if ($wo->scheduled_at)
                            <div>
                                <dt class="font-medium text-neutral-500">Dijadwalkan</dt>
                                <dd class="mt-1 text-neutral-700">
                                    {{ \Illuminate\Support\Carbon::parse($wo->scheduled_at)->translatedFormat('j M Y') }}
                                </dd>
                            </div>
                        @endif

                        @if ($wo->completed_at)
                            <div>
                                <dt class="font-medium text-neutral-500">Selesai</dt>
                                <dd class="mt-1 text-neutral-700">
                                    {{ \Illuminate\Support\Carbon::parse($wo->completed_at)->translatedFormat('j M Y') }}
                                </dd>
                            </div>
                        @endif
                    </dl>

                    @if ($wo->work_status === 'missed')
                        <x-mk.alert intent="warning" title="Pekerjaan belum terselesaikan.">
                            <p class="text-sm">
                                Pekerjaan perawatan ini belum dapat diselesaikan. Tim kami akan
                                menghubungi Anda untuk penjadwalan ulang. Hubungi
                                <a href="{{ route('bantuan.index') }}" class="font-medium underline underline-offset-2">Bantuan</a>
                                jika Anda memiliki pertanyaan.
                            </p>
                        </x-mk.alert>
                    @endif
                </div>
            </x-mk.card>
        @empty
            <x-mk.alert intent="info" title="Belum ada riwayat perawatan.">
                <p class="text-sm">
                    Riwayat perawatan akan muncul setelah Anda memiliki langganan perawatan aktif
                    dan pekerjaan pertama dijadwalkan.
                    Hubungi
                    <a href="{{ route('bantuan.index') }}" class="font-medium underline underline-offset-2">Bantuan</a>
                    untuk informasi lebih lanjut.
                </p>
            </x-mk.alert>
        @endforelse
    </div>
</div>

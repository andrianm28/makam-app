{{--
    resources/views/livewire/public/care-subscription/care-history-page.blade.php

    /riwayat-perawatan/{customerId} — customer care history view
    (P5b Lane 4). Shows work order history with SEPARATE billing + work
    status badges (AC2/AC6), evidence status, and acceptance/complaint actions.

    Billing status + work order status = TWO separate indicators, never
    collapsed. Missed/failed service shows an honest operational state,
    never styled as a customer error (AC6). Server-confirmed state only (AC4).

    Accept/complaint actions only render when `$canAct` is true (the
    authenticated visitor IS this customer — see CareHistoryPage's own
    `isAuthorizedCustomer()`); everyone else sees the same history read-only,
    with a note pointing at login instead of a button that would just deny.
--}}

@php
    use App\Support\Design\StatusIntent;
    use App\Domain\VendorFulfillment\WorkOrderStatus;
@endphp

<div class="mx-auto w-full max-w-3xl px-4 py-10">
    <h1 class="text-2xl font-semibold text-neutral-900">Riwayat Perawatan</h1>
    <p class="mt-2 text-base text-neutral-600">
        Daftar riwayat layanan perawatan makam. Status pembayaran dan pekerjaan
        ditampilkan secara terpisah.
    </p>

    @if ($actionMessage)
        <x-mk.alert :intent="$actionIntent" title="{{ $actionMessage }}" class="mt-6" live="polite" />
    @endif

    @if (! $canAct && $workOrders->isNotEmpty())
        <x-mk.alert intent="info" title="Masuk untuk mengelola riwayat ini." class="mt-6" live="off">
            <p class="text-sm">
                Untuk menerima layanan atau mengajukan komplain,
                <a href="{{ route('login') }}" class="font-medium underline underline-offset-2">masuk ke akun Anda</a>
                sebagai pelanggan ini.
            </p>
        </x-mk.alert>
    @endif

    <div class="mt-6">
        @forelse ($workOrders as $wo)
            @php
                $billingIntent = StatusIntent::intent($wo->billing_status, StatusIntent::FAMILY_CARE_FULFILLMENT);
                $billingLabel = StatusIntent::label($wo->billing_status, StatusIntent::FAMILY_CARE_FULFILLMENT);

                $workIntent = StatusIntent::intent($wo->work_status, StatusIntent::FAMILY_CARE_WORK_ORDER);
                $workLabel = StatusIntent::label($wo->work_status, StatusIntent::FAMILY_CARE_WORK_ORDER);

                $isExpanded = $expandedWorkOrderId === $wo->id;
                $hasAccepted = $wo->acceptance_count > 0;
                $hasComplaint = $wo->complaint_status !== null;
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

                        @if ($hasComplaint)
                            @php
                                $complaintIntent = StatusIntent::intent($wo->complaint_status, StatusIntent::FAMILY_CARE_COMPLAINT);
                                $complaintLabel = StatusIntent::label($wo->complaint_status, StatusIntent::FAMILY_CARE_COMPLAINT);
                            @endphp
                            <div>
                                <dt class="font-medium text-neutral-500">Status Komplain</dt>
                                <dd class="mt-1">
                                    <x-mk.badge :intent="$complaintIntent" dot>{{ $complaintLabel }}</x-mk.badge>
                                </dd>
                            </div>
                        @endif
                    </dl>

                    @if ($wo->work_status === 'missed')
                        {{-- intent="pending" — <x-mk.alert> has no "warning" intent (it exposes
                             neutral/info/pending/success/danger/urgent; "pending"/"urgent" both
                             map to the same warning-600/50/800 triad). The original read-only
                             version of this view passed "warning" here, which silently fell back
                             to the "info" intent's blue styling via the component's `?? $intents['info']`
                             default — corrected while this file was already being touched. --}}
                        <x-mk.alert intent="pending" title="Pekerjaan belum terselesaikan.">
                            <p class="text-sm">
                                Pekerjaan perawatan ini belum dapat diselesaikan. Tim kami akan
                                menghubungi Anda untuk penjadwalan ulang. Hubungi
                                <a href="{{ route('bantuan.index') }}" class="font-medium underline underline-offset-2">Bantuan</a>
                                jika Anda memiliki pertanyaan.
                            </p>
                        </x-mk.alert>
                    @endif

                    @if ($hasAccepted)
                        <p class="text-sm font-medium text-success-800">Layanan ini telah Anda terima.</p>
                    @endif

                    @if ($canAct)
                        <div class="flex flex-wrap gap-3">
                            @if ($wo->work_status === WorkOrderStatus::Completed->value && ! $hasAccepted)
                                <x-mk.button
                                    size="sm"
                                    variant="primary"
                                    wire:click="showAcceptForm('{{ $wo->id }}')"
                                >
                                    Terima Layanan
                                </x-mk.button>
                            @endif

                            @if (! $hasComplaint)
                                <x-mk.button
                                    size="sm"
                                    variant="secondary"
                                    wire:click="showComplaintForm('{{ $wo->id }}')"
                                >
                                    Ajukan Komplain
                                </x-mk.button>
                            @endif
                        </div>

                        @if ($isExpanded && $expandedMode === 'accept')
                            <form wire:submit.prevent="acceptService" class="flex flex-col gap-4 border-t border-neutral-200 pt-4">
                                @error('action')
                                    <p class="text-sm text-danger-800">{{ $message }}</p>
                                @enderror

                                <x-mk.field
                                    type="select"
                                    label="Nilai kepuasan"
                                    name="rating"
                                    :optional="true"
                                    :error="$errors->first('rating')"
                                    wire:model="rating"
                                >
                                    <option value="">Tidak dinilai</option>
                                    @foreach ([1, 2, 3, 4, 5] as $value)
                                        <option value="{{ $value }}">{{ $value }} / 5</option>
                                    @endforeach
                                </x-mk.field>

                                <x-mk.field
                                    type="textarea"
                                    label="Catatan (opsional)"
                                    name="acceptance_notes"
                                    :rows="3"
                                    :optional="true"
                                    wire:model="acceptanceNotes"
                                />

                                <div class="flex gap-3">
                                    <x-mk.button type="submit" size="sm" variant="primary">Kirim Penerimaan</x-mk.button>
                                    <x-mk.button type="button" size="sm" variant="ghost" wire:click="cancelAction">Batal</x-mk.button>
                                </div>
                            </form>
                        @endif

                        @if ($isExpanded && $expandedMode === 'complain')
                            <form wire:submit.prevent="fileComplaint" class="flex flex-col gap-4 border-t border-neutral-200 pt-4">
                                @error('action')
                                    <p class="text-sm text-danger-800">{{ $message }}</p>
                                @enderror

                                <x-mk.field
                                    type="textarea"
                                    label="Uraikan komplain Anda"
                                    name="complaint_text"
                                    :rows="4"
                                    :required="true"
                                    :error="$errors->first('complaintText')"
                                    wire:model="complaintText"
                                />

                                <div class="flex gap-3">
                                    <x-mk.button type="submit" size="sm" variant="primary">Kirim Komplain</x-mk.button>
                                    <x-mk.button type="button" size="sm" variant="ghost" wire:click="cancelAction">Batal</x-mk.button>
                                </div>
                            </form>
                        @endif
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

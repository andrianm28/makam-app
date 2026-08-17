{{--
    resources/views/livewire/public/care-subscription/subscription-status-page.blade.php

    /langganan/{subscriptionReference} — customer subscription status view
    (P5b Lane 4). Shows subscription status, care plan name, frequency,
    price, and cycle history with SEPARATE billing + work indicators (AC6).

    PAID ≠ COMPLETED: every cycle row renders billing status and work order
    status as TWO separate badges, never collapsed into one "done" badge.
    Server-confirmed state only (AC4) — no optimistic UI.
--}}

@php
    use App\Support\Design\StatusIntent;

    $freqLabels = [
        'monthly' => 'Bulanan',
        'quarterly' => 'Triwulanan',
        'semi_annual' => 'Semesteran',
        'annual' => 'Tahunan',
    ];

    $subIntent = StatusIntent::intent($subscription->status, StatusIntent::FAMILY_CARE_SUBSCRIPTION);
    $subIcon = StatusIntent::icon($subscription->status, StatusIntent::FAMILY_CARE_SUBSCRIPTION);
    $subLabel = StatusIntent::label($subscription->status, StatusIntent::FAMILY_CARE_SUBSCRIPTION);
@endphp

<div class="mx-auto w-full max-w-3xl px-4 py-10">
    <h1 class="text-2xl font-semibold text-neutral-900">Status Langganan</h1>

    <x-mk.card class="mt-6">
        <div class="flex flex-col gap-4">
            <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                <div>
                    <dt class="font-medium text-neutral-500">Referensi</dt>
                    <dd class="mt-1 font-mono font-semibold text-neutral-900">{{ $subscription->reference }}</dd>
                </div>

                <div>
                    <dt class="font-medium text-neutral-500">Status</dt>
                    <dd class="mt-1">
                        <x-mk.badge :intent="$subIntent" dot>{{ $subLabel }}</x-mk.badge>
                    </dd>
                </div>

                <div>
                    <dt class="font-medium text-neutral-500">Paket Perawatan</dt>
                    <dd class="mt-1 font-semibold text-neutral-900">
                        {{ $carePlan?->name ?? '—' }}
                    </dd>
                </div>

                <div>
                    <dt class="font-medium text-neutral-500">Frekuensi</dt>
                    <dd class="mt-1 text-neutral-700">
                        {{ $freqLabels[$subscription->frequency] ?? $subscription->frequency }}
                    </dd>
                </div>

                <div>
                    <dt class="font-medium text-neutral-500">Harga per Siklus</dt>
                    <dd class="mt-1 text-right tabular-nums font-mono font-semibold text-neutral-900">
                        Rp {{ number_format($subscription->price_minor, 0, ',', '.') }}
                    </dd>
                </div>

                <div>
                    <dt class="font-medium text-neutral-500">Siklus Saat Ini</dt>
                    <dd class="mt-1 text-neutral-700">
                        {{ $subscription->current_cycle_number }}
                    </dd>
                </div>
            </dl>
        </div>
    </x-mk.card>

    <h2 class="mt-8 text-lg font-semibold text-neutral-900">Riwayat Siklus</h2>

    <div class="mt-4">
        @forelse ($cycles as $cycle)
            @php
                $billingIntent = StatusIntent::intent($cycle->status, StatusIntent::FAMILY_CARE_FULFILLMENT);
                $billingLabel = StatusIntent::label($cycle->status, StatusIntent::FAMILY_CARE_FULFILLMENT);
            @endphp

            <x-mk.card class="mb-3">
                <div class="flex flex-col gap-3">
                    <dl class="grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="font-medium text-neutral-500">Periode</dt>
                            <dd class="mt-1 text-neutral-700">
                                {{ \Illuminate\Support\Carbon::parse($cycle->cycle_start)->translatedFormat('j M Y') }}
                                –
                                {{ \Illuminate\Support\Carbon::parse($cycle->cycle_end)->translatedFormat('j M Y') }}
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-neutral-500">Status Pembayaran</dt>
                            <dd class="mt-1">
                                <x-mk.badge :intent="$billingIntent" dot>{{ $billingLabel }}</x-mk.badge>
                            </dd>
                        </div>

                        @if ($cycle->work_order_id)
                            @php
                                $workOrder = \App\Domain\VendorFulfillment\Models\WorkOrder::query()->find($cycle->work_order_id);
                                $workStatus = $workOrder?->status ?? null;
                                $workIntent = $workStatus ? StatusIntent::intent($workStatus, StatusIntent::FAMILY_CARE_WORK_ORDER) : 'neutral';
                                $workLabel = $workStatus ? StatusIntent::label($workStatus, StatusIntent::FAMILY_CARE_WORK_ORDER) : '—';
                            @endphp
                            <div>
                                <dt class="font-medium text-neutral-500">Status Pekerjaan</dt>
                                <dd class="mt-1">
                                    <x-mk.badge :intent="$workIntent" dot>{{ $workLabel }}</x-mk.badge>
                                </dd>
                            </div>
                        @endif
                    </dl>
                </div>
            </x-mk.card>
        @empty
            <x-mk.alert intent="info" title="Belum ada riwayat siklus.">
                <p class="text-sm">
                    Riwayat siklus akan muncul setelah langganan aktif dan siklus pertama dihasilkan.
                    Hubungi
                    <a href="{{ route('bantuan.index') }}" class="font-medium underline underline-offset-2">Bantuan</a>
                    untuk informasi lebih lanjut.
                </p>
            </x-mk.alert>
        @endforelse
    </div>
</div>

{{--
    resources/views/livewire/public/akun/order-list.blade.php

    App\Livewire\Public\Akun\OrderList's view — `/akun/pesanan`, Task 2 of
    PR 3 of the `/akun` account area
    (`.superpowers/sdd/2026-08-20-akun-pesanan/task-2-brief.md`). Read that
    component's doc block first.

    Empty state follows design-system.md §6.2's layout recipe (no canonical
    copy exists for this screen), same structure `draft-list.blade.php`
    already uses: `flex flex-col items-center gap-3 py-12 text-center`,
    title `text-lg font-semibold text-neutral-800`, body `text-base
    text-neutral-600 max-w-prose`, then a `secondary` button.

    Status badge resolves through `App\Support\Design\StatusIntent` —
    design-system.md §3.7 is explicit that a component must never switch on
    a status enum string itself. Product type is humanized inline
    (`ProductType` has no `label()` helper, unlike `BookingServiceType`) —
    this batch has no authority to write new canonical product copy.

    Deliberately NO "Lihat detail" link on any row. `information-
    architecture.md`'s `/pesanan/{orderReference}` detail page (PUB-050) is
    an orphaned forward-reference (`docs/planning/kiro-specs-analysis.md`)
    that does not exist in this codebase; the only real order-detail
    surface (`/marketplace/pesanan/{orderNumber}`) is marketplace-only and
    belongs to a different order concept entirely. Linking either would
    either 404 or point at the wrong thing, so this row stops at the
    reference/status/date summary — same honesty convention PR 2's
    `DraftList` used for its own gaps.
--}}
<div class="mx-auto max-w-content px-4 py-8 md:px-6 lg:px-8">
    <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">Pesanan Saya</h1>

    @if ($orders->isEmpty())
        <div class="flex flex-col items-center gap-3 py-12 text-center">
            <h2 class="text-lg font-semibold text-neutral-800">Belum ada pesanan.</h2>
            <p class="max-w-prose text-base text-neutral-600">
                Anda belum memiliki pesanan yang tercatat. Mulai pemesanan baru untuk membuat pesanan pertama Anda.
            </p>
            <x-mk.button variant="secondary" :href="route('pemesanan-makam.index')" class="mt-2">
                Mulai pemesanan
            </x-mk.button>
        </div>
    @else
        <ul class="mt-8 space-y-4" aria-label="Daftar pesanan">
            @foreach ($orders as $order)
                <li>
                    <x-mk.card>
                        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                            <div>
                                <p class="text-base font-semibold text-neutral-900">
                                    {{ $order->reference }}
                                </p>
                                <p class="mt-1 text-sm text-neutral-600">
                                    {{ ucwords(str_replace('_', ' ', strtolower($order->product_type))) }}
                                </p>
                                <p class="mt-1 text-sm text-neutral-500">
                                    Dibuat {{ $order->created_at?->translatedFormat('d M Y, H:i') }}
                                </p>
                            </div>

                            <x-mk.badge
                                :intent="\App\Support\Design\StatusIntent::intent($order->status, \App\Support\Design\StatusIntent::FAMILY_ORDER_LIFECYCLE)"
                                :icon="\App\Support\Design\StatusIntent::icon($order->status, \App\Support\Design\StatusIntent::FAMILY_ORDER_LIFECYCLE)"
                            >
                                {{ \App\Support\Design\StatusIntent::label($order->status, \App\Support\Design\StatusIntent::FAMILY_ORDER_LIFECYCLE) }}
                            </x-mk.badge>
                        </div>
                    </x-mk.card>
                </li>
            @endforeach
        </ul>
    @endif
</div>

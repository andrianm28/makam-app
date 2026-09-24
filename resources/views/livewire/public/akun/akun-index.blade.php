{{--
    resources/views/livewire/public/akun/akun-index.blade.php

    App\Livewire\Public\Akun\AkunIndex's view — `/akun`, Task 2 of the
    `/akun` account area (`.superpowers/sdd/2026-08-20-akun-shell-and-drafts/
    task-2-brief.md`). Read that component's doc block first.

    Structural precedent: resources/views/livewire/public/support/
    help-centre.blade.php — `max-w-content` page gutter, `text-3xl` h1.
    Every colour/spacing/radius value below is an ordinary Tailwind utility
    backed by a Layer 1 token in tokens.css, no hex, no arbitrary value
    (design-system.md §9.2 MUST NOT 1/2).

    Four tiles — see the component's own doc block. The renewal and
    document tiles carry a `<x-mk.badge intent="neutral">` "Segera hadir"
    marker (per this task's brief) since both routes render
    `<x-mk.gate-closed-page>` rather than real account-scoped data. The
    order tile (PR 3, Task 2 of `.superpowers/sdd/2026-08-20-akun-pesanan/
    task-2-brief.md`) carries no such marker — it links to `OrderList`,
    real account-scoped data.

    Tiles render with `emphasis="strong"` and `<x-mk.icon-medallion size="lg">`
    (FFI account-area visual restyle, `.scratch/ffi-clone-whole-frontend/
    issues/07-account-area-visual-restyle.md`) — each tile is a journey
    entrance into a whole sub-flow, the documented case for both props
    (design-system.md §3.3, §3.3a).
--}}
<div class="mx-auto max-w-content px-4 py-8 md:px-6 lg:px-8">
    <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">Akun Saya</h1>
    <p class="mt-2 text-base text-neutral-700">
        Halo, {{ $user->name }}.
    </p>

    {{-- Each tile below is a journey entrance into a whole sub-flow (draft
         resume, order list, renewal, documents) — design-system.md §3.3's
         emphasis="strong" case exactly, matching the icon-medallion size
         bump directly below it. --}}
    <div class="mt-8 grid grid-cols-1 gap-4 md:grid-cols-2">
        <x-mk.card :href="route('akun.draft')" interactive emphasis="strong">
            <div class="flex items-start gap-4">
                <x-mk.icon-medallion icon="clock" tone="primary" size="lg" />
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">Draft Pemesanan</h2>
                    <p class="mt-1 text-sm text-neutral-600">
                        @if ($openDraftCount > 0)
                            {{ $openDraftCount }} draft belum selesai
                        @else
                            Belum ada draft pemesanan
                        @endif
                    </p>
                </div>
            </div>
        </x-mk.card>

        <x-mk.card :href="route('akun.pesanan')" interactive emphasis="strong">
            <div class="flex items-start gap-4">
                <x-mk.icon-medallion icon="inbox" tone="primary" size="lg" />
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">Pesanan</h2>
                    <p class="mt-1 text-sm text-neutral-600">
                        @if ($orderCount > 0)
                            {{ $orderCount }} pesanan tercatat
                        @else
                            Belum ada pesanan
                        @endif
                    </p>
                </div>
            </div>
        </x-mk.card>

        <x-mk.card :href="route('akun.perpanjangan')" interactive emphasis="strong">
            <div class="flex items-start gap-4">
                <x-mk.icon-medallion icon="clock-x" tone="primary" size="lg" />
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">
                        Perpanjangan
                        <x-mk.badge intent="neutral">Segera hadir</x-mk.badge>
                    </h2>
                    <p class="mt-1 text-sm text-neutral-600">
                        Perpanjangan makam via akun belum tersedia
                    </p>
                </div>
            </div>
        </x-mk.card>

        <x-mk.card :href="route('akun.dokumen')" interactive emphasis="strong">
            <div class="flex items-start gap-4">
                <x-mk.icon-medallion icon="document-text" tone="primary" size="lg" />
                <div>
                    <h2 class="text-lg font-semibold text-neutral-900">
                        Dokumen
                        <x-mk.badge intent="neutral">Segera hadir</x-mk.badge>
                    </h2>
                    <p class="mt-1 text-sm text-neutral-600">
                        Unggah dokumen pelanggan belum tersedia
                    </p>
                </div>
            </div>
        </x-mk.card>
    </div>

    <div class="mt-8 flex flex-wrap items-center gap-4 border-t border-neutral-200 pt-6">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <x-mk.button type="submit" variant="secondary">Keluar</x-mk.button>
        </form>

        <a
            href="{{ route('bantuan.index') }}"
            class="touch-target inline-flex items-center rounded-sm text-base font-medium text-primary-700 underline underline-offset-2 hover:text-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
        >
            Bantuan
        </a>
    </div>
</div>

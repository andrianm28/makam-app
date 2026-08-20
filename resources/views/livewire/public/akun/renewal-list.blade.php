{{--
    resources/views/livewire/public/akun/renewal-list.blade.php

    App\Livewire\Public\Akun\RenewalList's view — `/akun/perpanjangan`,
    Task 3 of the `/akun` account area
    (`.superpowers/sdd/2026-08-20-akun-shell-and-drafts/task-3-brief.md`).
    Read that component's doc block first.

    `<x-mk.gate-closed-page>` is the design-system.md §6.4 "Gate closed"
    primitive — see its own doc block. Fallback sends the visitor to the
    existing public renewal flow; support sends them to `/bantuan`.
--}}
<x-mk.gate-closed-page heading="Perpanjangan belum tersedia di akun" icon="clock-x">
    Perpanjangan makam saat ini masih diproses melalui alur publik yang ada,
    belum terhubung ke akun Anda.

    <x-slot:fallback>
        <x-mk.button href="{{ route('perpanjangan.index') }}">Buka Perpanjangan Makam</x-mk.button>
    </x-slot:fallback>

    <x-slot:support>
        <a
            href="{{ route('bantuan.index') }}"
            class="touch-target inline-flex items-center rounded-sm font-medium text-primary-700 underline underline-offset-2 hover:text-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
        >
            Bantuan
        </a>
    </x-slot:support>
</x-mk.gate-closed-page>

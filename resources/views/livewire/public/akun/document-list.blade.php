{{--
    resources/views/livewire/public/akun/document-list.blade.php

    App\Livewire\Public\Akun\DocumentList's view — `/akun/dokumen`, Task 3 of
    the `/akun` account area
    (`.superpowers/sdd/2026-08-20-akun-shell-and-drafts/task-3-brief.md`).
    Read that component's doc block first.

    `<x-mk.gate-closed-page>` is the design-system.md §6.4 "Gate closed"
    primitive — see its own doc block. No customer-facing upload path
    exists yet, so both fallback and support send the visitor to
    `/bantuan`.
--}}
<x-mk.gate-closed-page heading="Dokumen belum tersedia" icon="document-text">
    Belum ada jalur unggah dokumen untuk pelanggan saat ini.

    <x-slot:fallback>
        <x-mk.button href="{{ route('bantuan.index') }}">Bantuan</x-mk.button>
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

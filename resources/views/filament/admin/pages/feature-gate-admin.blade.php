{{--
    resources/views/filament/admin/pages/feature-gate-admin.blade.php

    View for App\Filament\Admin\Pages\FeatureGateAdmin. Same component
    choice as finance-reports.blade.php / mfa-settings.blade.php: Filament's
    own Blade components and Tailwind classes rather than the public site's
    `<x-mk.*>` primitives, for the identical reason those files record —
    `<x-mk.*>` component files live outside this panel's own Tailwind
    source scan.

    Required states (§6): loading (none — the table is one query on a tiny
    registry), empty (the registry is seeded by migration, but the loop
    renders nothing and the page still renders when no rows exist), success
    (quiet — the table itself), error (the danger notification from
    `transitionGate`, plus the modal's inline hint when the recorder
    refuses), pending (the transition modal), support (the note explaining
    the re-authentication and evidence requirements).

    The per-row Buka/Tutup buttons call `beginTransition()` (server-side:
    which gate + target state) and `$dispatch('open-modal', ...)` (client-
    side: open the Filament modal) in the same click; the modal's confirm
    button passes the page's own Livewire properties back to
    `transitionGate()`. `$activeGateId ?? ''` is defensive: the confirm
    button is only reachable after `beginTransition()` set the id, and a
    null id would fail loudly in the recorder's findOrFail rather than
    silently no-op.
--}}
<x-filament-panels::page>
    <div class="grid gap-y-6">
        <p class="text-sm text-neutral-600">
            Daftar gerbang fitur dan statusnya. Mengubah status memerlukan verifikasi ulang serta referensi bukti dan alasan — perubahan dicatat dan diaudit.
        </p>

        <div class="overflow-x-auto rounded-lg border border-neutral-200">
            <table class="min-w-full divide-y divide-neutral-200 text-sm">
                <thead class="bg-neutral-50 text-left">
                    <tr>
                        <th scope="col" class="px-4 py-2 font-medium text-neutral-800">ID</th>
                        <th scope="col" class="px-4 py-2 font-medium text-neutral-800">Kapabilitas</th>
                        <th scope="col" class="px-4 py-2 font-medium text-neutral-800">Tipe</th>
                        <th scope="col" class="px-4 py-2 font-medium text-neutral-800">Pemilik</th>
                        <th scope="col" class="px-4 py-2 font-medium text-neutral-800">Status</th>
                        <th scope="col" class="px-4 py-2 font-medium text-neutral-800">Referensi bukti</th>
                        <th scope="col" class="px-4 py-2 font-medium text-neutral-800">Berlaku sejak</th>
                        <th scope="col" class="px-4 py-2 font-medium text-neutral-800">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-neutral-200">
                    @php $gates = $this->gates(); @endphp
                    @forelse ($gates as $gate)
                        <tr>
                            <td class="px-4 py-2 font-mono text-neutral-900">{{ $gate->gate_id }}</td>
                            <td class="px-4 py-2 text-neutral-900">{{ $gate->capability }}</td>
                            <td class="px-4 py-2 text-neutral-700">{{ $gate->type }}</td>
                            <td class="px-4 py-2 text-neutral-700">{{ $gate->owner ?? '—' }}</td>
                            <td class="px-4 py-2">
                                @if ($gate->isOpenState())
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-medium text-emerald-700">Terbuka</span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-600">Tertutup</span>
                                @endif
                            </td>
                            <td class="px-4 py-2 font-mono text-neutral-700">{{ $gate->evidence_reference ?? '—' }}</td>
                            <td class="px-4 py-2 text-neutral-700">{{ $gate->effective_at !== null ? $gate->effective_at->format('Y-m-d H:i') : '—' }}</td>
                            <td class="px-4 py-2">
                                @if ($gate->isOpenState())
                                    <x-filament::button
                                        size="sm"
                                        color="gray"
                                        wire:click="beginTransition('{{ $gate->gate_id }}', 'closed')"
                                        x-on:click="$dispatch('open-modal', { id: 'feature-gate-transition' })"
                                    >
                                        Tutup
                                    </x-filament::button>
                                @else
                                    <x-filament::button
                                        size="sm"
                                        color="success"
                                        wire:click="beginTransition('{{ $gate->gate_id }}', 'open')"
                                        x-on:click="$dispatch('open-modal', { id: 'feature-gate-transition' })"
                                    >
                                        Buka
                                    </x-filament::button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-4 py-2 text-neutral-600">
                                Belum ada gerbang fitur terdaftar.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <x-filament::modal
            id="feature-gate-transition"
            :close-by-clicking-away="false"
            width="md"
        >
            <x-slot name="heading">
                Konfirmasi {{ $activeToState === 'open' ? 'pembukaan' : 'penutupan' }} gerbang
            </x-slot>
            <x-slot name="description">
                Gerbang: <span class="font-mono">{{ $activeGateId ?? '—' }}</span>
            </x-slot>

            <div class="grid gap-y-3">
                <div class="grid gap-y-1.5">
                    <label for="feature-gate-evidence" class="text-sm font-medium text-neutral-800">
                        Referensi bukti
                    </label>
                    <textarea
                        id="feature-gate-evidence"
                        wire:model="evidence"
                        class="fi-input w-full"
                        rows="3"
                        placeholder="mis. nomor dokumen persetujuan, tautan bukti"
                    ></textarea>
                    <p class="text-sm text-neutral-600">
                        Wajib diisi — catatan aktivasi tanpa bukti ditolak.
                    </p>
                </div>

                <div class="grid gap-y-1.5">
                    <label for="feature-gate-reason" class="text-sm font-medium text-neutral-800">
                        Alasan
                    </label>
                    <textarea
                        id="feature-gate-reason"
                        wire:model="reason"
                        class="fi-input w-full"
                        rows="3"
                        placeholder="mengapa gerbang ini dibuka/ditutup"
                    ></textarea>
                </div>
            </div>

            <x-slot name="footer">
                <x-filament::button
                    color="gray"
                    wire:click="cancelTransition"
                    x-on:click="$dispatch('close-modal', { id: 'feature-gate-transition' })"
                >
                    Batal
                </x-filament::button>
                <x-filament::button
                    color="primary"
                    wire:click="transitionGate($activeGateId ?? '', $activeToState, $evidence, $reason)"
                >
                    Simpan
                </x-filament::button>
            </x-slot>
        </x-filament::modal>
    </div>
</x-filament-panels::page>

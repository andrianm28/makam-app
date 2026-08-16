{{--
    resources/views/livewire/public/certificates/certificate-status-page.blade.php

    The public /sertifikat/{subjectType}/{subjectId} certificate status page
    (P5a Lane 1, Task 2) — AC6 of .kiro/specs/certificates-and-agreements/
    requirements.md: display issuance status WITHOUT exposing restricted
    source documents.

    The component renders ONLY the CertificateStatusView projection
    (type / status / version / effective_at / issued_by_role). This template
    never reads the certificate model, the vault document reference, or the
    certificate's document number — the entire page is one table over the
    already-projected rows, so a restricted value cannot leak through this
    view even if the projection's key set regressed (the domain test pins
    those keys).

    Status → intent per the kiro design-system task (§3.7): draft neutral,
    issued success, revoked danger, replaced/superseded neutral. Rows are
    newest version first.
--}}

<div class="mx-auto w-full max-w-3xl px-4 py-10">
    <h1 class="text-2xl font-semibold text-neutral-900">Status Sertifikat</h1>
    <p class="mt-2 text-base text-neutral-600">
        Status penerbitan sertifikat untuk subjek ini. Dokumen sumber tidak
        ditampilkan di halaman ini.
    </p>

    <div class="mt-6">
        @forelse ($statusRows as $row)
            <x-mk.card class="mb-3">
                <div class="flex flex-col gap-4">
                    <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="font-medium text-neutral-500">Tipe sertifikat</dt>
                            <dd class="mt-1 font-semibold text-neutral-900">
                                {{ $row['type'] === 'ORDER_SETTLEMENT' ? 'Penyelesaian Pesanan' : $row['type'] }}
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-neutral-500">Status</dt>
                            <dd class="mt-1">
                                @php
                                    $intent = match ($row['status']) {
                                        'issued' => 'success',
                                        'revoked' => 'danger',
                                        'replaced' => 'neutral',
                                        default => 'neutral',
                                    };
                                    $label = match ($row['status']) {
                                        'issued' => 'Terbit',
                                        'revoked' => 'Dicabut',
                                        'replaced' => 'Diganti',
                                        default => 'Draf',
                                    };
                                @endphp
                                <x-mk.badge :intent="$intent" dot>{{ $label }}</x-mk.badge>
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-neutral-500">Versi</dt>
                            <dd class="mt-1 font-mono font-semibold text-neutral-900">v{{ $row['version'] }}</dd>
                        </div>

                        <div>
                            <dt class="font-medium text-neutral-500">Mulai berlaku</dt>
                            <dd class="mt-1 text-neutral-700">
                                {{ $row['effective_at'] !== null ? \Illuminate\Support\Carbon::parse($row['effective_at'])->translatedFormat('j F Y') : '—' }}
                            </dd>
                        </div>

                        <div class="sm:col-span-2">
                            <dt class="font-medium text-neutral-500">Diterbitkan oleh</dt>
                            <dd class="mt-1 text-neutral-700">
                                @if ($row['issued_by_role'] === 'restricted_admin')
                                    Admin terbatas
                                @elseif ($row['issued_by_role'] === 'admin')
                                    Admin
                                @else
                                    {{ $row['issued_by_role'] }}
                                @endif
                            </dd>
                        </div>
                    </dl>
                </div>
            </x-mk.card>
        @empty
            <x-mk.alert intent="info" title="Belum ada sertifikat untuk subjek ini.">
                <p class="text-sm">
                    Status akan muncul setelah sertifikat diterbitkan oleh pengelola.
                    Hubungi
                    <a href="{{ route('bantuan.index') }}" class="font-medium underline underline-offset-2">Bantuan</a>
                    untuk informasi lebih lanjut.
                </p>
            </x-mk.alert>
        @endforelse
    </div>
</div>
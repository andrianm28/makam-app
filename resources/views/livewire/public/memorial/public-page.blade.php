{{--
    resources/views/livewire/public/memorial/public-page.blade.php

    App\Livewire\Public\Memorial\MemorialPublicPage's view — `/m/{token}`,
    the QR resolve page. `.kiro/specs/memorial-and-qr/requirements.md`
    AC3/AC5, design.md's "Sequence — QR resolve, gate-checked", and the
    plan's Task 4 brief.

    ===========================================================================
    THE UNIFORM FAILED STATE IS THE HARD RULE
    ===========================================================================
    Every denial — gate closed, unknown token, revoked token, privacy —
    renders the SAME "Memorial tidak tersedia" state below. This file must
    never branch on which denial happened: the component's `$visible` flag
    is the ONLY branch, and the exception message (which may embed the
    privacy mode) never reaches this view. Do not add a `$denialReason`,
    a debug echo, or a `@if ($privacyMode)` here.

    kiro tasks.md tone constraint applies at maximum strength: no
    celebration, no engagement mechanics, no view counters, no "share"
    nudges, no stock grief imagery. Quiet and respectful.

    The rendered surface is the ALLOWLIST projection (AC3): display name,
    published date, approved content bodies, accepted media — and nothing
    else. The privacy mode is deliberately NOT allowlisted (see
    MemorialPublicProjection's own doc block) and therefore never appears
    on this page, badge included.
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">
        @if (! $visible)
            {{-- The uniform not-visible state — identical for every denial
                 case (AC5): no existence leak, no privacy detail. --}}
            <x-mk.card class="text-center">
                <p class="text-lg font-semibold text-neutral-800">Memorial tidak tersedia</p>
                <p class="mt-2 max-w-prose text-base text-neutral-600">
                    Halaman ini tidak dapat ditampilkan. Jika Anda menerima kode ini dari
                    keluarga, silakan hubungi mereka untuk memastikan kode masih berlaku.
                </p>
            </x-mk.card>
        @else
            <article class="space-y-6">
                <header class="border-b border-neutral-200 pb-4">
                    <h1 class="text-2xl font-semibold text-neutral-900">
                        {{ $projection->displayName ?? 'Almarhum/Almarhumah' }}
                    </h1>

                    @if ($projection->publishedAt !== null)
                        <p class="mt-1 text-sm text-neutral-500">
                            Dibuat {{ $projection->publishedAt->translatedFormat('d F Y') }}
                        </p>
                    @endif
                </header>

                @if (count($projection->approvedContentBodies) === 0
                    && count($projection->acceptedMediaRefs) === 0)
                    <x-mk.card>
                        <p class="text-base text-neutral-600">
                            Belum ada kenangan yang dibagikan. Keluarga dapat menambahkan
                            catatan dan foto kapan saja.
                        </p>
                    </x-mk.card>
                @endif

                {{-- AC6: ONLY approved content renders; pending/rejected/
                     hidden rows never appear (the projection already filters).
                     The loop iterable is hoisted into a plain variable first:
                     the content-survival gate (finding N-14) treats a loop
                     header containing a `->` access as lost copy. --}}
                @php
                    $approvedBodies = $projection->approvedContentBodies;
                @endphp
                @foreach ($approvedBodies as $body)
                    <x-mk.card>
                        <p class="max-w-prose text-base text-neutral-700">{{ $body }}</p>
                    </x-mk.card>
                @endforeach

                {{-- AC3: the allowlisted media refs render as plain items —
                     the vault's private documents are never exposed over a
                     guest route (the internal download seam is
                     auth-gated + signed); a media row is present here only
                     when its vault document was accepted AND a moderator
                     approved the row. Iterable hoisted for the same
                     content-survival reason as above. --}}
                @php
                    $acceptedMediaRefs = $projection->acceptedMediaRefs;
                @endphp
                @if (count($acceptedMediaRefs) > 0)
                    <x-mk.card>
                        <h2 class="text-base font-semibold text-neutral-800">Media kenangan</h2>
                        <ul class="mt-2 space-y-1">
                            @foreach ($acceptedMediaRefs as $index => $ref)
                                <li class="text-sm text-neutral-600">
                                    {{ 'Lampiran kenangan '.($index + 1) }}
                                </li>
                            @endforeach
                        </ul>
                    </x-mk.card>
                @endif

                <p class="text-xs text-neutral-400">
                    Halaman ini hanya dapat diakses melalui kode QR yang sah.
                </p>
            </article>
        @endif
    </div>
</div>

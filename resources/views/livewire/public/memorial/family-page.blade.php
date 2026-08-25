{{--
    resources/views/livewire/public/memorial/family-page.blade.php

    App\Livewire\Public\Memorial\MemorialFamilyPage's view — `/kenangan/{profileId}`,
    the consent-gated family surface. `.kiro/specs/memorial-and-qr/requirements.md`
    AC1/AC2/AC6 and kiro tasks.md's design-system section.

    The privacy-mode indicator is PERSISTENT here (kiro tasks.md: "the
    current privacy mode must be visible at all times. A family editing
    what they believe is a private memorial, which is in fact public, is a
    serious harm"). This is the editor-gated surface, so the mode is known
    to the viewer by consent — the PUBLIC projection page must never show
    it (that page renders only the allowlist).

    Tone: quiet and respectful (kiro tasks.md tone constraint).
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">
        @if (! $visible)
            {{-- Same uniform not-visible state as the public page — no
                 existence leak for cross-family access (AC1/AC8 negative
                 criteria, kiro tasks.md §6.4). --}}
            <x-mk.card class="text-center">
                <p class="text-lg font-semibold text-neutral-800">Memorial tidak tersedia</p>
                <p class="mt-2 max-w-prose text-base text-neutral-600">
                    Halaman ini hanya dapat diakses oleh keluarga yang terdaftar.
                    Jika Anda menerima tautan ini, silakan hubungi keluarga
                    yang mengirimkannya.
                </p>
            </x-mk.card>
        @else
            @php
                $privacyIntent = match ($privacyMode) {
                    'public' => 'info',
                    'unlisted' => 'pending',
                    'family_only' => 'neutral',
                    default => 'neutral',
                };
                $privacyLabel = match ($privacyMode) {
                    'public' => 'Publik — dapat dilihat siapa saja yang memindai kode QR',
                    'unlisted' => 'Tidak terdaftar — hanya pemegang kode QR',
                    'family_only' => 'Keluarga — hanya keluarga terdaftar',
                    default => 'Pribadi — hanya keluarga terdaftar',
                };
            @endphp

            <div class="mb-6">
                <x-mk.badge :intent="$privacyIntent" :dot="true">{{ $privacyLabel }}</x-mk.badge>
            </div>

            @if ($notice !== '')
                <x-mk.alert intent="success" class="mb-6">{{ $notice }}</x-mk.alert>
            @endif

            {{-- ============ Profile identity ============ --}}
            <section class="space-y-4">
                <x-mk.card>
                    <h1 class="text-xl font-semibold text-neutral-900">Nama yang ditampilkan</h1>
                    <p class="mt-1 max-w-prose text-sm text-neutral-600">
                        Nama ini tampil pada halaman kenangan yang dibuka lewat kode QR.
                    </p>
                    <form class="mt-4 space-y-3" wire:submit="updateDisplayName">
                        <x-mk.field
                            label="Nama tampilan"
                            name="displayName"
                            type="text"
                            :value="$displayName"
                            wire:model="displayName"
                        />
                        <x-mk.button type="submit" variant="secondary">Simpan nama</x-mk.button>
                    </form>
                </x-mk.card>
            </section>

            {{-- ============ Content (AC6 — moderation) ============ --}}
            <section class="mt-6 space-y-4">
                <x-mk.card>
                    <h2 class="text-lg font-semibold text-neutral-900">Tulis kenangan</h2>
                    <form class="mt-3 space-y-3" wire:submit="submitContent">
                        <x-mk.field
                            label="Catatan"
                            name="body"
                            type="textarea"
                            :value="$body"
                            wire:model="body"
                            hint="Catatan diperiksa moderator sebelum tampil di halaman publik."
                        />
                        <x-mk.button type="submit">Kirim catatan</x-mk.button>
                    </form>
                </x-mk.card>

                @if (count($contents) > 0)
                    <div class="space-y-3">
                        @foreach ($contents as $content)
                            <x-mk.card>
                                <p class="max-w-prose text-base text-neutral-700">{{ $content->body }}</p>
                                <p class="mt-2 text-xs text-neutral-500">
                                    @php
                                        $stateLabel = match ($content->moderation_state) {
                                            'approved' => 'Tampil di halaman publik',
                                            'rejected' => 'Ditolak moderator',
                                            'hidden' => 'Disembunyikan moderator',
                                            default => 'Menunggu moderasi',
                                        };
                                    @endphp
                                    {{ $stateLabel }}
                                </p>
                            </x-mk.card>
                        @endforeach
                    </div>
                @endif
            </section>

            {{-- ============ Media (quarantine-first lifecycle) ============ --}}
            <section class="mt-6 space-y-4">
                <x-mk.card>
                    <h2 class="text-lg font-semibold text-neutral-900">Foto kenangan</h2>
                    <p class="mt-1 max-w-prose text-sm text-neutral-600">
                        File melalui pemindaian keamanan sebelum digunakan; tidak ada
                        yang tampil sebelum pemindaian selesai.
                    </p>
                    <form class="mt-3 space-y-3" wire:submit="uploadMedia">
                        <x-mk.field
                            label="Pilih foto (JPG/PNG, maks. 10 MB)"
                            name="mediaFile"
                            type="file"
                            wire:model="mediaFile"
                        />
                        <x-mk.button type="submit" variant="secondary">Unggah foto</x-mk.button>
                    </form>
                </x-mk.card>

                @if (count($pendingUploads) > 0)
                    <x-mk.card>
                        <h3 class="text-base font-semibold text-neutral-800">Menunggu pemindaian</h3>
                        <ul class="mt-2 space-y-1">
                            @foreach ($pendingUploads as $upload)
                                <li class="text-sm text-neutral-600">{{ $upload->original_filename }}</li>
                            @endforeach
                        </ul>
                    </x-mk.card>
                @endif

                @if (count($media) > 0)
                    <x-mk.card>
                        <h3 class="text-base font-semibold text-neutral-800">Media terpasang</h3>
                        <ul class="mt-2 space-y-1">
                            @foreach ($media as $item)
                                <li class="text-sm text-neutral-600">
                                    @php
                                        $mediaState = match ($item->moderation_state) {
                                            'approved' => 'Tampil di halaman publik',
                                            'rejected' => 'Ditolak moderator',
                                            'hidden' => 'Disembunyikan moderator',
                                            default => 'Menunggu moderasi',
                                        };
                                    @endphp
                                    {{ 'Lampiran kenangan — '.$mediaState }}
                                </li>
                            @endforeach
                        </ul>
                    </x-mk.card>
                @endif
            </section>

            {{-- ============ QR token (AC4/AC5) ============ --}}
            <section class="mt-6">
                <x-mk.card>
                    <h2 class="text-lg font-semibold text-neutral-900">Kode QR kunjungan</h2>
                    <p class="mt-1 max-w-prose text-sm text-neutral-600">
                        Dicetak dan ditempatkan di makam; memindainya membuka halaman
                        kenangan sesuai pengaturan privasi.
                    </p>

                    @if ($qrSvg !== null)
                        <div class="mt-4 inline-block rounded-lg border border-neutral-200 bg-white p-4">
                            {!! $qrSvg !!}
                        </div>
                    @else
                        <p class="mt-4 text-sm text-neutral-600">Belum ada kode QR yang diterbitkan.</p>
                    @endif

                    @if ($activeToken !== null)
                        <p class="mt-3 break-all font-mono text-xs text-neutral-500">{{ $activeToken->token }}</p>
                    @endif

                    <form class="mt-4" wire:submit="rotateToken">
                        <x-mk.button
                            type="submit"
                            variant="danger"
                            title="Kode QR lama langsung berhenti berlaku"
                        >
                            Terbitkan kode QR baru
                        </x-mk.button>
                    </form>
                    <p class="mt-2 max-w-prose text-sm text-neutral-500">
                        Menerbitkan kode baru membuat kode yang sudah dicetak tidak
                        lagi berlaku.
                    </p>
                </x-mk.card>
            </section>

            {{-- ============ Privacy selector (AC2) ============ --}}
            <section class="mt-6">
                <x-mk.card>
                    <h2 class="text-lg font-semibold text-neutral-900">Privasi</h2>
                    <p class="mt-1 max-w-prose text-sm text-neutral-600">
                        Setiap pilihan menegaskan konsekuensinya. Pilihan ini berlaku
                        langsung untuk siapa yang dapat melihat halaman kenangan.
                    </p>

                    <form class="mt-4 space-y-3" wire:submit="changePrivacy">
                        <x-mk.field
                            label="Keluarga saja"
                            name="privacyMode"
                            id="privacy-family"
                            type="radio"
                            value="family_only"
                            :checked="$privacyMode === 'family_only'"
                            hint="Hanya keluarga terdaftar yang dapat melihat, meski memegang kode QR."
                            wire:model="privacyMode"
                        />
                        <x-mk.field
                            label="Tidak terdaftar"
                            name="privacyMode"
                            id="privacy-unlisted"
                            type="radio"
                            value="unlisted"
                            :checked="$privacyMode === 'unlisted'"
                            hint="Pemegang kode QR dapat melihat; halaman tidak muncul di pencarian mana pun."
                            wire:model="privacyMode"
                        />
                        <x-mk.field
                            label="Publik"
                            name="privacyMode"
                            id="privacy-public"
                            type="radio"
                            value="public"
                            :checked="$privacyMode === 'public'"
                            hint="Dapat dilihat siapa saja yang memindai kode QR."
                            wire:model="privacyMode"
                        />

                        <x-mk.button type="submit" variant="secondary">Simpan privasi</x-mk.button>
                    </form>
                </x-mk.card>
            </section>
        @endif
    </div>
</div>

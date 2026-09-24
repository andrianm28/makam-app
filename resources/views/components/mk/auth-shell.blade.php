{{--
    resources/views/components/mk/auth-shell.blade.php

    <x-mk.auth-shell> — the shared page shell for the four public auth
    pages (`/masuk`, `/daftar`, `/lupa-password`, `/reset-password/{token}`),
    FFI-clone-whole-frontend ticket 04. Cloned literally from FFI's real
    `/register` page's card treatment (`/home/ubuntu/fundforindonesia.org/
    src/app/(auth)/register/page.tsx`: a colour-banded primary header fused
    to a white form body, one continuous rounded card) rather than FFI's
    `/login` page's plain centred-header card -- the ticket requires one
    shared shell across all four Makam pages, and FFI itself does not use
    one shell for both of its own two pages, so this is a deliberate pick
    of the stronger, more distinctive of FFI's two real patterns.

    Built entirely on the existing <x-mk.card> primitive's already-
    documented `media` slot ("renders full-bleed above the padded body,
    clipped to the card's own rounded corners") -- no change to
    card.blade.php itself (design-system.md's "foundation retained, not
    rebuilt" rule for this initiative).

    Contrast note: FFI's own register page renders its subtitle at
    `text-white/80` (opacity-reduced) on its primary band. This component
    uses full-opacity `text-neutral-0` for BOTH title and subtitle instead:
    tokens.css's own comment on `--color-primary-600` records white text on
    that colour at 4.57:1 -- a thin pass of the 4.5:1 AA floor -- so any
    opacity reduction below full white would drop under that floor (GATE 1
    itself only checks the fixed pairs verify-contrast.py already lists,
    not this component's own usage, so the risk is a real WCAG failure
    the gate would not catch, not a mechanical GATE 1 failure).
    `text-neutral-0`
    (not Tailwind's built-in `white`) matches every other `mk.*` primitive's
    established convention (see button.blade.php).

    Props:
      title    (string)  Rendered as the page's one <h1>, inside the band.
      subtitle (?string) Optional. Rendered directly below the title,
                          still inside the band, full-opacity white.

    Slot: default -- rendered inside the card's white body, below the band.
    Callers put their own extra copy, alerts, the <form>, and any in-card
    links (e.g. "Belum punya akun? Daftar") here, in that order, exactly as
    the four page views already did before this component existed.

    The `/bantuan` support escape-hatch link (design-system.md §6.10,
    required on every transactional screen) is rendered by THIS component,
    below the card, so all four pages get it identically and no future page
    can accidentally drop it by forgetting to copy-paste the paragraph.
--}}
@props([
    'title' => null,
    'subtitle' => null,
])

<div class="py-section md:py-section-lg">
    <div class="mx-auto max-w-content px-4">
        <div class="mx-auto w-full max-w-md">
            <x-mk.card padding="lg">
                <x-slot:media>
                    <div class="bg-primary-600 px-6 py-6 text-center">
                        <h1 class="text-xl font-bold text-neutral-0 md:text-2xl">
                            {{ $title }}
                        </h1>
                        @if ($subtitle)
                            <p class="mt-2 text-sm text-neutral-0">
                                {{ $subtitle }}
                            </p>
                        @endif
                    </div>
                </x-slot:media>

                {{ $slot }}
            </x-mk.card>

            {{-- §6.10 support escape hatch — required on every
                 transactional screen. Owned here so every auth page gets
                 it identically. --}}
            <p class="mt-10 text-center text-sm text-neutral-600">
                Butuh bantuan?
                <a href="/bantuan" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>.
            </p>
        </div>
    </div>
</div>

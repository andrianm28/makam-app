{{--
    resources/views/livewire/public/akun/draft-list.blade.php

    App\Livewire\Public\Akun\DraftList's view — `/akun/draft`, Task 2 of the
    `/akun` account area (`.superpowers/sdd/2026-08-20-akun-shell-and-drafts/
    task-2-brief.md`). Read that component's doc block first.

    Empty state follows design-system.md §6.2's layout recipe: `flex
    flex-col items-center gap-3 py-12 text-center`, title `text-lg
    font-semibold text-neutral-800`, body `text-base text-neutral-600
    max-w-prose`, then a `secondary` button — same structure
    resources/views/livewire/public/faq/index.blade.php's and
    marketplace/index.blade.php's own empty states already use. §6.2's icon
    is deliberately absent here too, same reasoning as those two files'
    doc comments: OQ-05 (icon set) is still open, and every icon file that
    does exist so far is scoped to a specific documented caller (e.g.
    icon/inbox.blade.php is `StatusIntent`'s glyph for order status
    `MASUK`) rather than a general-purpose empty-state mark.

    Row content per draft (city, cemetery name, service type) reads only
    fields/relations already directly available on `BookingDraft` — no new
    query. `city_code` is rendered as its raw closed-list value (e.g.
    "JAKARTA"): a locale-friendly city label needs `LaunchCityQuery`, a
    real table read the brief's own "before adding a new query" caution
    ruled out for this row; flagged in this batch's report as a follow-up
    worth a product/design decision, not fixed here.
--}}
<div class="mx-auto max-w-content px-4 py-8 md:px-6 lg:px-8">
    <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">Draft Pemesanan</h1>

    @if ($drafts->isEmpty())
        <div class="flex flex-col items-center gap-3 py-12 text-center">
            <h2 class="text-lg font-semibold text-neutral-800">Belum ada draft pemesanan.</h2>
            <p class="max-w-prose text-base text-neutral-600">
                Anda belum memiliki draft pemesanan makam yang tersimpan. Mulai pemesanan baru untuk membuat draft.
            </p>
            <x-mk.button variant="secondary" :href="route('pemesanan-makam.index')" class="mt-2">
                Mulai pemesanan
            </x-mk.button>
        </div>
    @else
        <ul class="mt-8 space-y-4" aria-label="Daftar draft pemesanan">
            @foreach ($drafts as $draft)
                <li>
                    <x-mk.card>
                        <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                            <div>
                                <p class="text-base font-semibold text-neutral-900">
                                    {{ $draft->cemetery?->name ?? 'Belum memilih TPU/TPS' }}
                                </p>
                                <p class="mt-1 text-sm text-neutral-600">
                                    {{ $draft->city_code ?? 'Belum memilih kota' }}
                                    @if ($draft->service_type && \App\Domain\Booking\BookingServiceType::isKnown($draft->service_type))
                                        &middot; {{ \App\Domain\Booking\BookingServiceType::label($draft->service_type) }}
                                    @endif
                                </p>
                                <p class="mt-1 text-sm text-neutral-600">
                                    Langkah {{ $draft->current_step }} dari 9
                                </p>
                                <p class="mt-1 text-sm text-neutral-500">
                                    Diperbarui {{ $draft->updated_at?->translatedFormat('d M Y, H:i') }}
                                </p>
                            </div>

                            <x-mk.button variant="primary" :href="route('pemesanan-makam.draft', ['draftId' => $draft->id])">
                                Lanjutkan
                            </x-mk.button>
                        </div>
                    </x-mk.card>
                </li>
            @endforeach
        </ul>
    @endif
</div>

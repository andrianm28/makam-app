{{--
    resources/views/payment/return.blade.php

    The page App\Platform\Payment\Http\Controllers\PaymentReturnController
    renders at /pembayaran/kembali (ADR-0033's `success_return_url`).
    Read that controller's doc block first — it records why this screen
    knows nothing about the visitor's payment and must not pretend to.

    --- The copy is the safety property here ---
    AGENTS.md §Domain and financial invariants: "Never mark paid from
    browser return URL." A page that reads "Pembayaran berhasil" on the
    strength of a redirect has marked paid from a return URL in the only
    way that matters to the person reading it, whatever the database says.
    So every sentence below is about the PROCESS ("kami menunggu
    konfirmasi resmi dari penyedia pembayaran"), never about the outcome,
    and PaymentReturnRouteTest asserts the absence of the four success
    claims that would break that.

    No query parameter is read, passed in, or echoed. There is no
    `$status`, no `$orderId`, no `request()->query(...)` — see the
    controller for why reflecting an attacker-supplied value would be
    both an injection surface and a false confirmation.

    --- Which of design-system.md §6's ten states are reachable ---
    Two, and neither is interactive. This is the "pending" state by its
    whole nature (the outcome is not known here and will not become known
    on this page), and §6.10's support escape hatch is present as an
    ordinary <a href> to /bantuan. Loading, validation error, empty,
    success, and provider-unavailable have nothing to attach to: there is
    no form, no wire:action, no query, and no domain read. With JS
    disabled this page is byte-for-byte the same page. Do NOT "complete
    the states" by adding a status poll — that would be the browser
    asserting a payment outcome, which is precisely the thing AC4 forbids.

    Structural precedent: resources/views/livewire/public/support/
    help-centre.blade.php — max-w-content page gutter, max-w-prose body
    column, text-3xl h1, <section aria-labelledby> per block. Every
    colour, spacing and radius below is an ordinary Tailwind utility
    backed by a Layer 1 token in tokens.css (design-system.md §1.1/§8.2)
    — no hex, no arbitrary value, per §9.2 MUST NOT 1 and 2.
--}}
@component('layouts.app', ['title' => 'Status Pembayaran — Makam.co.id'])
    <div class="py-8 md:py-12">
        <div class="mx-auto max-w-content px-4">
            <article class="mx-auto max-w-prose">
                <header class="mb-6 space-y-2">
                    <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">Status Pembayaran</h1>
                    <p class="text-base text-neutral-700">
                        Anda telah kembali dari halaman pembayaran. Halaman ini belum dapat memastikan hasil transaksi Anda.
                    </p>
                </header>

                <x-mk.alert intent="info" title="Menunggu konfirmasi penyedia pembayaran">
                    Status pembayaran hanya kami perbarui setelah menerima konfirmasi resmi dari penyedia pembayaran. Konfirmasi dari halaman ini, dari alamat web, atau dari tautan yang Anda buka tidak pernah kami gunakan sebagai bukti pembayaran.
                </x-mk.alert>

                <div class="mt-8 space-y-8 text-base text-neutral-700">
                    <section aria-labelledby="langkah-berikutnya" class="space-y-3">
                        <h2 id="langkah-berikutnya" class="text-2xl font-semibold text-neutral-900">Langkah berikutnya</h2>
                        <ul class="list-disc space-y-2 pl-5">
                            <li>Simpan bukti pembayaran dari penyedia pembayaran Anda.</li>
                            <li>Kami akan memperbarui status pesanan Anda setelah konfirmasi resmi kami terima.</li>
                            <li>Jangan melakukan pembayaran ulang untuk transaksi yang sama sebelum status diperbarui.</li>
                        </ul>
                    </section>

                    <section aria-labelledby="butuh-bantuan" class="space-y-3">
                        <h2 id="butuh-bantuan" class="text-2xl font-semibold text-neutral-900">Butuh bantuan?</h2>
                        <p>
                            Jika status tidak berubah dalam waktu yang wajar, hubungi kami melalui
                            <a href="{{ route('bantuan.index') }}" class="font-medium text-primary-700 underline underline-offset-2 hover:text-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-300 focus-visible:ring-offset-2">halaman Bantuan</a>.
                        </p>
                    </section>
                </div>
            </article>
        </div>
    </div>
@endcomponent

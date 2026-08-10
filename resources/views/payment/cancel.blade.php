{{--
    resources/views/payment/cancel.blade.php

    The page App\Platform\Payment\Http\Controllers\PaymentCancelController
    renders at /pembayaran/batal (ADR-0033's `cancel_return_url`). Read
    that controller's doc block, and PaymentReturnController's, first.

    --- Why this page is as careful as the success one ---
    The risk is symmetric. A visitor who DID pay can land here — a back
    button, a mis-configured provider redirect, an attacker-crafted link —
    while the webhook that settles the payment is still in flight. So this
    page must not tell them the payment failed, was cancelled, or was
    released, any more than the other page may tell them it succeeded. It
    describes only what the visitor themselves did (returned without
    completing the payment) and states plainly that nothing was changed.

    No query parameter is read, passed in, or echoed here either.

    --- Which of design-system.md §6's ten states are reachable ---
    Same answer as return.blade.php, and for the same reasons: an
    inherently non-interactive page with no form, no wire:action, no query
    and no domain read. §6.10's support escape hatch is the /bantuan link.

    Same token discipline as return.blade.php — ordinary Tailwind
    utilities backed by tokens.css, no hex, no arbitrary value.
--}}
@component('layouts.app', ['title' => 'Pembayaran Tidak Diselesaikan — Makam.co.id'])
    <div class="py-8 md:py-12">
        <div class="mx-auto max-w-content px-4">
            <article class="mx-auto max-w-prose">
                <header class="mb-6 space-y-2">
                    <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">Pembayaran Tidak Diselesaikan</h1>
                    <p class="text-base text-neutral-700">
                        Anda kembali dari halaman pembayaran tanpa menyelesaikan transaksi.
                    </p>
                </header>

                <x-mk.alert intent="info" title="Tidak ada perubahan yang kami lakukan">
                    Halaman ini tidak mengubah status pesanan maupun status pembayaran Anda. Jika Anda sebenarnya sudah menyelesaikan pembayaran, status akan diperbarui setelah kami menerima konfirmasi resmi dari penyedia pembayaran.
                </x-mk.alert>

                <div class="mt-8 space-y-8 text-base text-neutral-700">
                    <section aria-labelledby="langkah-berikutnya" class="space-y-3">
                        <h2 id="langkah-berikutnya" class="text-2xl font-semibold text-neutral-900">Langkah berikutnya</h2>
                        <ul class="list-disc space-y-2 pl-5">
                            <li>Data yang sudah Anda isi tidak dihapus oleh halaman ini.</li>
                            <li>Anda dapat mengulangi proses pembayaran dari halaman pesanan Anda.</li>
                            <li>Jika Anda sudah membayar, tunggu konfirmasi kami sebelum membayar kembali.</li>
                        </ul>
                    </section>

                    <section aria-labelledby="butuh-bantuan" class="space-y-3">
                        <h2 id="butuh-bantuan" class="text-2xl font-semibold text-neutral-900">Butuh bantuan?</h2>
                        <p>
                            Hubungi kami melalui
                            <a href="{{ route('bantuan.index') }}" class="touch-target font-medium text-primary-700 underline underline-offset-2 hover:text-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2">halaman Bantuan</a>
                            jika Anda memerlukan penjelasan mengenai status transaksi Anda.
                        </p>
                    </section>
                </div>
            </article>
        </div>
    </div>
@endcomponent

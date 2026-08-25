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
    released, any more than the other page may tell them it succeeded.

    Like return.blade.php, the copy branches only on `$returnState` — a
    `ReturnPageState` value object decided by the payment session row's own
    webhook-written value, never by a query parameter. The default (no
    session found, or a session still open) describes only what the visitor
    themselves did (returned without completing the payment) and states
    plainly that nothing was changed.

    No query parameter is echoed here either.

    --- Which of design-system.md §6's ten states are reachable ---
    Same answer as return.blade.php, and for the same reasons: an
    inherently non-interactive page with no form, no wire:action, no query
    and no domain write. §6.10's support escape hatch is the /bantuan link.

    Same token discipline as return.blade.php — ordinary Tailwind
    utilities backed by tokens.css, no hex, no arbitrary value.
--}}
@php
    use App\Platform\Payment\ReturnPageState;

    $returnState = $returnState ?? ReturnPageState::PENDING;
@endphp
@component('layouts.app', ['title' => 'Status Pembayaran — Makam.co.id'])
    <div class="py-8 md:py-12">
        <div class="mx-auto max-w-content px-4">
            <article class="mx-auto max-w-prose">
                <header class="mb-6 space-y-2">
                    <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">Status Pembayaran</h1>
                    <p class="text-base text-neutral-700">
                        @if ($returnState->is(ReturnPageState::PAID))
                            Konfirmasi pembayaran Anda sudah kami terima.
                        @elseif ($returnState->is(ReturnPageState::FAILED) || $returnState->is(ReturnPageState::EXPIRED))
                            Transaksi pembayaran Anda tidak selesai.
                        @else
                            Anda telah kembali dari halaman pembayaran. Halaman ini belum dapat memastikan hasil transaksi Anda.
                        @endif
                    </p>
                </header>

                @if ($returnState->is(ReturnPageState::PAID))
                    <x-mk.alert intent="success" title="Pembayaran Anda telah kami terima">
                        Konfirmasi resmi dari penyedia pembayaran sudah kami terima dan kami perbarui sesuai catatan kami. Status pesanan Anda akan diperbarui setelah pemrosesan selesai.
                    </x-mk.alert>
                @elseif ($returnState->is(ReturnPageState::FAILED))
                    <x-mk.alert intent="danger" title="Transaksi pembayaran tidak berhasil">
                        Tidak ada pembayaran yang kami catat untuk transaksi ini. Anda tidak dikenakan biaya apa pun. Anda dapat mencoba kembali dari halaman pemesanan Anda, atau gunakan pembayaran manual.
                    </x-mk.alert>
                @elseif ($returnState->is(ReturnPageState::EXPIRED))
                    <x-mk.alert intent="neutral" title="Sesi pembayaran telah kedaluwarsa">
                        Sesi pembayaran Anda telah kedaluwarsa tanpa pembayaran yang kami terima. Anda dapat mencoba kembali dari halaman pemesanan Anda, atau gunakan pembayaran manual.
                    </x-mk.alert>
                @else
                    <x-mk.alert intent="info" title="Menunggu konfirmasi penyedia pembayaran">
                        Status pembayaran hanya kami perbarui setelah menerima konfirmasi resmi dari penyedia pembayaran. Konfirmasi dari halaman ini, dari alamat web, atau dari tautan yang Anda buka tidak pernah kami gunakan sebagai bukti pembayaran.
                    </x-mk.alert>
                @endif

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

{{--
    resources/views/payment/return.blade.php

    The page App\Platform\Payment\Http\Controllers\PaymentReturnController
    renders at /pembayaran/kembali (ADR-0033's `success_return_url`).
    Read that controller's doc block first — it records why this screen
    trusts nothing the visitor presents.

    --- The copy is the safety property here ---
    AGENTS.md §Domain and financial invariants: "Never mark paid from
    browser return URL." A page that reads "Pembayaran berhasil" on the
    strength of a redirect has marked paid from a return URL in the only
    way that matters to the person reading it, whatever the database says.
    The state below comes ONLY from `$returnState` — a `ReturnPageState`
    value object whose state was decided by the payment session row's own
    webhook-written value, never by a query parameter. The default (no
    session found, or a session still open) is the pending page that
    describes the process without claiming an outcome.

    No query parameter is echoed. There is no `$status`, no `$orderId`,
    no `request()->query(...)` in this template.

    --- Which of design-system.md §6's ten states are reachable ---
    Pending (default), success, and failure/expiry — but ONLY as display
    states of what the webhook already recorded; this page has no form, no
    wire:action, and no domain write, and it must never gain any (the
    structural test in PaymentReturnRouteTest fails if a write-shaped name
    appears in the controller). §6.10's support escape hatch is present on
    every branch as an ordinary <a href> to /bantuan. Loading, empty,
    validation error, and provider-unavailable have nothing to attach to:
    there is no form and no query-trusted data. Do NOT "complete the
    states" by adding a status poll — that would be the browser asserting
    a payment outcome, which is precisely the thing AC4 forbids.
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

                    <div class="mt-8 space-y-8 text-base text-neutral-700">
                        <section aria-labelledby="langkah-berikutnya" class="space-y-3">
                            <h2 id="langkah-berikutnya" class="text-2xl font-semibold text-neutral-900">Langkah berikutnya</h2>
                            <ul class="list-disc space-y-2 pl-5">
                                <li>Simpan bukti pembayaran dari penyedia pembayaran Anda.</li>
                                <li>Status pesanan Anda akan kami perbarui setelah pemrosesan selesai.</li>
                                <li>Jangan melakukan pembayaran ulang untuk transaksi yang sama.</li>
                            </ul>
                        </section>
                    </div>
                @elseif ($returnState->is(ReturnPageState::FAILED))
                    <x-mk.alert intent="danger" title="Transaksi pembayaran tidak berhasil">
                        Tidak ada pembayaran yang kami catat untuk transaksi ini. Anda tidak dikenakan biaya apa pun. Anda dapat mencoba kembali dari halaman pemesanan Anda, atau gunakan pembayaran manual.
                    </x-mk.alert>

                    <div class="mt-8 space-y-8 text-base text-neutral-700">
                        <section aria-labelledby="langkah-berikutnya" class="space-y-3">
                            <h2 id="langkah-berikutnya" class="text-2xl font-semibold text-neutral-900">Langkah berikutnya</h2>
                            <ul class="list-disc space-y-2 pl-5">
                                <li>Kembali ke halaman pemesanan Anda untuk mencoba lagi.</li>
                                <li>Atau gunakan pembayaran manual sesuai instruksi tim kami.</li>
                                <li>Hubungi kami bila Anda sudah pernah melakukan pembayaran.</li>
                            </ul>
                        </section>
                    </div>
                @elseif ($returnState->is(ReturnPageState::EXPIRED))
                    <x-mk.alert intent="neutral" title="Sesi pembayaran telah kedaluwarsa">
                        Sesi pembayaran Anda telah kedaluwarsa tanpa pembayaran yang kami terima. Anda dapat mencoba kembali dari halaman pemesanan Anda, atau gunakan pembayaran manual.
                    </x-mk.alert>

                    <div class="mt-8 space-y-8 text-base text-neutral-700">
                        <section aria-labelledby="langkah-berikutnya" class="space-y-3">
                            <h2 id="langkah-berikutnya" class="text-2xl font-semibold text-neutral-900">Langkah berikutnya</h2>
                            <ul class="list-disc space-y-2 pl-5">
                                <li>Kembali ke halaman pemesanan Anda untuk membuka sesi pembayaran baru.</li>
                                <li>Atau gunakan pembayaran manual sesuai instruksi tim kami.</li>
                            </ul>
                        </section>
                    </div>
                @else
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
                    </div>
                @endif

                <div class="mt-8 space-y-8 text-base text-neutral-700">
                    <section aria-labelledby="butuh-bantuan" class="space-y-3">
                        <h2 id="butuh-bantuan" class="text-2xl font-semibold text-neutral-900">Butuh bantuan?</h2>
                        <p>
                            Jika status tidak berubah dalam waktu yang wajar, hubungi kami melalui
                            <a href="{{ route('bantuan.index') }}" class="touch-target font-medium text-primary-700 underline underline-offset-2 hover:text-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2">halaman Bantuan</a>.
                        </p>
                    </section>
                </div>
            </article>
        </div>
    </div>
@endcomponent

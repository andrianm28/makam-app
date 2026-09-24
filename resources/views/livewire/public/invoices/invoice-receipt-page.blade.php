{{--
    resources/views/livewire/public/invoices/invoice-receipt-page.blade.php

    The public /kwitansi/{reference} receipt page — see
    App\Livewire\Public\Invoices\InvoiceReceiptPage's own doc block. Renders
    only the invoice's own fields plus the order's human-facing reference;
    nothing restricted is available to this template in the first place.

    FFI-clone-whole-frontend ticket 09 — page shell (container/heading
    classes) matches the same py-section/max-w-content/max-w-prose-scale
    convention as help-centre.blade.php/faq/index.blade.php, rather than
    this file's previous narrower, smaller-heading shell. The receipt
    card itself keeps its original max-w-2xl reading width, now nested
    inside the wider page shell instead of constraining the whole page.
--}}

<div class="py-section md:py-section-lg">
    <div class="mx-auto max-w-content px-4">
        <div class="mx-auto w-full max-w-2xl">
            <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">Kwitansi Pembayaran</h1>
            <p class="mt-2 text-base text-neutral-600">
                Bukti penerimaan pembayaran untuk pesanan Anda.
            </p>

            <x-mk.card class="mt-6">
                <div class="flex flex-col gap-6">
                    <dl class="grid grid-cols-1 gap-4 text-sm sm:grid-cols-2">
                        <div>
                            <dt class="font-medium text-neutral-500">Nomor kwitansi</dt>
                            <dd class="mt-1 font-mono font-semibold text-neutral-900">{{ $invoice->reference }}</dd>
                        </div>

                        <div>
                            <dt class="font-medium text-neutral-500">Nomor pesanan</dt>
                            <dd class="mt-1 font-mono font-semibold text-neutral-900">
                                {{ $order?->reference ?? '—' }}
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-neutral-500">Tanggal diterbitkan</dt>
                            <dd class="mt-1 text-neutral-700">
                                <x-mk.local-time :at="$invoice->issued_at" />
                            </dd>
                        </div>

                        <div>
                            <dt class="font-medium text-neutral-500">Status</dt>
                            <dd class="mt-1">
                                <x-mk.badge intent="success" dot>Lunas</x-mk.badge>
                            </dd>
                        </div>

                        <div class="sm:col-span-2">
                            <dt class="font-medium text-neutral-500">Rincian</dt>
                            <dd class="mt-1 text-neutral-700">{{ $invoice->summary }}</dd>
                        </div>

                        <div class="sm:col-span-2">
                            <dt class="font-medium text-neutral-500">Jumlah dibayar</dt>
                            <dd class="mt-1 text-xl font-semibold text-neutral-900">
                                {{ (new \App\Platform\FinancialLedger\Money((int) $invoice->amount_minor))->format() }}
                            </dd>
                        </div>
                    </dl>
                </div>
            </x-mk.card>

            <p class="mt-6 text-sm text-neutral-500">
                Ada pertanyaan tentang kwitansi ini?
                <a href="{{ route('bantuan.index') }}" class="font-medium underline underline-offset-2">Hubungi Bantuan</a>.
            </p>
        </div>
    </div>
</div>

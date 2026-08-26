{{--
    resources/views/livewire/public/invoices/invoice-receipt-page.blade.php

    The public /kwitansi/{reference} receipt page — see
    App\Livewire\Public\Invoices\InvoiceReceiptPage's own doc block. Renders
    only the invoice's own fields plus the order's human-facing reference;
    nothing restricted is available to this template in the first place.
--}}

<div class="mx-auto w-full max-w-2xl px-4 py-10">
    <h1 class="text-2xl font-semibold text-neutral-900">Kwitansi Pembayaran</h1>
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
                        {{ $invoice->issued_at->translatedFormat('j F Y, H:i') }}
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

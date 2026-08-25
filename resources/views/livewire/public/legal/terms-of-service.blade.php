{{--
    resources/views/livewire/public/legal/terms-of-service.blade.php

    App\Livewire\Public\Legal\TermsOfService's view — `/syarat-ketentuan`.
    Same structural precedent as privacy-policy.blade.php (its sibling in
    this same directory) — see that file's own doc block for the
    --container-prose / heading-scale / N-14 reasoning, not repeated here.

    Same honesty discipline as its sibling: the "Terakhir diperbarui" line
    below carries the draft notice and must stay while $legalReviewNote is
    null (App\Support\LegalReviewStatus::note()) — see privacy-policy.blade.php's
    own doc block for how an operator supersedes it via the admin Site
    Settings page. Section 3 (Kebijakan Pembayaran) deliberately does not
    state a specific refund percentage or timeline — see App\Livewire\
    Public\Legal\TermsOfService's own doc block for why
    (assumptions-and-gates.md §5 item 8 is a real open decision, not an
    oversight here).
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">
        <article class="mx-auto max-w-prose">
            <header class="mb-6 space-y-2">
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">Syarat &amp; Ketentuan</h1>
                <p class="text-sm text-neutral-600">
                    @if ($legalReviewNote !== null)
                        Terakhir diperbarui {{ $updatedAt }}. {{ $legalReviewNote }}
                    @else
                        Terakhir diperbarui {{ $updatedAt }}. Dokumen ini adalah draf awal dan akan diperbarui setelah tinjauan hukum resmi.
                    @endif
                </p>
                @if ($companyNib !== null)
                    <p class="text-sm text-neutral-600">NIB: {{ $companyNib }}</p>
                @endif
            </header>

            <div class="space-y-8 text-base text-neutral-700">
                <section aria-labelledby="syarat-definisi">
                    <h2 id="syarat-definisi" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">1. Definisi Layanan</h2>
                    <p class="mb-3">Dalam Syarat &amp; Ketentuan ini:</p>
                    <ul class="list-disc space-y-2 pl-6">
                        <li><strong>"Platform"</strong> berarti situs dan layanan Makam.co.id yang dioperasikan oleh {{ $companyName }}.</li>
                        <li><strong>"Pengguna"/"Anda"</strong> berarti setiap orang yang mengakses atau menggunakan Platform.</li>
                        <li><strong>"Layanan"</strong> berarti empat layanan utama yang tersedia di Platform: Pemesanan Makam, Layanan Pemakaman, Perpanjangan Makam, dan FAQ/dukungan pelanggan.</li>
                        <li><strong>"Vendor"</strong> berarti penyedia layanan pemakaman pihak ketiga yang terdaftar pada Layanan Pemakaman di Platform.</li>
                        <li><strong>"Pengelola Makam"</strong> berarti pihak yang mengelola tempat pemakaman umum (TPU) atau tempat pemakaman swasta (TPS) yang bekerja sama dengan Platform.</li>
                    </ul>
                </section>

                <section aria-labelledby="syarat-pemesanan">
                    <h2 id="syarat-pemesanan" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">2. Syarat Pemesanan</h2>
                    <p>Saat membuat pemesanan, Anda wajib memberikan data identitas pemesan dan data almarhum/almarhumah yang akurat, serta dokumen pendukung (seperti KTP, KK, dan surat keterangan kematian) sesuai yang diminta pada alur pemesanan. Pemesanan diproses setelah data dan dokumen yang diperlukan diterima dan diverifikasi. Platform berhak menolak atau menunda pemesanan yang datanya tidak lengkap, tidak akurat, atau tidak dapat diverifikasi.</p>
                </section>

                <section aria-labelledby="syarat-pembayaran">
                    <h2 id="syarat-pembayaran" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">3. Kebijakan Pembayaran</h2>
                    <p>Platform mendukung pembayaran daring maupun proses konfirmasi manual apabila metode pembayaran daring sedang tidak tersedia untuk suatu Layanan. Ketentuan rinci mengenai pengembalian dana (refund), termasuk persentase, jangka waktu, dan kondisi yang berlaku, <strong>masih dalam proses finalisasi</strong> dan belum ditetapkan. Ketentuan tersebut akan dipublikasikan pada halaman ini setelah diputuskan secara resmi. Sampai saat itu, setiap permintaan terkait pembayaran atau pengembalian dana akan ditangani secara manual melalui tim Bantuan kami berdasarkan kasus per kasus.</p>
                </section>

                <section aria-labelledby="syarat-kewajiban-pengguna">
                    <h2 id="syarat-kewajiban-pengguna" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">4. Kewajiban Pengguna</h2>
                    <p class="mb-3">Sebagai Pengguna, Anda wajib:</p>
                    <ul class="list-disc space-y-2 pl-6">
                        <li>memberikan data dan dokumen yang benar, lengkap, dan bukan milik orang lain tanpa hak;</li>
                        <li>menggunakan Platform untuk tujuan yang sah dan sesuai peruntukannya;</li>
                        <li>menjaga kerahasiaan kredensial akun Anda, jika Platform mengharuskan akun untuk suatu Layanan; dan</li>
                        <li>mematuhi ketentuan yang berlaku dari Pengelola Makam atau Vendor terkait, sepanjang tidak bertentangan dengan Syarat &amp; Ketentuan ini.</li>
                    </ul>
                </section>

                <section aria-labelledby="syarat-batasan-tanggung-jawab">
                    <h2 id="syarat-batasan-tanggung-jawab" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">5. Batasan Tanggung Jawab Platform</h2>
                    <p>Untuk Layanan yang melibatkan Pengelola Makam atau Vendor pihak ketiga, Platform bertindak sebagai perantara yang memfasilitasi pemesanan dan komunikasi antara Pengguna dan pihak tersebut. Platform berupaya menjaga keandalan proses pemesanan, namun tidak bertanggung jawab atas keterlambatan, kegagalan, atau kekurangan dalam pelaksanaan layanan fisik yang sepenuhnya berada di luar kendali Platform, sepanjang diizinkan oleh hukum yang berlaku. Jika Anda mengalami masalah dengan Layanan, silakan hubungi tim Bantuan kami agar dapat kami bantu tindak lanjuti.</p>
                </section>

                <section aria-labelledby="syarat-hukum-berlaku">
                    <h2 id="syarat-hukum-berlaku" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">6. Hukum yang Berlaku</h2>
                    <p>Syarat &amp; Ketentuan ini diatur dan ditafsirkan berdasarkan hukum Republik Indonesia. Setiap perselisihan yang timbul akan diupayakan terlebih dahulu melalui musyawarah untuk mufakat; apabila tidak tercapai kesepakatan, perselisihan akan diselesaikan melalui pengadilan yang berwenang di wilayah Republik Indonesia.</p>
                </section>

                <section aria-labelledby="syarat-kontak">
                    <h2 id="syarat-kontak" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">7. Kontak</h2>
                    <p>Jika Anda memiliki pertanyaan mengenai Syarat &amp; Ketentuan ini, silakan hubungi tim Bantuan kami.</p>
                    <p class="mt-2 text-sm text-neutral-600">{{ $companyName }}<br>{{ $companyAddress }}</p>
                    <p class="mt-4">
                        <a
                            href="/bantuan"
                            class="inline-flex h-11 select-none items-center justify-center gap-2 rounded-md border border-primary-600 bg-neutral-0 px-4 text-base font-medium text-primary-700 transition-[color,background-color,border-color,box-shadow] duration-fast ease-standard hover:bg-primary-50 active:bg-primary-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
                        >
                            Hubungi Bantuan
                        </a>
                    </p>
                </section>
            </div>
        </article>
    </div>
</div>

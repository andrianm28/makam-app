{{--
    resources/views/livewire/public/legal/privacy-policy.blade.php

    App\Livewire\Public\Legal\PrivacyPolicy's view — `/privasi`. Structural
    precedent: resources/views/livewire/public/faq/article-detail.blade.php
    (--container-prose / max-w-prose for body copy, real h1/h2 heading scale
    already established there — text-3xl for the page h1, text-2xl for each
    h2 — and hand-written links, not <x-mk.button>, same N-14 discipline as
    every other Livewire full-page view in this repo).

    This is placeholder legal content, honestly labelled as such — see
    App\Livewire\Public\Legal\PrivacyPolicy's own doc block for why. The
    "Terakhir diperbarui" line below is not decorative: it is the one
    honesty marker this batch's brief requires to stay regardless of "show
    the full page publicly" — do not remove it when editing this file.

    $legalReviewNote (App\Support\LegalReviewStatus::note()) supersedes the
    draft disclaimer once an operator enters a review confirmation via the
    admin Site Settings page (SiteSettingsForm's "Tinjauan hukum" section)
    — do not delete the draft branch, it is the honest default state.
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">
        <article class="mx-auto max-w-prose">
            <header class="mb-6 space-y-2">
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">Kebijakan Privasi</h1>
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
                <section aria-labelledby="privasi-pendahuluan">
                    <h2 id="privasi-pendahuluan" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">1. Pendahuluan</h2>
                    <p>{{ $companyName }} ("Makam.co.id", "kami") mengelola platform pemesanan dan layanan pemakaman online. Kebijakan Privasi ini menjelaskan data apa yang kami kumpulkan dari Anda sebagai pengguna, bagaimana data tersebut kami gunakan, bagaimana dokumen privat disimpan, berapa lama data disimpan, serta hak Anda atas data pribadi Anda. Dengan menggunakan Makam.co.id, Anda menyetujui pengumpulan dan penggunaan data sebagaimana dijelaskan di halaman ini.</p>
                </section>

                <section aria-labelledby="privasi-data-dikumpulkan">
                    <h2 id="privasi-data-dikumpulkan" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">2. Data yang Kami Kumpulkan</h2>
                    <p class="mb-3">Kami mengumpulkan tiga kategori data utama:</p>
                    <ul class="list-disc space-y-2 pl-6">
                        <li><strong>Data identitas pemesan</strong> &mdash; nama, nomor telepon, alamat e-mail, dan alamat yang Anda berikan saat membuat pemesanan atau menghubungi layanan pelanggan.</li>
                        <li><strong>Data almarhum/almarhumah</strong> &mdash; nama, tanggal lahir dan wafat, serta informasi lain yang diperlukan untuk memproses layanan pemakaman, penguburan, atau perpanjangan makam yang Anda pesan.</li>
                        <li><strong>Dokumen pendukung</strong> &mdash; dokumen seperti KTP, KK, dan surat keterangan kematian yang Anda unggah untuk memverifikasi pemesanan.</li>
                    </ul>
                </section>

                <section aria-labelledby="privasi-penggunaan-data">
                    <h2 id="privasi-penggunaan-data" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">3. Bagaimana Data Digunakan</h2>
                    <p>Data yang kami kumpulkan digunakan untuk memproses pemesanan dan pembayaran Anda, memverifikasi identitas pemesan dan data almarhum untuk keperluan administrasi pemakaman, berkomunikasi dengan Anda mengenai status pemesanan, serta memenuhi kewajiban hukum yang berlaku. Kami tidak menjual data pribadi Anda kepada pihak ketiga untuk kepentingan pemasaran.</p>
                </section>

                <section aria-labelledby="privasi-dokumen-privat">
                    <h2 id="privasi-dokumen-privat" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">4. Penyimpanan Dokumen Privat</h2>
                    <p>Dokumen seperti KTP, KK, dan surat keterangan kematian disimpan secara privat dan diperiksa sebelum dapat diakses siapa pun, termasuk tim kami. Akses ke dokumen ini dibatasi hanya untuk personel yang benar-benar membutuhkannya untuk memverifikasi pemesanan, dan setiap akses tersebut dicatat.</p>
                </section>

                <section aria-labelledby="privasi-retensi-data">
                    <h2 id="privasi-retensi-data" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">5. Berapa Lama Data Disimpan</h2>
                    <p>Kebijakan retensi data secara rinci &mdash; termasuk masa penyimpanan dokumen identitas, dokumen kematian, dan riwayat pemesanan &mdash; masih dalam proses finalisasi bersama tim hukum kami dan akan dipublikasikan di halaman ini setelah ditetapkan. Sampai saat itu, prinsip yang kami terapkan adalah menyimpan data Anda hanya selama diperlukan untuk memenuhi tujuan yang dijelaskan pada bagian 3, atau selama diwajibkan oleh hukum yang berlaku, dan tidak lebih lama dari itu.</p>
                </section>

                <section aria-labelledby="privasi-hak-anda">
                    <h2 id="privasi-hak-anda" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">6. Hak Anda</h2>
                    <p class="mb-3">Anda berhak untuk:</p>
                    <ul class="list-disc space-y-2 pl-6">
                        <li>mengakses data pribadi yang kami simpan tentang Anda;</li>
                        <li>meminta koreksi atas data yang tidak akurat; dan</li>
                        <li>meminta penghapusan data Anda, sepanjang tidak bertentangan dengan kewajiban hukum yang mengharuskan kami menyimpan data tertentu (misalnya untuk keperluan administrasi pemakaman yang sudah selesai).</li>
                    </ul>
                    <p class="mt-3">Untuk mengajukan salah satu permintaan di atas, hubungi kami melalui kontak pada bagian 7.</p>
                </section>

                <section aria-labelledby="privasi-kontak">
                    <h2 id="privasi-kontak" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">7. Kontak</h2>
                    <p>Jika Anda memiliki pertanyaan mengenai kebijakan privasi ini atau ingin menggunakan hak Anda di atas, silakan hubungi tim Bantuan kami.</p>
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

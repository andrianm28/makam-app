{{--
    resources/views/livewire/public/support/help-centre.blade.php

    App\Livewire\Public\Support\HelpCentre's view — `/bantuan`, screen
    PUB-060 ("Help/contact | channels, hours, emergency disclaimer",
    screen-inventory.md §A). Read that component's doc block first: it
    records the defect this closes (the header's persistent Bantuan action
    linked `/bantuan` from every page while no route existed) and why this
    screen performs no domain read.

    Structural precedent: resources/views/livewire/public/legal/
    privacy-policy.blade.php — `max-w-content` page gutter, `max-w-prose`
    body column, `text-3xl` h1 / `text-2xl` h2 heading scale, `<section
    aria-labelledby>` per block. Every colour, spacing, radius, and
    duration below is an ordinary Tailwind utility backed by a Layer 1
    token in tokens.css (design-system.md §1.1/§8.2) — no hex, no
    arbitrary value, per §9.2 MUST NOT 1 and 2.

    Unlike the older hand-written call sites in this repo, the two CTAs at
    the bottom use <x-mk.button>: N-14's `Undefined variable $loading` bug
    is fixed and the primitive was re-verified working on Laravel 13.22.0 +
    Livewire 4.3.3 on 8 Aug 2026, so §9.2 MUST 2 ("use the <x-mk.*>
    primitives, extend rather than fork") applies to new pages again.

    --- Which of §6's ten states are reachable here ---
    None of the interactive ones, and that is deliberate rather than an
    omission. This page has no form, no wire: action, no query, and no
    JS-dependent affordance, so loading (§6.1), validation error (§6.3),
    and provider unavailable (§6.5) have nothing to attach to. §6.10's
    "must work with JS disabled" is met structurally: with scripts off,
    this page is byte-for-byte the same page. Do NOT "add the missing
    states" by introducing a contact form — see the component's doc block
    for why a form with no configured inbox is the worse failure.

    --- Order of the blocks is a safety decision ---
    The emergency disclaimer sits ABOVE the channel list. Someone landing
    here in the first hour after a death should read "this is not an
    emergency service" before they read a phone number, not after. Do not
    move it below the channels for visual balance.

    --- Every contact value is read, never typed ---
    $phone/$email/$businessHours trace to App\Support\ContactInfo and
    $companyName/$companyAddress to App\Support\CompanyInfo, passed in by
    the component. Both classes' doc blocks explain these are marked
    placeholders for the public dev host. The "belum aktif" notice below is
    the visible half of that honesty and must stay: without it this page
    reads as a staffed hotline, which is a promise to a grieving family
    that nothing in this repository can keep.
--}}
<div class="py-8 md:py-12">
    <div class="mx-auto max-w-content px-4">
        <article class="mx-auto max-w-prose">
            <header class="mb-6 space-y-2">
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">Bantuan dan Kontak</h1>
                <p class="text-base text-neutral-700">
                    Halaman ini berisi kanal komunikasi resmi Makam.co.id, jam operasional layanan pelanggan, dan penjelasan mengenai hal-hal yang dapat maupun belum dapat kami bantu. Anda dapat membuka halaman ini kapan saja melalui tombol <strong>Bantuan</strong> yang tersedia di bagian atas setiap halaman.
                </p>
            </header>

            <div class="space-y-8 text-base text-neutral-700">
                {{-- design-system.md §6.10: "must carry the emergency
                     disclaimer on PUB-060". Intent `urgent` (an alias of
                     warning in tokens.css §2.10) rather than `danger` —
                     §2.3's no-alarm rule: this is a standing safety notice,
                     not a failure. `live="off"` because it is present on
                     initial page load and is not a live region (alert
                     component's own §3.8/§7.4 mapping). --}}
                <x-mk.alert
                    intent="urgent"
                    icon="exclamation-triangle"
                    live="off"
                    title="Halaman ini bukan layanan gawat darurat"
                >
                    <p>
                        Jika sedang terjadi keadaan darurat medis atau keselamatan jiwa, jangan menunggu balasan dari halaman ini. Segera hubungi layanan gawat darurat setempat, rumah sakit terdekat, atau aparat yang berwenang di wilayah Anda.
                    </p>
                    <p class="mt-2">
                        Makam.co.id adalah platform pemesanan dan administrasi layanan pemakaman. Kami tidak menyediakan ambulans, penanganan medis, penjemputan jenazah darurat, maupun layanan tanggap darurat lainnya, dan tidak dapat menjamin ada petugas yang membaca pesan Anda saat itu juga.
                    </p>
                </x-mk.alert>

                <section aria-labelledby="bantuan-kanal">
                    <h2 id="bantuan-kanal" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">1. Kanal Komunikasi</h2>
                    <p class="mb-4">
                        Berikut kanal resmi yang kami gunakan untuk melayani pertanyaan mengenai pemesanan, pembayaran, dan perpanjangan makam. Kami tidak memiliki kanal resmi selain yang tercantum di halaman ini.
                    </p>

                    <dl class="space-y-4 rounded-lg border border-neutral-200 bg-neutral-0 p-4 md:p-6">
                        <div>
                            <dt class="text-sm font-medium text-neutral-600">Telepon</dt>
                            <dd class="mt-1">
                                <a
                                    href="{{ $telHref }}"
                                    class="touch-target inline-flex items-center rounded-sm text-base font-medium text-primary-700 underline underline-offset-2 hover:text-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
                                >
                                    {{ $phone }}
                                </a>
                            </dd>
                        </div>

                        {{-- Same number as Telepon, presented as one number
                             reachable two ways — ContactInfo::whatsapp()
                             falls back to phone()'s resolved value when no
                             support_whatsapp setting is configured; its doc
                             block asks call sites to say exactly this
                             instead of restating a second value. No wa.me
                             deep link: see the component's doc block. --}}
                        <div>
                            <dt class="text-sm font-medium text-neutral-600">WhatsApp</dt>
                            <dd class="mt-1 text-base text-neutral-700">
                                Nomor yang sama dengan nomor telepon di atas.
                            </dd>
                        </div>

                        <div>
                            <dt class="text-sm font-medium text-neutral-600">E-mail</dt>
                            <dd class="mt-1">
                                <a
                                    href="mailto:{{ $email }}"
                                    class="touch-target inline-flex items-center rounded-sm text-base font-medium text-primary-700 underline underline-offset-2 hover:text-primary-800 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-600 focus-visible:ring-offset-2"
                                >
                                    {{ $email }}
                                </a>
                            </dd>
                        </div>
                    </dl>

                    {{-- The honesty notice named in this file's doc comment.
                         Placed directly under the channel list, not in a
                         footnote, so it cannot be read separately from the
                         numbers it qualifies. --}}
                    <div class="mt-4">
                        <x-mk.alert intent="info" live="off" title="Kanal di atas belum aktif">
                            Nomor dan alamat e-mail di atas masih merupakan data contoh yang dipasang untuk lingkungan pengembangan situs ini, dan belum terhubung ke tim layanan pelanggan. Panggilan, pesan WhatsApp, atau e-mail yang Anda kirim ke kontak tersebut saat ini belum tentu sampai kepada kami dan belum tentu dibalas. Kontak resmi yang sebenarnya akan dipublikasikan di halaman ini begitu tersedia.
                        </x-mk.alert>
                    </div>
                </section>

                <section aria-labelledby="bantuan-jam">
                    <h2 id="bantuan-jam" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">2. Jam Operasional</h2>
                    <p class="mb-3">
                        Jam layanan pelanggan reguler: <strong>{{ $businessHours }}</strong>.
                    </p>
                    <p class="mb-3">
                        Pertanyaan yang masuk di luar jam tersebut akan ditangani pada jam layanan berikutnya. Kami tidak menjanjikan durasi balasan tertentu, dan kami tidak beroperasi 24 jam.
                    </p>
                    {{-- UrgentMode's doc block: G-OPS-01 is seeded closed and
                         this platform never claims automatic Urgent/At-Need
                         acceptance — only hours and coverage. Stating the
                         reguler hours must not silently imply Urgent
                         coverage, hence this paragraph. --}}
                    <p>
                        Jam di atas berlaku untuk layanan reguler. Untuk layanan Urgent seperti pemakaman pada hari yang sama, jam operasional dan ketersediaan berbeda-beda tergantung lokasi dan pengelola TPU/TPS, dan diperiksa langsung pada saat Anda mengajukan layanan tersebut. Halaman ini tidak dapat memastikan permintaan Urgent Anda diterima.
                    </p>
                </section>

                <section aria-labelledby="bantuan-cakupan">
                    <h2 id="bantuan-cakupan" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">3. Yang Dapat dan Belum Dapat Kami Bantu</h2>
                    <p class="mb-3">Tim layanan pelanggan dapat membantu Anda untuk:</p>
                    <ul class="list-disc space-y-2 pl-6">
                        <li>memahami alur pemesanan makam, layanan pemakaman, dan perpanjangan makam;</li>
                        <li>menelusuri status pesanan, draft, atau pembayaran yang sudah Anda buat;</li>
                        <li>menjelaskan dokumen apa saja yang perlu disiapkan; dan</li>
                        <li>meneruskan kendala Anda kepada pihak terkait, termasuk pengelola TPU/TPS atau vendor.</li>
                    </ul>
                    <p class="mt-4 mb-3">Hal-hal berikut berada di luar kemampuan halaman ini:</p>
                    <ul class="list-disc space-y-2 pl-6">
                        <li>penanganan keadaan darurat, sebagaimana dijelaskan pada bagian paling atas halaman ini;</li>
                        <li>memastikan ketersediaan lokasi, jadwal, atau layanan Urgent tanpa pemeriksaan langsung pada saat pemesanan; dan</li>
                        <li>memberikan kepastian hukum atas dokumen kependudukan atau administrasi pemakaman, yang tetap mengikuti ketentuan pengelola dan pemerintah daerah setempat.</li>
                    </ul>
                </section>

                <section aria-labelledby="bantuan-persiapan">
                    <h2 id="bantuan-persiapan" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">4. Sebelum Menghubungi Kami</h2>
                    {{-- Consistent with the seeded FAQ article
                         `informasi-yang-perlu-disiapkan-saat-menghubungi-
                         bantuan` (faq-catalog.md §Customer Service) — same
                         three items, not a contradicting list. --}}
                    <p class="mb-3">
                        Agar kendala Anda dapat ditelusuri lebih cepat, siapkan lebih dulu:
                    </p>
                    <ul class="list-disc space-y-2 pl-6">
                        <li>nomor pesanan atau kode draft, jika sudah ada;</li>
                        <li>nama pemesan sesuai data yang terdaftar; dan</li>
                        <li>ringkasan singkat mengenai kendala yang Anda alami, beserta bukti transaksi bila kendalanya berkaitan dengan pembayaran.</li>
                    </ul>
                </section>

                <section aria-labelledby="bantuan-lainnya">
                    <h2 id="bantuan-lainnya" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">5. Cara Lain Menemukan Jawaban</h2>
                    <p class="mb-4">
                        Banyak pertanyaan mengenai pemesanan, pembayaran, dan perpanjangan sudah dijawab pada halaman FAQ kami, yang dapat Anda baca kapan saja tanpa menunggu jam layanan.
                    </p>
                    <div class="flex flex-wrap gap-3">
                        <x-mk.button variant="primary" :href="route('faq.index')">
                            Buka Halaman FAQ
                        </x-mk.button>
                        <x-mk.button variant="secondary" :href="route('home')">
                            Kembali ke Beranda
                        </x-mk.button>
                    </div>
                </section>

                <section aria-labelledby="bantuan-penyelenggara">
                    <h2 id="bantuan-penyelenggara" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">6. Penyelenggara Layanan</h2>
                    <p class="text-sm text-neutral-600">{{ $companyName }}<br>{{ $companyAddress }}</p>
                </section>
            </div>
        </article>
    </div>
</div>

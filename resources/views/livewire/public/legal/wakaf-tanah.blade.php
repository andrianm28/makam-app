{{--
    resources/views/livewire/public/legal/wakaf-tanah.blade.php

    App\Livewire\Public\Legal\WakafTanah's view — `/wakaf-tanah`. Structural
    precedent: resources/views/livewire/public/legal/privacy-policy.blade.php
    (same --container-prose / max-w-prose body, same h1/h2 heading scale,
    same hand-written "Hubungi Bantuan" button, same N-14 discipline of not
    using <x-mk.button> on a full-page Livewire view).

    AC12 (`.kiro/specs/public-home-and-navigation/requirements.md`) is
    explicit: purpose, general requirements, the six-step process AS
    INFORMATION, and the help-centre contact channel — and explicitly
    "SHALL NOT present a form, accept an upload, or register interest
    through this page." There is no <form>, no file input, and no
    "ajukan"/"daftar" call-to-action anywhere below — only a plain link to
    /bantuan, identical to how PrivacyPolicy/TermsOfService end.

    The "general requirements" section states plainly that specific
    eligibility/document requirements are still being finalised, rather
    than inventing them — the PRD (docs/product/prd-yiem-2026-09-18.md
    §1/MK-09) names "syarat umum" as a required section but never states
    what those requirements actually are, and land-endowment eligibility
    is exactly the kind of jurisdiction- and religiously-specific content
    this repo's own governance discipline (see TermsOfService's payment
    section, same honesty pattern) says must not be fabricated. This is a
    real, named gap, not an oversight.

    The six steps are the PRD's own process diagram (§8, "Wakaf tanah"
    flowchart), transcribed as an ordered list of information, not as
    form fields: pilih tujuan wakaf, isi data pemilik dan tanah,
    verifikasi dan survei, proses administrasi, penilaian komersial atau
    non komersial, dokumentasi dan serah terima. The PRD is explicit that
    steps 2 through 6 happen through the contact channel and YIEM's
    manual process, never inside this system — the intro paragraph above
    the list says this plainly so a reader doesn't expect to complete
    any of it here.
--}}
<div class="py-section md:py-section-lg">
    <div class="mx-auto max-w-content px-4">
        <article class="mx-auto max-w-prose">
            <header class="mb-6 space-y-2">
                <h1 class="text-3xl font-semibold tracking-tight text-neutral-900">Wakaf Tanah</h1>
                <p class="text-sm text-neutral-600">Halaman informasi. Bukan formulir pengajuan.</p>
            </header>

            <div class="space-y-8 text-base text-neutral-700">
                <section aria-labelledby="wakaf-tujuan">
                    <h2 id="wakaf-tujuan" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">1. Tentang Wakaf Tanah</h2>
                    <p>Wakaf tanah adalah tanah yang diwakafkan untuk kepentingan pemakaman sosial atau keluarga. Di Makam.co.id, wakaf tanah bersifat non komersial: tidak ada biaya administrasi yang dikomersialkan dari proses ini, dan tidak ada pengalihan atau jual-beli hak tanah yang dijalankan melalui sistem kami. Halaman ini menjelaskan tujuan program dan gambaran prosesnya; pengajuan sesungguhnya berjalan lewat kanal kontak pada bagian 3, bukan lewat formulir di halaman ini.</p>
                </section>

                <section aria-labelledby="wakaf-syarat">
                    <h2 id="wakaf-syarat" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">2. Syarat Umum</h2>
                    <p>Syarat kelayakan dan dokumen yang rinci masih dalam proses finalisasi bersama tim kami dan akan dipublikasikan di halaman ini setelah ditetapkan. Sampai saat itu, calon wakif yang berminat dapat menghubungi tim Bantuan kami melalui kanal pada bagian 3 untuk penjelasan awal.</p>
                </section>

                <section aria-labelledby="wakaf-proses">
                    <h2 id="wakaf-proses" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">3. Gambaran Proses</h2>
                    <p class="mb-3">Enam langkah berikut adalah gambaran proses, bukan status pengajuan Anda — langkah 2 sampai 6 berjalan melalui kanal kontak dan proses manual tim kami, bukan di dalam sistem ini.</p>
                    <ol class="list-decimal space-y-2 pl-6">
                        <li>Pilih tujuan wakaf.</li>
                        <li>Isi data pemilik dan tanah &mdash; dilakukan bersama tim kami, bukan lewat formulir di halaman ini.</li>
                        <li>Verifikasi dan survei.</li>
                        <li>Proses administrasi.</li>
                        <li>Penilaian komersial atau non komersial.</li>
                        <li>Dokumentasi dan serah terima.</li>
                    </ol>
                </section>

                <section aria-labelledby="wakaf-kontak">
                    <h2 id="wakaf-kontak" class="mb-3 text-2xl font-semibold tracking-tight text-neutral-900">4. Kontak</h2>
                    <p>Untuk pertanyaan mengenai wakaf tanah atau untuk memulai pembicaraan awal, silakan hubungi tim Bantuan kami.</p>
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

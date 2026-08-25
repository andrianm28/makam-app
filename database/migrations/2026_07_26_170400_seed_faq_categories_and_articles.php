<?php

declare(strict_types=1);

use App\Domain\Faq\FaqArticlePublishState;
use App\Domain\Faq\FaqCategoryCode;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Seeds `faq_categories` and `faq_articles` from the two documents this
 * batch's brief names as the single source of truth: `docs/product/
 * faq-catalog.md` (the six categories, their exact codes/labels/order, and
 * the minimum article questions per category) and `docs/governance/
 * assumptions-and-gates.md` §2 + `2026_07_26_120400_seed_feature_gate_
 * registry.php` (which gates are actually open vs. closed today, for the
 * payment/Urgent content-accuracy constraint below).
 *
 * ---------------------------------------------------------------------------
 * Why a DATA MIGRATION, not a `database/seeders/*` class
 * ---------------------------------------------------------------------------
 * `database/seeders/DatabaseSeeder.php` exists only as the unmodified
 * Laravel scaffold (a single `User::factory()` call) — nothing in this
 * codebase's CI pipeline or deployment process ever runs `php artisan
 * db:seed`. `2026_07_26_120400_seed_feature_gate_registry.php` already
 * established the actual precedent for "real content that must exist in
 * every environment automatically": a migration, which runs everywhere
 * `php artisan migrate` does. Following that precedent here — a
 * `Database\Seeders\FaqSeeder` class would silently never run outside a
 * developer's own manual `db:seed` call, which is not what "seed and
 * preserve six required categories" (`AGENTS.md` §FAQ) needs.
 *
 * ---------------------------------------------------------------------------
 * Article count — every catalogue question, none invented, none skipped
 * ---------------------------------------------------------------------------
 * `faq-catalog.md`'s "Minimum article questions" section lists 4 + 3 + 4 + 4
 * + 4 + 4 = 23 questions across the six categories (counted directly against
 * that file, not assumed). This migration seeds exactly those 23 as
 * articles, one per question, using the question itself (or a direct
 * capitalisation-only rephrasing) as the article title, per that section's
 * own wording being questions, not answers — this migration writes the
 * answers.
 *
 * ---------------------------------------------------------------------------
 * 22 published, 1 deliberately seeded as `draft` — not accidental
 * ---------------------------------------------------------------------------
 * `CUSTOMER_SERVICE`'s fourth question ("Bagaimana melaporkan masalah vendor
 * atau order?") is seeded with `publish_state = draft`, on purpose: this
 * batch's brief requires at least one real seeded (not test-fixture-only)
 * draft row so a later batch's AC6 tests/UI have genuine data to exercise
 * the "never shown publicly" guarantee against. This specific question was
 * chosen because no dedicated vendor/order-complaint flow is documented
 * anywhere in this repo yet (`docs/product/**`, `.kiro/specs/**` were
 * checked) — a genuinely plausible reason a real content team would hold
 * this one back for review while publishing the rest. A LATER BATCH: do not
 * mistake this for accidentally-unpublished content — it is intentional
 * seed data, kept in `draft` deliberately.
 *
 * ---------------------------------------------------------------------------
 * AC7 content-accuracy constraint — what was checked before writing the
 * payment- and Urgent-related answers
 * ---------------------------------------------------------------------------
 * requirements.md AC7: "WHILE a payment-related or Urgent-related feature
 * gate is active THE SYSTEM SHALL reflect only approved operational
 * information in FAQ content — never an unsupported claim about
 * availability, price, or SLA." Checked directly against
 * `2026_07_26_120400_seed_feature_gate_registry.php`: EVERY gate seeds
 * `closed` as of Sprint 3, including `G-PAY-01` (online payment — "Manual
 * coordination" is the documented behaviour before activation) and
 * `G-OPS-01` (Urgent/At-Need acceptance — "Disabled by area/time"). No
 * document in this repo commits to a specific manual-verification
 * turnaround number, a specific renewal late-fee amount without a written
 * operator basis, or a real customer-service phone/WhatsApp/email contact
 * or fixed operating hours (`docs/testing/release-gates.md`'s own checklist
 * still lists "Support contacts, hours, incident owner, and escalation are
 * configured" as an OPEN item, not a done one; `G-WA-01`/WhatsApp is also
 * seeded `closed`). Every answer below that touches payment, Urgent
 * timing/hours, a turnaround duration, or a support contact channel is
 * phrased to describe the REAL current state (manual coordination,
 * gate-checked-at-request-time availability, "tim kami akan
 * menginformasikan..." instead of a specific promised number) rather than
 * asserting a fact this repository does not actually commit to anywhere.
 * See this batch's own final report for the specific list of answers this
 * constraint shaped.
 *
 * Migration timestamp slot: see
 * `2026_07_26_170000_create_faq_categories_table.php`'s own doc block.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // --- faq_categories — faq-catalog.md's own listed order/codes/labels.
        $categories = [
            [FaqCategoryCode::CARA_MEMESAN, 'Cara Memesan'],
            [FaqCategoryCode::DOKUMEN, 'Dokumen'],
            [FaqCategoryCode::PEMBAYARAN, 'Pembayaran'],
            [FaqCategoryCode::PERPANJANGAN, 'Perpanjangan Makam'],
            [FaqCategoryCode::PEMBAYARAN_GAGAL, 'Pembayaran Gagal'],
            [FaqCategoryCode::CUSTOMER_SERVICE, 'Customer Service'],
        ];

        $categoryIds = [];

        foreach ($categories as $index => [$code, $name]) {
            $categoryIds[$code] = DB::table('faq_categories')->insertGetId([
                'code' => $code,
                'name' => $name,
                'sort_order' => $index + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // --- faq_articles — one row per faq-catalog.md question, in that
        // file's own listed order within each category.
        //
        // Shape: [category code, title, slug, summary, body, publish state]
        $articles = [
            // --- Cara Memesan (4 questions) ---
            [
                FaqCategoryCode::CARA_MEMESAN,
                'Bagaimana cara memesan makam?',
                'bagaimana-cara-memesan-makam',
                'Pemesanan dilakukan melalui alur booking online sembilan langkah, mulai dari memilih lokasi hingga konfirmasi akhir.',
                'Untuk memesan makam, Anda akan melalui sembilan langkah berurutan: memilih lokasi, memilih TPU/TPS, memilih jenis layanan, memilih layanan dan tambahan yang dibutuhkan, meninjau ringkasan pesanan, mengisi data pemesan, melengkapi data almarhum beserta dokumen pendukung, melakukan pembayaran, dan terakhir konfirmasi pesanan. Setiap langkah divalidasi sebelum Anda melanjutkan ke langkah berikutnya, dan progres Anda tersimpan secara otomatis sehingga dapat dilanjutkan kapan saja. Jika ada perubahan harga atau ketersediaan di tengah proses, sistem akan meminta Anda meninjau ulang ringkasan sebelum melanjutkan ke pembayaran. Setelah semua langkah selesai, tim kami akan memproses pesanan Anda sesuai jenis layanan yang dipilih.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                FaqCategoryCode::CARA_MEMESAN,
                'Bagaimana cara melanjutkan draft?',
                'bagaimana-cara-melanjutkan-draft',
                'Draft pesanan tersimpan otomatis setiap kali Anda menyelesaikan sebuah langkah, sehingga dapat dilanjutkan kapan saja.',
                'Sistem menyimpan draft pesanan Anda secara otomatis setiap kali Anda berhasil menyelesaikan suatu langkah, dan secara berkala selama Anda masih mengisi data pada langkah yang sama. Jika Anda memesan tanpa masuk ke akun, simpan baik-baik tautan atau kode draft yang diberikan sistem di awal proses, karena tautan tersebut adalah cara Anda kembali ke draft yang sama. Jika Anda memesan menggunakan akun, draft akan otomatis terhubung dengan akun Anda dan dapat dilanjutkan dari perangkat apa pun setelah masuk. Jika Anda mengalami kendala menemukan draft yang pernah dibuat, tim customer service dapat membantu menelusurinya.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                FaqCategoryCode::CARA_MEMESAN,
                'Kapan ketersediaan makam dikonfirmasi?',
                'kapan-ketersediaan-makam-dikonfirmasi',
                'Ketersediaan yang tampil di awal bersifat indikatif; konfirmasi akhir dilakukan sebelum pesanan difinalisasi.',
                'Informasi ketersediaan yang Anda lihat saat memilih lokasi dan jenis layanan pada awalnya bersifat indikatif dan masih memerlukan konfirmasi. Secara default, sistem mengonfirmasi ketersediaan pada tingkat paket atau kelas layanan, bukan menjanjikan petak tertentu, karena penentuan petak spesifik memerlukan data inventaris resmi dari pengelola lokasi. Konfirmasi akhir dilakukan sebelum pesanan Anda difinalisasi, dan apabila terjadi perubahan ketersediaan atau harga di tengah proses, sistem akan meminta Anda meninjau ulang ringkasan pesanan sebelum melanjutkan. Jika Anda membutuhkan kepastian lebih awal, tim customer service dapat membantu menanyakan langsung ke pengelola lokasi terkait.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                FaqCategoryCode::CARA_MEMESAN,
                'Bagaimana proses Pemakaman Hari Ini?',
                'bagaimana-proses-pemakaman-hari-ini',
                'Layanan Pemakaman Hari Ini (Urgent) diperiksa langsung terhadap area, jam operasional, dan kapasitas saat itu; ketersediaannya tidak dapat dijanjikan di semua lokasi dan waktu.',
                "Pemakaman Hari Ini adalah jenis layanan Urgent yang dipilih pada langkah 'Pilih Jenis Layanan'. Saat Anda memilih layanan ini, sistem akan langsung memeriksa apakah area, jam operasional, dan kapasitas lokasi yang dipilih memungkinkan penanganan pada hari yang sama. Karena hal ini bergantung pada kondisi setiap lokasi dan waktu pengajuan, penerimaan layanan Pemakaman Hari Ini belum dapat dijanjikan secara instan untuk semua lokasi dan waktu. Apabila permintaan Anda dapat diterima, sistem akan membuat kasus penanganan khusus yang dipantau oleh tim kami hingga selesai. Jika saat itu layanan tidak dapat diterima di lokasi pilihan Anda, kami sarankan segera menghubungi customer service agar dapat dibantu mencari opsi terbaik.",
                FaqArticlePublishState::PUBLISHED,
            ],

            // --- Dokumen (3 questions) ---
            [
                FaqCategoryCode::DOKUMEN,
                'Dokumen apa yang diperlukan?',
                'dokumen-apa-yang-diperlukan',
                'Tiga dokumen utama yang dibutuhkan adalah KTP, Kartu Keluarga, dan Surat Keterangan Kematian.',
                'Pada langkah data almarhum, Anda akan diminta mengunggah tiga dokumen utama: Kartu Tanda Penduduk (KTP), Kartu Keluarga (KK), dan Surat Keterangan Kematian. Ketiga dokumen ini digunakan untuk memverifikasi identitas pemesan dan almarhum, serta melengkapi data administrasi pemakaman. Sistem akan memeriksa format dan ukuran berkas yang Anda unggah, dan akan memberi tahu jika ada dokumen yang belum sesuai. Pastikan seluruh dokumen jelas terbaca sebelum diunggah agar proses verifikasi dapat berjalan lancar.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                FaqCategoryCode::DOKUMEN,
                'Bagaimana keamanan KTP, KK, dan surat kematian?',
                'keamanan-ktp-kk-surat-kematian',
                'Dokumen disimpan secara privat, diperiksa keamanannya sebelum dapat digunakan, dan hanya dapat diakses melalui tautan yang dibatasi waktu.',
                'Setiap dokumen yang Anda unggah — termasuk KTP, KK, dan Surat Keterangan Kematian — masuk ke penyimpanan privat dan menjalani pemeriksaan keamanan sebelum dapat digunakan atau diunduh oleh siapa pun, termasuk tim kami. Akses ke dokumen hanya diberikan melalui tautan bertanda tangan digital yang berlaku sangat singkat, dan setiap akses terhadap dokumen restricted tercatat dalam log audit. Dokumen pribadi Anda tidak pernah dikirimkan sebagai lampiran melalui email atau WhatsApp. Perlakuan ini berlaku untuk seluruh dokumen sensitif yang Anda unggah selama proses pemesanan.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                FaqCategoryCode::DOKUMEN,
                'Apa yang dilakukan jika dokumen belum lengkap?',
                'dokumen-belum-lengkap',
                'Draft pesanan Anda tetap tersimpan sehingga dokumen dapat dilengkapi kapan saja sebelum melanjutkan ke pembayaran.',
                'Jika dokumen Anda belum lengkap atau belum valid, Anda tidak perlu khawatir kehilangan progres — draft pesanan tetap tersimpan dan dapat dilanjutkan kapan saja setelah dokumen siap. Sistem akan menandai dokumen mana yang belum diunggah atau belum memenuhi format yang diminta. Jika Anda tidak yakin dokumen pengganti apa yang dapat diterima untuk situasi khusus Anda, tim customer service siap membantu memberikan arahan sebelum Anda melanjutkan ke langkah pembayaran.',
                FaqArticlePublishState::PUBLISHED,
            ],

            // --- Pembayaran (4 questions) ---
            [
                FaqCategoryCode::PEMBAYARAN,
                'Metode pembayaran apa yang tersedia?',
                'metode-pembayaran-yang-tersedia',
                'Saat ini pembayaran dikoordinasikan secara manual bersama tim kami; kanal pembayaran otomatis akan diinformasikan begitu tersedia.',
                'Pada tahap ini, metode pembayaran yang kami dukung sepenuhnya adalah koordinasi pembayaran manual bersama tim kami: Anda akan menerima instruksi dan referensi pembayaran pada langkah pembayaran, kemudian dapat mengunggah bukti pembayaran untuk diverifikasi. Kanal pembayaran otomatis/online sedang dalam proses aktivasi dan akan diinformasikan melalui halaman ini begitu benar-benar tersedia, sehingga kami tidak menampilkan opsi tersebut sebelum siap digunakan. Apa pun metode yang berlaku, status pesanan Anda hanya akan berubah menjadi lunas setelah pembayaran benar-benar terverifikasi, bukan hanya karena Anda kembali dari halaman pembayaran.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                FaqCategoryCode::PEMBAYARAN,
                'Kapan pembayaran dapat dilakukan?',
                'kapan-pembayaran-dapat-dilakukan',
                'Pembayaran dilakukan pada langkah kedelapan, setelah ringkasan pesanan, data pemesan, dan dokumen selesai dikonfirmasi.',
                'Pembayaran menjadi langkah kedelapan dari sembilan langkah pemesanan, dilakukan setelah Anda meninjau ringkasan pesanan, mengisi data pemesan, dan melengkapi data almarhum beserta dokumen pendukung. Urutan ini memastikan jumlah tagihan yang Anda bayarkan sudah sesuai dengan layanan dan tambahan yang benar-benar Anda pilih. Jika terjadi perubahan harga sebelum Anda sampai ke langkah ini, sistem akan meminta Anda mengonfirmasi ulang ringkasan pesanan terlebih dahulu.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                FaqCategoryCode::PEMBAYARAN,
                'Kapan invoice diterbitkan?',
                'kapan-invoice-diterbitkan',
                'Invoice diterbitkan setelah ada pembayaran sah yang tercatat, baik otomatis maupun setelah pembayaran manual disetujui tim kami.',
                'Invoice untuk pesanan Anda diterbitkan setelah terjadi peristiwa pembayaran yang sah, yaitu pembayaran otomatis yang telah dikonfirmasi sistem, atau pencatatan pembayaran manual yang telah disetujui oleh tim kami setelah proses verifikasi. Dengan kata lain, invoice tidak diterbitkan hanya berdasarkan pengajuan atau unggahan bukti pembayaran semata, melainkan setelah pembayarannya benar-benar dinyatakan sah. Anda dapat memeriksa status pesanan untuk mengetahui kapan invoice sudah tersedia.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                FaqCategoryCode::PEMBAYARAN,
                'Apakah pembayaran manual didukung?',
                'apakah-pembayaran-manual-didukung',
                'Ya, pembayaran manual adalah jalur pembayaran utama yang didukung penuh saat ini.',
                'Pembayaran manual didukung penuh dan menjadi jalur utama pembayaran saat ini. Pada langkah pembayaran, Anda akan menerima instruksi dan referensi pembayaran, kemudian dapat mengunggah bukti pembayaran secara opsional untuk mempercepat proses verifikasi. Tim kami akan memverifikasi pembayaran tersebut sebelum status pesanan diperbarui menjadi lunas — status tidak pernah diubah secara otomatis hanya karena Anda kembali dari halaman pembayaran. Jika ada kendala saat proses ini, Anda dapat menghubungi customer service kapan saja.',
                FaqArticlePublishState::PUBLISHED,
            ],

            // --- Perpanjangan (4 questions) ---
            [
                FaqCategoryCode::PERPANJANGAN,
                'Bagaimana mencari data makam?',
                'bagaimana-mencari-data-makam',
                'Cari berdasarkan nama almarhum, blok, atau tanggal pemakaman setelah memilih kota serta TPU/TPS terkait.',
                'Untuk memperpanjang makam, mulai dengan memilih kota, kemudian TPU/TPS tempat makam berada, lalu cari data makam menggunakan nama almarhum, nomor blok, atau tanggal pemakaman. Pencarian dirancang cukup toleran terhadap variasi kecil dalam penulisan nama, sehingga Anda tetap dapat menemukan data meski ejaan yang diketik tidak persis sama. Jika muncul beberapa hasil yang mirip, periksa kembali detail seperti blok dan tanggal untuk memastikan Anda memilih makam yang benar sebelum melanjutkan ke tahap tarif.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                FaqCategoryCode::PERPANJANGAN,
                'Dari mana tarif perpanjangan berasal?',
                'dari-mana-tarif-perpanjangan-berasal',
                'Tarif ditampilkan beserta sumber resminya dan waktu pembaruan terakhir; tidak ada nominal atau denda yang dibuat tanpa dasar tertulis.',
                'Setiap tarif perpanjangan yang ditampilkan disertai sumber resmi dan waktu pembaruan terakhirnya, sehingga Anda dapat melihat dari mana angka tersebut berasal. Kami tidak pernah menghitung atau menampilkan denda keterlambatan tanpa dasar tertulis yang jelas dari pengelola terkait — jika dasar tersebut belum tersedia untuk lokasi Anda, sistem tidak akan mengarang nominal denda. Apabila Anda merasa tarif yang tampil perlu diklarifikasi, tim customer service dapat membantu menghubungkan Anda dengan pihak pengelola.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                FaqCategoryCode::PERPANJANGAN,
                'Bagaimana jika sudah membayar di loket?',
                'bagaimana-jika-sudah-membayar-di-loket',
                'Sampaikan bukti pembayaran ke customer service agar dapat dicatat sebagai pembayaran di luar sistem dan mencegah tagihan ganda.',
                'Jika Anda sudah membayar perpanjangan langsung di loket atau melalui jalur di luar sistem, sampaikan bukti pembayaran tersebut kepada customer service kami. Tim kami atau operator lokasi dapat mencatat pembayaran itu sebagai pembayaran di luar sistem yang dilengkapi bukti, sehingga catatan perpanjangan Anda tetap akurat dan Anda tidak diminta membayar dua kali untuk periode yang sama. Proses ini memastikan riwayat perpanjangan makam Anda tetap konsisten meskipun pembayarannya tidak dilakukan melalui platform.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                FaqCategoryCode::PERPANJANGAN,
                'Apa yang dilakukan jika data makam tidak ditemukan?',
                'data-makam-tidak-ditemukan',
                'Coba perbaiki kata kunci pencarian, atau hubungi customer service untuk verifikasi data secara manual.',
                'Jika pencarian tidak menemukan data makam yang Anda maksud, coba periksa kembali ejaan nama, nomor blok, atau tanggal pemakaman yang Anda masukkan — variasi kecil terkadang memengaruhi hasil pencarian. Apabila data tetap tidak ditemukan, kami tidak akan menampilkan atau mengarang data yang tidak pasti. Sebagai gantinya, silakan hubungi customer service agar data dapat ditelusuri dan diverifikasi secara manual bersama pengelola lokasi terkait.',
                FaqArticlePublishState::PUBLISHED,
            ],

            // --- Pembayaran Gagal (4 questions) ---
            [
                FaqCategoryCode::PEMBAYARAN_GAGAL,
                'Apa yang dilakukan jika pembayaran gagal?',
                'apa-yang-dilakukan-jika-pembayaran-gagal',
                'Draft dan pesanan Anda tetap aman; Anda dapat mencoba kembali atau beralih ke jalur pembayaran manual.',
                'Jika pembayaran Anda gagal, draft dan pesanan Anda tetap tersimpan dengan aman dan tidak hilang. Anda dapat mencoba melakukan pembayaran kembali atau beralih menggunakan jalur pembayaran manual yang tersedia. Status pesanan tidak akan pernah ditandai lunas hanya karena Anda kembali ke halaman ini setelah kegagalan; perubahan status hanya terjadi setelah pembayaran benar-benar terverifikasi. Jika kegagalan terus berulang, silakan hubungi customer service agar dapat dibantu menelusuri penyebabnya.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                FaqCategoryCode::PEMBAYARAN_GAGAL,
                'Bagaimana jika saldo terpotong tetapi status belum berubah?',
                'saldo-terpotong-status-belum-berubah',
                'Jangan melakukan pembayaran ulang terlebih dahulu; hubungi customer service dengan bukti transaksi agar dapat ditelusuri.',
                'Apabila saldo Anda terpotong namun status pesanan belum berubah, jangan langsung melakukan pembayaran ulang. Simpan bukti transaksi yang Anda miliki, seperti mutasi rekening atau notifikasi dari penyedia pembayaran, lalu hubungi customer service agar kejadian tersebut dapat ditelusuri. Tim kami tidak akan menandai status pesanan sebagai lunas tanpa konfirmasi pembayaran yang valid, sehingga penelusuran ini penting untuk memastikan pembayaran Anda tercatat dengan benar tanpa risiko pembayaran ganda.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                FaqCategoryCode::PEMBAYARAN_GAGAL,
                'Bagaimana mengirim bukti pembayaran?',
                'bagaimana-mengirim-bukti-pembayaran',
                'Unggah bukti pembayaran melalui halaman status pesanan atau pada langkah pembayaran manual yang tersedia.',
                'Bukti pembayaran dapat diunggah melalui opsi unggah yang tersedia pada langkah pembayaran manual, atau melalui halaman status pesanan Anda jika pembayaran sudah pernah diajukan sebelumnya. Pastikan bukti yang diunggah jelas terbaca dan mencantumkan informasi penting seperti nominal, tanggal, dan referensi transaksi. Bukti pembayaran yang Anda kirimkan disimpan secara privat, sama seperti dokumen sensitif lainnya, dan hanya digunakan untuk keperluan verifikasi.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                FaqCategoryCode::PEMBAYARAN_GAGAL,
                'Berapa lama verifikasi manual?',
                'berapa-lama-verifikasi-manual',
                'Durasi verifikasi bergantung pada antrean dan kelengkapan bukti yang dikirimkan; tim customer service akan menginformasikan perkembangannya.',
                'Kami belum dapat menjanjikan durasi pasti untuk verifikasi pembayaran manual, karena lamanya proses bergantung pada volume verifikasi yang sedang berjalan dan kelengkapan bukti yang Anda kirimkan. Untuk mempercepat proses, pastikan bukti pembayaran yang diunggah lengkap dan jelas sejak awal. Jika Anda ingin mengetahui perkembangan verifikasi pesanan Anda, tim customer service dapat membantu memberikan informasi terkini.',
                FaqArticlePublishState::PUBLISHED,
            ],

            // --- Customer Service (4 questions) ---
            [
                FaqCategoryCode::CUSTOMER_SERVICE,
                'Bagaimana menghubungi customer service?',
                'bagaimana-menghubungi-customer-service',
                'Gunakan tautan atau tombol Bantuan yang tersedia pada halaman pemesanan dan status pesanan Anda.',
                'Anda dapat menghubungi customer service melalui tautan atau tombol Bantuan yang tersedia pada halaman pemesanan, status pesanan, maupun halaman FAQ ini. Kanal komunikasi resmi lain, seperti nomor kontak atau jam layanan khusus, akan ditampilkan pada halaman terkait sesuai dengan konfigurasi yang berlaku untuk lokasi Anda. Kami akan menginformasikan secara jelas apabila ada kanal tambahan, seperti WhatsApp, yang mulai tersedia.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                FaqCategoryCode::CUSTOMER_SERVICE,
                'Jam operasional layanan reguler dan Urgent.',
                'jam-operasional-layanan-reguler-dan-urgent',
                'Jam layanan reguler dan Urgent dapat berbeda untuk setiap lokasi, dan diperiksa langsung saat Anda memilih layanan.',
                'Jam operasional layanan reguler maupun Urgent dapat berbeda-beda tergantung lokasi dan pengelola TPU/TPS masing-masing. Untuk layanan Urgent seperti Pemakaman Hari Ini, sistem akan memeriksa jam operasional lokasi yang dipilih secara langsung pada saat Anda mengajukan layanan tersebut, sehingga informasi yang Anda terima selalu sesuai dengan kondisi terkini. Untuk mengetahui jam operasional yang berlaku di lokasi Anda, silakan periksa halaman lokasi terkait atau hubungi customer service.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                FaqCategoryCode::CUSTOMER_SERVICE,
                'Informasi apa yang perlu disiapkan saat menghubungi bantuan?',
                'informasi-yang-perlu-disiapkan-saat-menghubungi-bantuan',
                'Siapkan nomor pesanan atau draft, nama pemesan, dan ringkasan kendala agar bantuan dapat diproses lebih cepat.',
                'Agar tim customer service dapat membantu Anda lebih cepat, siapkan nomor pesanan atau kode draft, nama pemesan sesuai data yang terdaftar, serta ringkasan singkat mengenai kendala yang Anda alami. Jika kendala berkaitan dengan pembayaran, sertakan juga bukti transaksi yang Anda miliki. Semakin lengkap informasi yang Anda berikan di awal, semakin cepat tim kami dapat menelusuri dan menindaklanjuti permintaan Anda.',
                FaqArticlePublishState::PUBLISHED,
            ],
            [
                // Deliberately seeded as `draft` — see this migration's own
                // class-level doc block for why this specific question was
                // chosen.
                FaqCategoryCode::CUSTOMER_SERVICE,
                'Bagaimana melaporkan masalah vendor atau order?',
                'bagaimana-melaporkan-masalah-vendor-atau-order',
                'Panduan melaporkan kendala terkait vendor atau pesanan kepada tim kami.',
                'Jika Anda mengalami kendala terkait vendor atau pesanan, gunakan tautan Bantuan yang tersedia pada halaman status pesanan Anda untuk menyampaikan laporan kepada tim kami. Sertakan nomor pesanan serta uraian kendala secara singkat agar dapat segera kami tindaklanjuti bersama vendor terkait. Kami akan menginformasikan perkembangan penanganan laporan Anda melalui kanal yang sama.',
                FaqArticlePublishState::DRAFT,
            ],
        ];

        // Per-category sequential sort_order, in this array's own order —
        // matches faq-catalog.md's own listed question order within each
        // category. `ReorderFaqArticles` is the ONLY way this changes after
        // seeding.
        $sortOrderByCategory = [];

        foreach ($articles as [$categoryCode, $title, $slug, $summary, $body, $publishState]) {
            $sortOrderByCategory[$categoryCode] = ($sortOrderByCategory[$categoryCode] ?? 0) + 1;

            $isPublished = $publishState === FaqArticlePublishState::PUBLISHED;

            DB::table('faq_articles')->insert([
                'category_id' => $categoryIds[$categoryCode],
                'title' => $title,
                'slug' => $slug,
                'summary' => $summary,
                'body' => $body,
                'publish_state' => $publishState,
                'sort_order' => $sortOrderByCategory[$categoryCode],
                // A published seed article is seeded as already having gone
                // live once — `current_version = 1` with a matching
                // `faq_article_versions` row below, exactly what a real
                // `PublishFaqArticle` call would have produced. The seeded
                // draft stays at `current_version = 0` with no version row,
                // matching a never-published article's real invariant.
                'current_version' => $isPublished ? 1 : 0,
                'published_at' => $isPublished ? $now : null,
                'unpublished_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($isPublished) {
                $articleId = DB::table('faq_articles')->where('slug', $slug)->value('id');

                DB::table('faq_article_versions')->insert([
                    'faq_article_id' => $articleId,
                    'version_number' => 1,
                    'category_id' => $categoryIds[$categoryCode],
                    'title' => $title,
                    'slug' => $slug,
                    'summary' => $summary,
                    'body' => $body,
                    'published_at' => $now,
                    'published_by' => 'seed:public-faq-catalog',
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::table('faq_article_versions')->where('published_by', 'seed:public-faq-catalog')->delete();

        DB::table('faq_articles')->whereIn('slug', [
            'bagaimana-cara-memesan-makam', 'bagaimana-cara-melanjutkan-draft',
            'kapan-ketersediaan-makam-dikonfirmasi', 'bagaimana-proses-pemakaman-hari-ini',
            'dokumen-apa-yang-diperlukan', 'keamanan-ktp-kk-surat-kematian', 'dokumen-belum-lengkap',
            'metode-pembayaran-yang-tersedia', 'kapan-pembayaran-dapat-dilakukan', 'kapan-invoice-diterbitkan',
            'apakah-pembayaran-manual-didukung', 'bagaimana-mencari-data-makam', 'dari-mana-tarif-perpanjangan-berasal',
            'bagaimana-jika-sudah-membayar-di-loket', 'data-makam-tidak-ditemukan',
            'apa-yang-dilakukan-jika-pembayaran-gagal', 'saldo-terpotong-status-belum-berubah',
            'bagaimana-mengirim-bukti-pembayaran', 'berapa-lama-verifikasi-manual',
            'bagaimana-menghubungi-customer-service', 'jam-operasional-layanan-reguler-dan-urgent',
            'informasi-yang-perlu-disiapkan-saat-menghubungi-bantuan', 'bagaimana-melaporkan-masalah-vendor-atau-order',
        ])->delete();

        DB::table('faq_categories')->whereIn('code', FaqCategoryCode::KNOWN_CODES)->delete();
    }
};

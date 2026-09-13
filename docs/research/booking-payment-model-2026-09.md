# Model Pembayaran Pemesanan Makam — Riset, September 2026

## Status

**Riset input + rekomendasi. BUKAN keputusan, BUKAN persyaratan kontraktual.** Disusun 8 September 2026.

Dokumen ini **tidak boleh dijadikan dasar implementasi sendirian.** Model yang direkomendasikan menyentuh kontrol finansial yang dipasang lewat ruling `1b-L3-01` (six-condition guard) dan membawa eksposur **pidana** di bawah UU Perlindungan Konsumen. Urutan yang benar: dokumen ini → opini hukum profesional → keputusan pemilik produk → ADR → spec implementasi.

## Latar masalah

Wizard pemesanan publik menawarkan tombol "Bayar Sekarang" di Langkah 3, tetapi pembayaran online **tidak pernah bisa berhasil** untuk pemesanan self-service. `GuardPaymentSession` mensyaratkan enam kondisi; tiga di antaranya hanya bisa dipenuhi operator/admin.

Terverifikasi langsung dari basis data `dev` (`payment_intents`, evaluasi 8 Sep 2026 14:23:57):

```
denied_conditions: ["confirmation_valid_or_reservation_active",
                    "quote_accepted_and_unexpired",
                    "authorized_opening"]
```

Kondisi 1 (gate `G-PAY-01`), 5 (jumlah cocok), dan 6 (merchant binding) lolos. Yang gagal:

| # | Kondisi | Kenapa gagal untuk pemesan baru |
|---|---|---|
| 2 | `ConfirmationOrReservation` | `SubmitBookingDraft` membuat order berstatus `MASUK`; guard butuh minimal `PENAWARAN_TERKIRIM` |
| 3 | `QuoteAcceptedAndUnexpired` | Wizard hanya menerbitkan quote (`ISSUED`); `AcceptQuote` hanya dipanggil dari `TransitionOrderAction` di panel admin |
| 4 | `AuthorizedOpening` | Butuh `ScopeAssignment` bertipe ORDER dari aktor ber-role ADMIN/FINANCE |

Perilaku ini **disengaja** dan dikunci oleh test repo sendiri (`BookingWizardOnlinePaymentTest::test_online_submit_creates_order_and_quote_but_fails_closed_without_calling_the_provider`). Jadi yang cacat bukan guard-nya, melainkan **UI yang menawarkan tombol pembayaran yang mustahil berhasil**, lalu menampilkan layar merah seolah gagal padahal ordernya sudah tercatat.

## Temuan riset

Empat jalur riset dijalankan paralel: praktik industri, model pembayaran & rail Indonesia, regulasi Indonesia, dan mekanika reservasi inventaris langka.

### 1. At-Need dan Pre-Need adalah dua produk pembayaran yang berbeda

Pola ini konsisten di Indonesia, AS, Inggris, dan Australia — dan ketiga jalur riset non-hukum sampai pada kesimpulan yang sama secara independen.

Angka terverifikasi dari operator Indonesia:

| Operator | Booking fee | Aturan pelunasan |
|---|---|---|
| San Diego Hills | Rp 5 jt (Single, cash) — Rp 170 jt (Peak Estate) | Dihitung sebagai bagian total harga; **At-Need wajib lunas sebelum lahan digali**; cicilan 0% 12 bulan **hanya Pre-Need** |
| Al Azhar Memorial Garden | Rp 1 jt – 5 jt | **7 hari** (cash keras) / **14 hari** (tunai); DP 20% untuk cicilan 12×; cicilan **tidak berlaku saat kedukaan** |
| Lestari Memorial Park | tidak dipublikasi | cicilan 0% 12–36 bulan |

Catatan kepercayaan: angka San Diego Hills berasal dari situs agen resmi yang saling konsisten, bukan domain operator sendiri. Angka Al Azhar (7/14 hari) berasal dari FAQ operator langsung.

**Tidak satu pun memorial park Indonesia dalam sapuan ini mempublikasikan klausul refund, pembatalan, atau cooling-off.** Ini celah nyata di praktik pasar, sekaligus peluang diferensiasi terkuat yang tersedia.

### 2. Rail pembayaran menentukan apakah refund mungkin

Dari dokumentasi resmi gateway:

- Xendit: *"All virtual account transactions that have been paid by the end-customer cannot be refunded from Xendit side/system."*
- Midtrans: matriks refund menandai Bank Transfer (Permata, Mandiri Bill, BNI, BCA, BRI) dan OTC (Alfamart/Indomaret) sebagai **NO**.
- QRIS dan e-wallet **mendukung** refund di kedua gateway.

Karena VA/transfer bank adalah rail dominan di Indonesia, ini bukan detail teknis — ini menentukan apakah janji refund bisa ditepati sama sekali.

**Implikasi desain (leverage tertinggi):** ambil DP **hanya** lewat rail yang bisa di-refund (QRIS/e-wallet/kartu), dan sediakan VA **hanya** untuk pelunasan setelah konfirmasi — karena sisa tagihan tidak akan pernah perlu dibalikkan.

### 3. Authorization hold ada di Indonesia, tapi bukan fondasi

- Midtrans kartu: `type: authorize`, dana dilepas otomatis setelah **7 hari** tanpa capture.
- Midtrans **GoPay Tokenization Pre-Auth**: `validUpTo` dapat dikonfigurasi **20 detik – 180 hari** (default 7 hari). Perlu pengaktifan lewat sales, saat ini hanya transaksi No-PIN.
- Xendit kartu: otorisasi kedaluwarsa **7 hari**; partial capture tunggal didukung, multiple partial captures tidak.
- DOKU: Authorize & Capture pada kartu.

Secara struktural, *authorize-now-capture-on-confirmation* adalah jawaban paling tepat untuk masalah ini — uang tidak berpindah sampai ketersediaan dikonfirmasi, dan pembatalan berarti *void*, bukan refund. Kendalanya cakupan: kartu hanya menjangkau ~6–7% populasi Indonesia (sumber sekunder). Layak dijadikan **optimisasi opsional belakangan**, bukan fondasi.

### 4. Refund manual bisa di-de-risk tanpa integrasi refund API

Kondisi hari ini: `RecordRefund` hanya menulis baris `payment_reversals` + audit event. Doc-nya eksplisit tidak memanggil `PaymentProvider::refund()` karena kontrak itu tidak ada di branch ini, dan `SumoPodPaymentClient` hanya punya `createPayment()`.

Jalan keluar tanpa membangun integrasi refund: gunakan rail **payout/disbursement** untuk kaki pengembaliannya. Xendit menyatakan jangkauan 140+ bank dan e-wallet dengan *"99.9% of payouts processed within 15 minutes"*, beroperasi 07:00–23:00 setiap hari. **Persetujuan tetap manusia, eksekusinya otomatis.**

### 5. Kendala hukum Indonesia

Ini bagian dengan konsekuensi terberat.

**UU No. 8/1999 Pasal 9 ayat (1) huruf e** melarang menawarkan/mengiklankan jasa secara tidak benar seolah-olah *"barang dan/atau jasa tersebut tersedia"*. Menampilkan plot sebagai dapat dipesan dan menarik uang padahal ketersediaan belum dikonfirmasi jatuh pada rumusan ini. Sanksi **Pasal 62 ayat (1): penjara maks. 5 tahun atau denda maks. Rp 2 miliar.**

Pasal terkait lain: Pasal 10 (pernyataan tidak benar soal harga/tarif), Pasal 16 huruf a–b (tidak menepati pesanan/janji pelayanan), Pasal 7 huruf b/f/g (kewajiban informasi jujur dan ganti rugi), Pasal 19 ayat (2)–(3) (ganti rugi dapat berupa pengembalian uang, **dilaksanakan dalam 7 hari**).

**PP No. 80/2019:**
- **Pasal 39 ayat (1) huruf f–g** — penawaran elektronik **wajib memuat** *"risiko dan kondisi yang tidak diharapkan"* dan *"pembatasan pertanggungjawaban apabila terjadi risiko yang tidak diharapkan"*. Pengungkapan risiko gagal-penuhi karena itu adalah kewajiban regulasi, bukan pilihan desain.
- **Pasal 46** — isi Konfirmasi Elektronik harus sama dengan informasi penawaran.
- **Pasal 71** — penyelenggara yang menerima pembayaran **wajib memiliki mekanisme yang memastikan pengembalian dana** konsumen bila terjadi pembatalan.
- **Pasal 80** — sanksi administratif sampai **daftar hitam, pemblokiran sementara layanan, dan pencabutan izin usaha**.

**Peringatan penting dari riset:** label tidak menyelamatkan. Menyebut transaksi sebagai "permintaan pemesanan" tidak menetralkan Pasal 9 bila UI tetap menyiratkan ketersediaan pasti — pasal itu menilai substansi penawaran.

**Retribusi TPU.** UU No. 1/2022 (HKPD) merasionalisasi Retribusi Jasa Umum dan menghapus "pelayanan pemakaman dan pengabuan mayat" dari daftar; **Perda DKI Jakarta No. 1/2024** menghapus pungutan atas IPTM. Artinya untuk TPU DKI **tidak ada retribusi sah yang bisa ditagih platform**. Apa pun yang ditagih di muka untuk TPU hanya boleh berupa fee jasa pengurusan Makam.co.id sendiri, **dipisah sebagai baris tersendiri**, dan tidak boleh dipresentasikan sebagai "biaya makam" — menagih untuk sesuatu yang Perda gratiskan adalah paparan Pasal 10.

Konteks yang memperkuat: Juni 2026 Kadis Pertamanan DKI menyatakan pungli pada pemakaman gratis masih terjadi. Platform yang menagih bundel tidak terinci berisiko menjadi calo baru yang justru ingin digantikannya.

**PPN atas uang muka.** UU PPN Pasal 11 ayat (2): pembayaran diterima sebelum penyerahan jasa ⇒ saat terutangnya pajak adalah **saat pembayaran**. DP memicu PPN dan kewajiban faktur pajak sebelum jasa diberikan; refund menimbulkan pembatalan/penggantian faktur.

### 6. Mekanika reservasi

**Okupansi TPU Jakarta >95%, mendekati 100%** — mayoritas TPU praktis hanya dapat melayani **sistem tumpang** (sumber: publikasi Pemprov DKI). Ini temuan produk, bukan sekadar teknis: jalur dominan At-Need di TPU adalah tumpang, bukan plot baru.

**Makam Tumpang bukan inventaris.** Ia adalah *pengecekan kelayakan* atas status makam eksisting, jarak waktu pemakaman sebelumnya, dan hak ahli waris. Ia seharusnya **tidak mengambil hold plot sama sekali**.

**TTL 15 menit salah untuk checkout yang mengandung konfirmasi manusia berjam-jam.** Norma industri: hold pendek (5–20 menit) ketika checkout sinkron dan mandiri; jam-ke-hari hanya ketika ada sesuatu yang sudah dikomitkan (tarif, otorisasi kartu) yang membuat hold tidak gratis. Memanjangkan hold tak berbayar menjadi berjam-jam mensterilkan inventaris langka persis saat permintaan memuncak.

Pola *request-to-book* (Airbnb): tamu **tidak ditagih** selama jendela menunggu; bila tuan rumah menolak atau tidak merespons dalam 24 jam, tidak ada tagihan. Varian India menagih lalu me-refund — didorong kendala rail pembayaran yang mirip Indonesia.

**Kesenjangan state model saat ini.** `PlotReservationState` memiliki `held / confirmed / released / expired / converted`. Yang hilang:
- **`PENDING_OPERATOR`** — jam SLA operator, bukan jam TTL hold
- **`CONFLICTED`** — plot ternyata sudah terjual di luar sistem; dijamin akan terjadi karena pandangan platform atas ketersediaan selalu berpotensi basi

Tidak ada pula diferensiasi TTL/SLA per jenis layanan.

## Rekomendasi

Dua produk pembayaran terpisah, bukan satu alur.

| | Pre-Need | Urgent / At-Need |
|---|---|---|
| Uang di muka | DP kecil & flat (indikasi 5–10% harga plot, dibatasi nominal bulat, **dikreditkan ke harga**) | **Tidak ada uang sebagai syarat dispatch** |
| Prasyarat menagih | Lolos *soft check* ketersediaan + operator ack | — |
| Rail | QRIS / e-wallet / kartu saja | — |
| Pelunasan | Setelah konfirmasi; VA boleh di sini | Ditagih setelah plot & harga final dikonfirmasi |
| Soft hold | 15 menit | 2–4 jam |
| SLA operator | 24–72 jam, auto-decline boleh | 30–60 menit, **eskalasi ke manusia, jangan pernah auto-decline** |
| Refund | Penuh bila platform/operator tak sanggup; hangus hanya bila customer batal **setelah** konfirmasi | Selalu penuh, tidak pernah hangus |
| Makam Tumpang | — | Bukan inventaris: `ELIGIBILITY_REQUESTED → VERIFIED → SCHEDULED`, tanpa hold plot |

Alasan At-Need tanpa uang di muka bersifat ganda: **hukum** (paparan Pasal 9(1)e paling tajam justru saat ketersediaan paling tidak pasti dan keluarga paling rentan) dan **komersial** (di sanalah volume refund akan tertinggi sekaligus biaya reputasi keterlambatan refund paling mahal).

SLA refund yang disarankan: **≤ 3 hari kerja** pada rail e-wallet/QRIS, dengan batas atas mengacu tenggat 7 hari Pasal 19 ayat (3).

## Yang wajib diputuskan sebelum implementasi

1. **Opini hukum tertulis** atas: apakah menagih DP sebelum konfirmasi melanggar Pasal 9(1)e / Pasal 16 UU 8/1999, dan apakah pengungkapan ala Pasal 39(1) f–g PP 80/2019 cukup menetralkannya. Ini eksposur pidana.
2. **Batas "merchant of record" vs "menahan dana pihak lain"** menurut PBI 23/6/PBI/2021 dan UU 3/2011. Tidak ada norma yang menetapkan ambangnya.
3. **Legalitas menagih biaya apa pun di muka untuk TPU**, termasuk apakah fee jasa pengurusan boleh dipungut atas layanan yang Perda gratiskan.
4. **PP 9/1987** — disebut melarang TPBU dikelola secara komersial. **Sumber sekunder, gagal diverifikasi ke teks primer.** Bila benar, menyentuh fondasi model bisnis TPS.
5. **Status PPN** jasa pemakaman dan perlakuan faktur atas DP yang direfund.
6. **Kesiapan operasional refund**: siapa PIC-nya, SLA berapa, dan apakah rail payout akan dipakai.

Butir 1–5 memerlukan penasihat profesional. Riset ini tidak menggantikannya.

## Perubahan yang tidak menunggu keputusan di atas

Satu perbaikan bersifat aman dan berlaku untuk model mana pun: **berhenti menampilkan kegagalan kepada customer yang pemesanannya sebenarnya berhasil tercatat.** Saat guard menolak dengan pola "menunggu operator" (kondisi 2/3/4 pada order yang baru dibuat), itu adalah jalur yang diharapkan, bukan error — dan harus dikomunikasikan sebagai pesanan diterima + pembayaran akan diatur setelah konfirmasi, bukan layar merah.

## Sumber

Operator & industri:
- https://sales-sandiegohills.com/ketentuan-booking-fee/
- https://marketing-sandiegohillskarawang.com/kredit-kepemilikan-lahan-makam/
- https://www.makamalazhar.co.id/pertanyaan-umum/
- https://lestarimemorialpark.net/faq/
- https://kamboja.co.id/layanan-rumah-duka-kamboja/
- https://nirvanamemorial.com.sg/faqs/

Gateway & rail pembayaran:
- https://docs.midtrans.com/reference/card-feature-pre-authorization
- https://docs.midtrans.com/reference/auth-payment-api-gopay-tokenization
- https://docs.midtrans.com/docs/what-payment-method-that-have-refund-feature
- https://docs.xendit.co/docs/cards-capturing-a-card-payment
- https://help.xendit.co/hc/en-us/articles/4417382730137-Can-I-ask-for-a-Xendit-virtual-account-refund-on-the-transaction-that-has-been-paid
- https://www.xendit.co/en-id/products/automated-payouts/
- https://developers.doku.com/

Regulasi:
- UU 8/1999 — https://jdih.kemenkeu.go.id/api/download/fulltext/1999/8TAHUN~1999UU.htm
- PP 80/2019 — https://peraturan.go.id/files/pp80-2019bt.pdf
- Permendag 31/2023 — https://peraturan.bpk.go.id/Details/265202/permendag-no-31-tahun-2023
- PBI 23/6/PBI/2021 — https://www.bi.go.id/id/publikasi/peraturan/Documents/PBI_230621.pdf
- UU 3/2011 Transfer Dana — https://www.kemhan.go.id/itjen/wp-content/uploads/migrasi/peraturan/UU0032011.pdf
- PP 9/1987 Pemakaman — https://bphn.go.id/data/documents/87pp009.pdf
- Perda DKI 1/2024 — https://jdih.jakarta.go.id/dokumen/detail/13908
- Retribusi dihapus (UU HKPD) — https://news.ddtc.go.id/uu-hkpd-berlaku-14-layanan-di-daerah-ini-tak-lagi-dipungut-retribusi-1799897
- PPN uang muka — https://www.pajak.go.id/en/node/117951

Regulator luar negeri (pembanding):
- FTC Funeral Rule — https://consumer.ftc.gov/articles/ftc-funeral-rule
- CMA Funerals Market Investigation Order 2021 — https://www.gov.uk/government/publications/summary-of-the-funerals-market-investigation-order-2021/summary-of-the-funerals-market-investigation-order-2021
- NSW interment rights — https://www.cemeteries.nsw.gov.au/industry-regulation/interment-rights

Mekanika reservasi:
- https://www.airbnb.com/help/article/85
- https://agodahomeshelp.zendesk.com/hc/en-us/articles/360053665154-Accept-or-reject-a-booking-request-for-Book-on-Request-properties
- https://www.hellointerview.com/learn/system-design/in-the-wild/shopify-inventory-reservations
- https://docs.stripe.com/payments/place-a-hold-on-a-payment-method
- https://smartcity.jakarta.go.id/blog/351/pelayanan-pemakaman-secara-online

Kasus kegagalan:
- https://www.justice.gov/usao-edmo/pr/final-defendant-national-prearranged-services-inc-case-convicted-18-counts-fraud
- https://megapolitan.kompas.com/read/2026/06/17/15280251/masih-ada-pungli-pemakaman-gratis-di-jakarta-kadis-pertamanan-rt-dan-rw

## Catatan kepercayaan

**Terverifikasi dari sumber primer:** teks pasal UU 8/1999, PP 80/2019, PBI 23/6/2021, UU PPN; FAQ operator Al Azhar; dokumentasi gateway Midtrans/Xendit/DOKU; kebijakan Airbnb; okupansi & layanan online TPU DKI.

**Sumber sekunder / satu langkah dari operator:** seluruh angka San Diego Hills (situs agen resmi, saling konsisten); DP 20% Al Azhar; penghapusan retribusi Perda DKI 1/2024; penetrasi kartu 6–7%; jendela hold skema Visa/Mastercard.

**Tidak ditemukan:** klausul refund/pembatalan yang dipublikasi operator makam Indonesia mana pun; durasi hold booking fee di Indonesia; regulasi pre-need/trust fund pemakaman Indonesia; tenggat refund maksimum yang diwajibkan regulasi e-commerce.

**Belum terverifikasi ke teks primer:** PP 9/1987 dan larangan komersialisasi TPBU; struktur hak atas lahan memorial park swasta.

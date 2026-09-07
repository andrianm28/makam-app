# Remediasi Penuh Temuan Audit Makam.co.id — Implementation Plan

## Context

Audit teknik menyeluruh 23-dimensi dijalankan pada 6 September 2026 terhadap commit `53626f59` (identik dengan yang live di `dev.makam.co.id` dan `makam.co.id`/beta). Menghasilkan 342 temuan setelah dedup dan verifikasi adversarial dua lensa untuk setiap temuan Critical/High, ditambah fase ketiga yang menutup seluruh 33 celah yang fase pertama tandai tak-teruji. Laporan lengkap: `https://claude.ai/code/artifact/d331e5e4-456b-444d-b2c4-771a3be7ff61`. Data mentah semua 342 temuan (id, severity, dimensi, pernyataan terverifikasi, rekomendasi, file:line) ada di `/tmp/user/1000/claude-1000/-home-ubuntu-makam-app/2e81212b-3e2a-4bf3-8ced-ace5d15e3f91/scratchpad/coordinator/report-data.json` — **ini sumber kebenaran tunggal untuk daftar lengkap**, jangan diduplikasi ke file lain; plan ini mengutip hanya yang perlu dieksekusi segera secara rinci dan mendeskripsikan strategi batching untuk sisanya per AGENTS.md §Documentation.

Distribusi severity (setelah dedup, tidak termasuk 1 yang dibantah):

| Severity | Jumlah |
|---|---|
| Critical | 4 |
| High | 17 |
| Medium | 192 |
| Low | 105 |
| Info | 24 |

**Status validasi:** seluruh klaim teknis rinci di Phase 0-2 (nomor baris, tanda tangan method, nama kolom skema, keberadaan scope/helper) telah melalui satu putaran validasi independen tambahan setelah draf pertama — dua agen pembaca kode memeriksa ulang setiap file yang dikutip terhadap kondisi terkini, dan tiga kesalahan nyata ditemukan lalu dikoreksi: (1) asumsi keliru bahwa `config/horizon.php` punya environment key `beta` (tidak ada — hanya `production`/`staging`/`local`, dan beta hari ini tidak menjalankan Horizon sama sekali), (2) asumsi bahwa `PlotReservation::activeForOrder()` perlu dibuat baru (sudah ada dan sudah dipakai di produksi), dan (3) kesalahan aritmetika pada tabel batching Phase 3 (192 Medium yang dihitung hanya berjumlah 177 karena dua dimensi terlewat). Detail koreksi tertanam langsung di bagian yang relevan di bawah, ditandai "hasil validasi"/"CORRECTED".

Dua fakta operasional mengubah bentuk remediasi ini:

1. **Repositori kini PUBLIK** (dikonfirmasi `gh repo view --json visibility` → `PUBLIC`, sebelumnya diasumsikan privat pada rencana lama). Ini membuka proteksi cabang klasik dan GitHub Advanced Security yang sebelumnya diblokir oleh Free plan — `docs/planning/git-workflow.md` §0/§4/§8 (OQ-G1) sudah usang pada premis intinya dan perlu dikoreksi sebagai bagian dari remediasi CI-05.
2. **Temuan Critical `DOM-01` punya tenggat nyata sekitar 19 September 2026** — 9 pesanan live di `makam_beta` sudah menaut ke draft yang akan tersapu begitu draft tertua melewati jendela retensi 30 hari. Ini mengharuskan sebuah stop-gap yang berjalan lebih dulu daripada perbaikan penuh.

Metodologi eksekusi wajib mengikuti AGENTS.md §Development methodology: `brainstorming → writing-plans → subagent-driven-development → finishing-a-development-branch`, setiap unit kerja dalam worktree terisolasi di `.worktrees/`, satu PR per unit kerja terhadap trunk `docs/design-system-and-planning`. Plan ini SENDIRI adalah program multi-gelombang, bukan satu siklus SDD — setiap Task/Batch di bawah menjadi siklus SDD-nya sendiri (dokumen plan turunannya sendiri di `docs/superpowers/plans/<tanggal>-<slug>.md`, worktree sendiri, PR sendiri), kecuali dinyatakan lain.

## Global Constraints

- Setiap fix menyertakan test regresi nyata (AGENTS.md §Testing: "Every bug fix requires regression test"), dijalankan terhadap PostgreSQL 18 sungguhan via image pinned CI-parity, bukan SQLite, untuk apa pun yang menyentuh kolom `uuid` atau perilaku spesifik Postgres — lihat pola di memory `feedback_verify_against_real_db_not_sqlite`.
- `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress` (level 2, level CI), dan `bash ci/verify-docs.sh` harus tetap bersih di setiap PR.
- **Batas human review (AGENTS.md §Infrastructure-agent execution), berlaku untuk Task 0.3, 0.4, 1.2b, 1.4, dan proteksi cabang di Batch 2G:** agen menyiapkan perubahan (skrip, perintah `gh api`, konfigurasi) tetapi TIDAK mengeksekusinya sendiri di host produksi atau pengaturan keamanan repositori — manusia menjalankan dan memverifikasi. Ini bukan pembatasan berlebihan; ini persis batas yang AGENTS.md tetapkan untuk perubahan security/production-affecting/destructive-adjacent.
- Perubahan skema mengikuti expand/contract (AGENTS.md §Database); tidak ada `down()` destruktif yang diandalkan untuk rollback produksi.
- Setiap Action domain baru mengikuti pola `Audit::wrap()` yang sudah mapan (lihat referensi konkret di Task 1.1 dan Batch 2A di bawah) — closure mutasi, action, subject, outcome, actorRef, actorRole, source, correlationId.
- Commit git dan PR description mengakhiri dengan atribusi standar sesi ini.

---

## Phase 0 — Stop-gap segera (mulai hari ini, sebelum perbaikan penuh Phase 1 selesai direview)

Tujuan fase ini murni membeli waktu dan visibilitas dengan risiko serendah mungkin, lewat jalur PR normal (bukan perubahan host langsung), sehingga tenggat 19 September tidak terlewati sementara Task 1.1 yang lebih menyeluruh masih dalam review.

### Task 0.1: Nonaktifkan sementara sapuan penghapus draft yang destruktif (menutup DOM-01 sampai Task 1.1 landing)

**Files:**
- Modify: `routes/console.php:14` — comment-out atau beri guard `->skip(fn () => true)` sementara pada `Schedule::command('booking:purge-stale-drafts')->dailyAt('03:15')`, dengan komentar yang mengutip finding id dan tanggal target Task 1.1 selesai.
- Test: tambahkan/perbarui test scheduler yang mengonfirmasi task ini memang tidak terdaftar aktif selama guard berlaku (lihat pola test scheduler yang sudah ada untuk 5 task lain di `routes/console.php`).

**Verifikasi:** jalankan `php artisan schedule:list` di container pinned dan konfirmasi baris `booking:purge-stale-drafts` tidak muncul atau ditandai skip. PR ini expedited — minta review prioritas tinggi, sitir tenggat 19 Sep 2026.

### Task 0.2: Alert kedalaman karantina Document Vault (visibilitas sementara untuk COORD-07, sebelum Task 1.2 selesai)

**Files:**
- Create: sebuah scheduled command baru (mis. `app/Console/Commands/AlertStaleQuarantinedDocumentsCommand.php`) yang menghitung dokumen dengan `state` non-`accepted` lebih tua dari ambang (mis. 1 jam) dan menulis ke log/Sentry sebagai warning — TIDAK mengubah data, murni observability.
- Modify: `routes/console.php` — daftarkan `everyFiveMinutes()->withoutOverlapping()`, mengikuti pola `spine:watchdog` yang sudah ada di baris `:59`.
- Test: Feature test yang men-seed dokumen lama dalam state quarantine dan mengonfirmasi command melaporkan hitungan benar.

**Catatan penting:** JANGAN mengalihkan `ScanDocumentJob::dispatch(...)->onQueue(OutboxQueueName::Media->value)` ke antrean `default`/`urgent`/`critical` sebagai stop-gap. Sudah diverifikasi terhadap `docs/architecture/queue-and-outbox.md` §2-3: `media` sengaja diisolasi dengan timeout job 300 detik dan koneksi Redis terpisah (`redis_batch`, `retry_after` 1000 detik) dari `critical/urgent/notifications/default` yang timeout 90 detik dan `retry_after` 90 detik. Memindahkan job scan ke queue tersebut berisiko job scan mati di tengah jalan (timeout) dan sekaligus melanggar aturan "imports/reports/media must not starve critical or urgent queues" arah sebaliknya. Task 1.2 di Phase 1 menangani perbaikan sungguhan (menjalankan consumer `media` yang sesungguhnya), bukan penukaran queue.

### Task 0.3: Alert kedalaman `failed_jobs` pada antrean critical/urgent (COORD-15)

**Files:**
- Extend command Task 0.2 (atau command terpisah kecil) untuk juga menghitung `failed_jobs` berumur non-nol pada `queue IN ('critical','urgent')` dan melaporkannya.
- Beta saat ini punya 6 baris `failed_jobs` dari ledakan 25 Agustus (`PaymentCheckoutProviderException` HTTP 404) yang belum ditindak — agen TIDAK boleh retry/discard baris live tersebut sendiri (data produksi); command hanya melaporkan, keputusan retry/discard ada di Task 0.4.

### Task 0.4 (human-executed — siapkan, jangan eksekusi): tiga perintah host/GitHub yang perlu manusia menjalankannya hari ini

Bukan bagian dari PR; ini instruksi tersedia untuk operator manusia, disiapkan agen tapi tidak dijalankan agen, per AGENTS.md §Infrastructure-agent execution.

1. **Cadangkan `makam_beta` untuk pertama kalinya (CI-01, Critical).** Tambahkan `makam_beta` ke loop `for DB in makam_dev makam_stg` di `/opt/makam/scripts/pg-backup.sh:17`, jalankan sekali manual, verifikasi artefak muncul di `/opt/makam/backups/pg`. Ini stop-gap murni (backup pertama yang pernah ada); enkripsi, kadensi 4-6 jam, dan pengiriman off-disk adalah pekerjaan penuh di Task 1.4.
2. **Proteksi cabang trunk (bagian dari CI-05, sekarang tidak terblokir karena repo publik).** Jalankan perintah `gh api --method PUT` persis seperti yang tertulis di `docs/operations/runbooks/setup-cicd-self-hosted-runner.md:149-166` (sembilan required status check + 1 review). Verifikasi dengan `gh api repos/andrianm28/makam-app/branches/docs%2Fdesign-system-and-planning/protection`.
3. **Putuskan enam baris `failed_jobs`** (Task 0.3 hasil): retry atau discard, dengan operator manusia melihat pesan errornya di `makam_beta` langsung.

**Verifikasi Phase 0:** `php artisan schedule:list` mengonfirmasi Task 0.1; log/Sentry menunjukkan alert Task 0.2/0.3 berjalan setelah deploy; operator mengonfirmasi ketiga item Task 0.4 secara terpisah dari siklus PR.

---

## Phase 1 — Perbaikan penuh 4 temuan Critical

### Task 1.1: `PurgeStaleBookingDrafts` — guard dependensi penuh (menggantikan stop-gap Task 0.1)

**Files:**
- Modify: `app/Domain/Booking/Actions/PurgeStaleBookingDrafts.php:37` — predikat `where('updated_at', '<', $cutoff)` perlu ditambah exclusion terhadap draft yang punya order, funeral case, pre-need interest, atau plot reservation.
- Modify: `app/Domain/Booking/Models/BookingDraft.php` — model ini HANYA punya relasi outbound (`cemetery()`, `cemeteryPackage()`), tidak ada relasi inverse ke `Order`/`FuneralCase`/`PreNeedInterest`/`PlotReservation`. **Jangan tambahkan relasi Eloquent inverse** — `BookingDraftQuery::openForUser()` di file yang sama (baris `:80-95`) sudah mendokumentasikan alasan desain: relasi inverse akan menciptakan siklus model lintas-domain antara `Domain\Booking` dan `Domain\OrderWorkflow`/dst. Ikuti pola yang sama persis: gunakan `whereNotIn`/`whereNotExists` subquery terhadap tabel far-side (`orders.booking_draft_id`, `funeral_cases.booking_draft_id`, `pre_need_interests.booking_draft_id`, `plot_reservations.booking_draft_id`), bukan relasi model baru.
- Test: `tests/Unit/Domain/Booking/PurgeStaleBookingDraftsTest.php` (atau lokasi test yang ada) — ganti/tambah kasus: draft di belakang order (harus TIDAK terhapus), di belakang funeral case, di belakang pre-need interest, di belakang plot reservation, dan kasus draft benar-benar telantar (tetap terhapus). Test yang ada saat ini justru menegaskan perilaku salah (`test_a_stale_draft_with_a_live_plot_hold_is_still_purged`) — balik assersinya.

**Interfaces:** Consumes 4 tabel far-side lewat subquery read-only; tidak ada perubahan skema.

**Steps:**
1. Tulis test yang gagal untuk keempat kasus dependensi di atas.
2. Ubah predikat query di `PurgeStaleBookingDrafts.php:37` menjadi kombinasi `whereNotExists` subquery terhadap `orders`, `funeral_cases`, `pre_need_interests`, `plot_reservations`, masing-masing pada `booking_draft_id`.
3. Balik assersi test lama yang salah.
4. Jalankan `vendor/bin/phpunit tests/Unit/Domain/Booking/PurgeStaleBookingDraftsTest.php` via image CI-parity terhadap Postgres nyata.
5. Cabut guard Task 0.1 di `routes/console.php:14` dalam PR yang sama (aktifkan kembali scheduler dengan predikat yang sudah aman).

### Task 1.2: Pipeline dokumen — konsumen antrean `media` yang sesungguhnya (COORD-07)

**Koreksi penting hasil validasi:** `config/horizon.php` hanya punya TIGA environment key — `production` (:302), `staging` (:349), `local` (:359). **Tidak ada key `beta`.** Beta hari ini juga dikonfirmasi TIDAK menjalankan Horizon sama sekali — container `beta-worker` menjalankan perintah mentah `php artisan queue:work --queue=critical,urgent,notifications,default` (bukan `php artisan horizon`). Jadi memperbaiki `config/horizon.php` saja TIDAK memperbaiki apa pun di beta hari ini kecuali Horizon benar-benar diaktifkan di sana — dua pekerjaan berbeda, jangan dicampur.

**1.2a — kesiapan kode (PR normal, tidak bergantung host):**
- Modify: `config/horizon.php` — pastikan blok `production` sudah benar untuk `supervisor-batch` (queue `imports,media`, koneksi `redis_batch`, timeout 300s, `maxProcesses` bukan `0`/`false` — baca peringatan baris `:313-333` soal `ProvisioningPlan::toSupervisorOptions()` yang crash pada nilai salah). Ini kesiapan untuk MASA DEPAN jika Horizon diaktifkan di beta; bukan fix untuk hari ini.
- Test: Feature test config-level yang mengonfirmasi `ScanDocumentJob` terdaftar pada koneksi `redis_batch` dengan timeout 300s.

**1.2b (human-executed — perbaikan sesungguhnya untuk beta HARI INI, di luar repo ini):** Sebelum eksekusi, operator manusia harus mengonfirmasi `APP_ENV` sesungguhnya di host beta (nilai dotenv di luar cakupan agen). Jalur tercepat dan paling rendah risiko: JANGAN aktifkan Horizon di beta (perubahan besar); cukup perluas `command` container `beta-worker` di `/opt/makam/compose/compose.yml` menjadi dua container terpisah — worker existing tetap `--queue=critical,urgent,notifications,default`, TAMBAH satu container/proses baru `php artisan queue:work --queue=media,imports --timeout=300 --tries=3`. **Catatan risiko starvation**: prefer container terpisah (bukan satu command diperluas) persis karena `docs/architecture/queue-and-outbox.md` mendokumentasikan `media`/`imports` di supervisor terpisah dengan timeout 300s vs 90s pada queue lain — satu command yang menggabungkan keduanya berisiko job `media` yang lambat menahan slot job `critical`/`urgent`.

### Task 1.3: Bank-transfer step-up authentication + audit lengkap (SEC-02)

**Files:**
- Modify: `app/Filament/Admin/Resources/SiteSettings/Pages/EditSiteSettings.php:79-145` (method `save()`) — tambahkan `app(ReauthenticationGuard::class)->assertFresh($actor)` sebelum `DB::transaction` di baris `:92`, dengan try/catch persis pola yang sudah mapan di `app/Filament/Admin/Resources/MarketplaceOrders/Actions/MarkMarketplaceOrderPaidAction.php:77-100` (baca sebagai referensi utuh — actor resolve, catch `ReauthenticationRequiredException`, `session()->put(RequireRecentAuthentication::REASON_SESSION_KEY, 'bank_account_change')`, `session()->put('url.intended', ...)`, `redirect()->route(PasswordReauthentication::ROUTE_NAME)`).
- Modify: `EditSiteSettings.php` — perluas `Audit::record` yang sudah ada di baris `:132-141` agar metadata membawa nilai lama dan baru (atau hash) per kunci yang berubah, bukan hanya nama kunci (`$changed[]`, baris `:121`).
- Modify: `app/Filament/Admin/Resources/SiteSettings/Schemas/SiteSettingsForm.php:51-55` — tambahkan validasi cross-field: ketiga kunci `bank_transfer_*` harus diisi bersamaan atau tidak sama sekali (deskripsi baris `:55` sudah menyebutkan bahaya ini dalam teks tapi tanpa validasi nyata).
- Pertimbangkan (diskusikan di brainstorming siklus SDD Task ini): mempersempit `MasterDataAdminAuthorizer` (baris `:39-44`, saat ini admin/restricted_admin/operator/finance) khusus untuk bagian `bank_transfer_*`, karena `rbac-matrix.md` menempatkan operator sebagai "Tidak" untuk verifikasi pembayaran manual — kemungkinan perlu authorizer terpisah yang lebih sempit hanya untuk section ini, bukan seluruh halaman.
- Test: Feature test baru yang mengonfirmasi (a) sesi basi ditolak dengan redirect ke `PasswordReauthentication`, (b) audit metadata membawa nilai lama/baru, (c) perubahan parsial (hanya 1 dari 3 kunci bank) ditolak validasi.

### Task 1.4 (human-executed — siapkan skrip/dokumen, jangan eksekusi): backup produksi penuh

**Files (perubahan yang disiapkan agen untuk dijalankan manusia):**
- Draft perubahan `/opt/makam/scripts/pg-backup.sh`: tambah `makam_beta` ke loop, enkripsi output (mis. `gpg --encrypt` dengan kunci publik operator sebelum/sesudah `gzip`), tulis ke lokasi terpisah dari disk basis data.
- Draft perubahan `/etc/cron.d/makam-pg-backup`: naikkan kadensi beta dari harian ke 4-6 jam sekali, sesuai `docs/operations/database-backup-and-recovery.md:124-129`.
- Draft prosedur restore-test satu kali dan pencatatan buktinya.
- Koreksi `docs/operations/database-backup-and-recovery.md` bagian yang mengklaim strategi ini "sudah berlaku" untuk beta — ini bagian PR normal (dokumen, bukan host), bisa masuk siklus SDD yang sama.

**Verifikasi Phase 1:** setiap Task di atas — test-nya sendiri hijau terhadap Postgres nyata; `vendor/bin/pint --test`, `phpstan analyse`, `ci/verify-docs.sh` bersih; setelah merge dan deploy, konfirmasi ulang `booking_drafts`/`orders` di `makam_beta` (read-only) bahwa predikat baru benar-benar mengecualikan 9 pesanan yang sudah teridentifikasi; konfirmasi `documents` mulai berpindah dari quarantine ke accepted setelah Task 1.2 aktif.

---

## Phase 2 — 17 temuan High, dikelompokkan jadi 8 batch SDD

Setiap batch = satu siklus SDD (brainstorm → plan sendiri → worktree → PR). Urutan di bawah bukan urutan wajib strict, tapi Batch 2A dan 2G punya urgensi keamanan tertinggi setelah Phase 1.

### Batch 2A — Cakupan step-up authentication (SEC-03, SEC-04, SEC-05)

Tiga temuan ini berbagi akar dan pola perbaikan yang identik, layak satu siklus SDD:

- **SEC-03**: `CreateCertificateAction.php:169-217`, `RevokeCertificateAction.php:69-96`, `ReplaceCertificateAction.php:78-120` — ketiganya hanya memakai gate role `isIssuer()`, tidak ada `ReauthenticationGuard::assertFresh()`. Tambahkan pemanggilan persis setelah cek `isIssuer()` di ketiga `run()`, memakai pola `MarkMarketplaceOrderPaidAction.php:77-100` (7 call site lain sudah ada sebagai referensi: `BasePlotFloorMapPage.php:678`, `FeatureGateAdmin.php:207`, `GravePlotsTable.php:285`, `PreNeedCaseActions.php:657`, `TransitionOrderAction.php:198`, `RecordExternalRenewalPaymentAction.php:73`, `MarkExternalRenewalAction.php:115`).
- **SEC-04**: `app/Livewire/Public/Auth/ResetPasswordPage.php:70` — setelah `Password::reset()` callback, tambahkan `Auth::logoutOtherDevices($password)`. Juga tambahkan `\Illuminate\Session\Middleware\AuthenticateSession::class` ke grup middleware `web` di `bootstrap/app.php:39` (`$middleware->appendToGroup('web', ...)`), karena saat ini hanya tiga Filament panel provider yang memuatnya.
- **SEC-05**: `app/Platform/IdentityAccess/Adapters/LocalUsersTableIdentityAccessAdapter.php:97-112` (`resolveLastAuthenticatedAt()`) mengambil max `last_authenticated_at` lintas SEMUA sesi non-revoked milik user, bukan sesi yang sedang aktif. Perbaikan: tambahkan `public readonly ?string $sessionId = null` sebagai parameter kelima opsional di `app/Platform/IdentityAccess/ActorContext.php:77` (trailing-optional agar `ActorContext::guest()` di `:85-88` tidak berubah), populate dari sesi saat ini di titik konstruksi (`LocalUsersTableIdentityAccessAdapter.php:71-77`), dan ubah `resolveLastAuthenticatedAt()` memakai `->where('session_id', $sessionId)->first()` alih-alih `->value()` lintas-sesi. Perbaiki juga ketidaksesuaian dok/skema di migrasi `actor_sessions` (`2026_07_26_100000_create_actor_sessions_table.php:58-63` — dok bilang "nullable" tapi kolom sebenarnya NOT NULL, perbaiki komentarnya).

**Test:** perluas `tests/Feature/IdentityAccess/LocalUsersTableIdentityAccessAdapterTest.php` untuk kasus dua sesi aktif berbeda usia; tambah test baru certificate actions menolak sesi basi; tambah test `ResetPasswordPage` yang membuktikan sesi kedua untuk user yang sama benar-benar tercabut setelah reset.

### Batch 2B — IDOR marketplace + rendering uang minor-unit (MKT-01, MKT-09)

- **MKT-01**: `app/Livewire/Public/Marketplace/OrderTracking.php:59,61` (`$orderNumber`, `$customerRef` publik tanpa `Locked`) dan `Checkout.php:102,107,109,111` (`$idempotencyKey`, `$onlinePaymentAllowed`, `$orderPlaced`, `$placedOrderNumber`). Tambahkan `#[Locked]` (import `Livewire\Attributes\Locked` — saat ini tidak diimpor di kedua file) pada seluruh properti tersebut. **Dikonfirmasi aman**: `#[Locked]` di Livewire 4.4.1 hanya memblokir update yang datang dari payload client (`HandleComponents::updateProperties()`), bukan assignment PHP biasa di dalam method — jadi assignment server-side yang sudah ada (`$idempotencyKey` di `mount()` baris `:119`; `$orderPlaced`/`$placedOrderNumber` di `placeOrder()` baris `:226-227`) tetap berfungsi normal. Preseden yang sudah berjalan di repo ini: `BookingWizard.php` memakai `#[Locked]` pada `$draftId` (baris `:102`, di-reassign server-side di `:289,305,399,835`) dan `$currentStep` (baris `:154`, di-reassign di 9 tempat) — pola identik dengan yang diusulkan di sini. Sebagai defense-in-depth, re-derive `customerRef` dari `auth()`/`session()` di dalam `render()`, `fileComplaint()`, dan `submitManualProof()` alih-alih membaca properti langsung.
- **MKT-09**: tiga lokasi rendering uang salah — form vendor `app/Filament/Vendor/Resources/VendorListings/Schemas/VendorListingForm.php:57-62` (raw minor-unit, tanpa divide/multiply), form admin `app/Filament/Admin/Resources/Vendors/RelationManagers/ListingsRelationManager.php:87-91` (label klaim "Rp" tapi menulis minor-unit), tabel admin `ListingsRelationManager.php:156-160` (menampilkan `Rp 15000000` untuk listing Rp 150.000). Perbaiki mengikuti pola yang SUDAH BENAR di `app/Filament/Vendor/Resources/VendorListings/Tables/VendorListingsTable.php:39-41` (`->money('IDR', divideBy: 100)`), tambahkan `formatStateUsing`/konversi `*100` yang simetris di form.

**Test:** Feature test IDOR yang mengirim `customerRef` palsu lewat `/livewire/update` dan mengonfirmasi ditolak (dua file probe kosong sudah ada tak sengaja di `tests/Feature/Livewire/Public/Marketplace/AuthzCheckoutProbeTest.php` dan `AuthzIdorProbeTest.php` — **file-file ini kosong 0 byte, hapus di awal Batch ini** sebagai housekeeping, lalu isi ulang dengan test nyata alih-alih file placeholder); test harga admin/vendor yang membuktikan simetri rupiah↔minor-unit.

### Batch 2C — Rilis reservasi plot pada transisi terminal pesanan (UNBUILT-01)

- Reservasi plot yang berlabuh pada `order_id` (dibuat via `ConvertDraftHoldToOrderReservation.php:106-116` tanpa `expires_at`) tidak pernah dilepas oleh `CancelOrder`/`RejectOrder`/`ExpireOrder` (ketiganya dikonfirmasi hanya 29 baris pass-through murni ke `RecordOrderStatusChange`, tidak ada logika lain). Titik jahit paling tepat: di dalam closure mutasi `Audit::wrap()` pada `RecordOrderStatusChange.php:record()` (baris `:234-330`), tepat setelah `$current->applyStatus($event)` (baris `:276`) dan sebelum `emitStatusChanged()` (`:292`) — panggil `ReleasePlotReservation` ketika `$to` termasuk salah satu dari tiga state terminal non-`SELESAI` (`DIBATALKAN`, `DITOLAK`, `KEDALUWARSA` — dikonfirmasi baris `:46-48` di `OrderTransition::ALLOWED`, ketiganya array kosong).
- **`PlotReservation::activeForOrder(Order $order): ?self` SUDAH ADA** (`app/Domain/PlotReservation/Models/PlotReservation.php:177-186`, static method, bukan Eloquent scope — sudah dipakai di produksi oleh `ReservePlot.php:167`). Tidak perlu dibuat baru, cukup dipanggil. Closure mutasi `record()` HANYA me-lock `Order` (baris `:245`, `Order::query()->lockForUpdate()->findOrFail(...)` menjadi `$current`) — tidak ada `PlotReservation` yang sudah resolve, jadi butuh satu query tambahan: `PlotReservation::activeForOrder($current)`.
- **Urutan lock nested SUDAH ADA PRESEDENNYA**, bukan yang pertama: `ReservePlot.php` melakukan pola identik dalam satu `DB::transaction` — lock `Order` dulu (baris `:165`), baru lock `GravePlot` (baris `:174`), dengan dokblok kelas (baris `:41-56`) yang secara eksplisit menuliskan aturan "LOCK THE ORDER ROW FIRST". Ikuti urutan yang sama persis (order dulu, baru plot lewat `ReleasePlotReservation`).
- **Catatan desain**: `ReleasePlotReservation` sengaja memakai `Audit::record()` di dalam `DB::transaction`-nya sendiri, BUKAN `Audit::wrap()` (didokumentasikan di baris `:38-59` filenya — alasan atau divergensi plot hanya diketahui setelah plot ter-lock, sementara `Audit::wrap()` mengunci `$reason` di waktu pemanggilan). Memanggilnya dari dalam closure `Audit::wrap()` milik `RecordOrderStatusChange` berarti menaruh satu `DB::transaction` di dalam `Audit::wrap()` lain — aman (Laravel menangani sebagai savepoint) tapi dokumentasikan eksplisit di komentar kode.

**Test:** Feature test yang membuat pesanan dengan reservasi plot berlabuh order, membatalkan/menolak/mengedaluwarsakannya, dan mengonfirmasi `plot_reservations` benar-benar berpindah ke `RELEASED` dan `grave_plots.plot_state` kembali tersedia (kecuali divergen, ikuti pola `reasonWithDivergence()` yang sudah ada).

### Batch 2D — Validator Step 2 booking wizard mengabaikan tipe layanan (UXB-02)

- `app/Domain/Booking/Actions/SaveBookingDraftStep.php:85-92` memanggil `validateDeceasedData($payload)` tanpa mengetahui `service_type` (baris `:492-594`), sehingga jalur Pre-Need selalu gagal validasi. `service_type` HANYA tersedia dari step DISCOVERY (`:333-347`, tersimpan di `:111`), bukan di payload step 2 — solusinya membaca `$draft->service_type` (parameter `$draft` sudah ada di scope `__invoke`, baris `:85`) dan meneruskannya sebagai parameter kedua ke `validateDeceasedData(array $payload, ?string $serviceType)`.
- **Jangan lupa persistence**: arm persistence di baris `:119-141` menulis kelima kolom deceased tanpa syarat — jika validasi dibuat kondisional tanpa menyentuh persistence, draft Pre-Need akan menyimpan string kosong. Sesuaikan keduanya bersamaan.
- Untuk At-Need: nama lengkap dan hubungan tetap wajib; tanggal lahir (dan tanggal wafat bila tak diketahui) menjadi optional-tapi-divalidasi-jika-ada, sesuai teks form "Isi sebisa Anda" yang sudah ada tapi kontradiktif dengan validator saat ini.

**Test:** Feature test PRE_NEED yang membuktikan draft bisa lolos step 2 tanpa data deceased; test AT_NEED yang membuktikan nama/hubungan tetap wajib.

### Batch 2E — Kelengkapan notifikasi (NOTIF-02, NOTIF-03)

- **NOTIF-02**: `ProvisionalAggregateNotificationSubjectSource.php:158-167` hanya memetakan 4 tipe aggregate. Explore agent menemukan bahwa **hanya `vendor_order` yang punya kolom kontak nyata** (`customer_name`/`customer_phone`/`customer_email` inline di `vendor_orders`). `marketplace_order` punya `customer_ref` tapi untuk tamu itu adalah PHP session id, bukan alamat — perlu sumber kontak lain (kemungkinan dari `marketplace_orders`' delivery/recipient side, verifikasi di siklus SDD-nya). `payment_session` dan `work_evidence` **tidak punya kolom customer sama sekali** dalam 1-2 hop — jangan dipaksakan tanpa perubahan skema; scope-kan batch ini ke `vendor_order` dan `marketplace_order` dulu (jika kontak marketplace_order ditemukan), dan catat `payment_session`/`work_evidence` sebagai perlu perubahan skema terpisah kalau memang harus dikirim notifikasi.
- Perhatikan catatan dokumen migrasi bahwa `vendor.evidence_uploaded.v1` ditulis oleh `Domain\VendorFulfillment\Actions\UploadEvidence`, BUKAN tabel `work_evidence` — dua hal berbeda meski nama mirip; verifikasi domain mana yang benar sebelum menulis arm baru.
- **NOTIF-03**: seed migrasi `2026_08_09_100020_seed_notification_templates_from_matrix.php` menaruh placeholder di versi 1, yang **immutable secara database** (trigger di migrasi `2026_08_09_100010`, baris `:103-142`). Perbaikan WAJIB berupa migrasi baru yang menyisipkan baris versi 2 dengan SEMUA kolom terisi: `template_id` (FK), `version=2`, `subject` (satu-satunya kolom nullable, tapi isi dengan subjek Indonesia asli), `body` (NOT NULL, body Indonesia asli), `variable_allowlist` DAN `restricted_fields` (KEDUANYA wajib berupa JSON array — CHECK constraint gabungan `notification_template_versions_json_arrays_check` baris `:71-76` menuntut `jsonb_typeof(variable_allowlist) = 'array' AND jsonb_typeof(restricted_fields) = 'array'`, bukan hanya satu kolom), `created_by` (string, NOT NULL, konvensi `'seed:notification-matrix'`), `created_at` (NOT NULL — tabel ini TIDAK punya kolom `updated_at`, isi manual). Lalu mem-flip `notification_templates.active_version_id` (kolom nullable biasa, bukan trigger-protected) — JANGAN PERNAH UPDATE/DELETE baris versi 1. Perhatikan juga unique index parsial `(template_id, version)` dan FK komposit `(active_version_id, id)` → `(id, template_id)` (baris `:37-40`, `:46-50`, `:63-68`) — versi baru tidak bisa "dipinjam" ke template lain.

**Test:** test yang memverifikasi setiap event yang sebelumnya nol-penerima sekarang resolve minimal satu penerima; test migrasi versi-2 yang membuktikan versi 1 tetap utuh dan trigger immutability masih menolak mutasi terhadapnya.

### Batch 2F — Perbaikan tampilan jam kunjung seragam (COORD-05 / STAT-03)

Temuan trivial, kandidat digabung ke PR mana pun yang menyentuh `app/Filament/Admin/Resources/CemeteryVisitationPolicies/` lebih dulu, atau PR mandiri kecil:
- `app/Filament/Admin/Resources/CemeteryVisitationPolicies/Tables/CemeteryVisitationPoliciesTable.php:64` (sekitar) — baca `$uniformPair['open']`/`$uniformPair['close']` (kunci asosiatif), bukan `$uniformPair[0]`/`[1]`.
- Test: assersi baru untuk string "Setiap hari 08.00-17.00" pada kasus jam seragam — grep sebelumnya menunjukkan tidak ada satu pun test yang mengandung teks "Setiap hari".

### Batch 2G — Pengerasan CI/CD dan tata kelola (CI-02, CI-03, CI-05, COORD-08, COORD-17, DS-01)

- **CI-02**: `/opt/makam/compose/compose.yml` (host, di luar repo) tidak punya volume untuk `storage/app` — setiap redeploy menghapus dokumen tersimpan. Draft perubahan compose (volume `beta_storage`/`dev_storage`, mount ke `beta-web`/`beta-worker`/`beta-scheduler`) untuk dieksekusi manusia; tambahkan `storage/app` ke cakupan backup runbook (bagian ini bisa jalan sebagai perubahan dokumen dalam PR normal).
- **CI-03**: tidak ada `php.ini` di image runtime — `upload_max_filesize=2M` default PHP mengalahkan cap 10MB dokumen. Tambahkan fragment `zz-app.ini` di `Dockerfile` antara baris `:187` (setelah blok opcache) dan `:189` (`WORKDIR`), SEBELUM `USER www-data` di `:223` (folder `conf.d` tidak writable oleh www-data). Set `upload_max_filesize=12M`, `post_max_size=14M` (di bawah `client_max_body_size 15m` nginx), `memory_limit=256M`, `expose_php=Off`.
- **CI-05**: setelah proteksi cabang aktif (Task 0.4 manusia), perbarui `.github/workflows/ci.yml:20-23` — arahkan `pull_request: branches:` ke `docs/design-system-and-planning`, bukan `master` yang sudah pensiun. Koreksi `docs/planning/git-workflow.md` §0/§4/§8 (OQ-G1) yang masih mengklaim repo privat — repo sudah publik, seluruh premis "protection is gated" perlu dicabut atau ditulis ulang sebagai closed. **Catatan konsekuensi:** required status check di proteksi cabang memakai string nama job (`name:` di ci.yml) — dokumentasikan eksplisit bahwa mengganti nama job di masa depan akan diam-diam meng-unblock check tersebut kecuali proteksi cabang diperbarui bersamaan.
- **COORD-08**: tambahkan retry (mis. `nick-fields/retry` action atau loop bash) pada step `composer audit --locked` dan `npm audit` di `ci.yml:323-340`, bedakan advisory-database-unreachable (retry lalu warn) dari advisory sungguhan (fail closed).
- **COORD-17**: bukan perubahan kode — tuliskan keputusan eksplisit di `AGENTS.md` (atau aktifkan required-review di proteksi cabang Task 0.4, yang otomatis menutup celah ini sekaligus) tentang siapa yang review perubahan security/financial. Rekomendasi: manfaatkan `required_pull_request_reviews[required_approving_review_count]=1` yang sudah ada di payload Task 0.4 poin 2 — itu SATU perubahan yang menutup CI-05 dan COORD-17 sekaligus.
- **DS-01**: gambar hero 7,2 MB. Generate turunan responsif AVIF/WebP (640/960/1440px, ≤120KB), ganti `<img>` tunggal di `resources/views/components/mk/hero.blade.php:68` dengan `<picture>`/`srcset`+`sizes`, tambahkan `width`/`height` eksplisit (cegah CLS) dan `fetchpriority="high"`, hapus file asli 6,9MB dari repo, tambahkan pemeriksaan bobot gambar ke `ci/verify-docs.sh`.

**Test/Verifikasi:** `docker-php-ext-` extension assertion existing di Dockerfile tetap lolos; build image lokal via container pinned dan `php -i | grep upload_max_filesize` mengonfirmasi 12M; Lighthouse/ukuran file untuk hero image; `gh api .../protection` mengonfirmasi sembilan check + 1 review setelah Task 0.4.

### Batch 2H — (digabung ke atas; tidak ada temuan High tersisa di luar 2A-2G)

Cross-check: 3 (2A) + 2 (2B) + 1 (2C) + 1 (2D) + 2 (2E) + 1 (2F) + 6 (2G) = 16. Item ke-17 (`FIL-04`, kelengkapan aksi Filament untuk `PaymentVerification`) belum tercakup di atas — tambahkan sebagai:

### Batch 2I — Aksi keputusan verifikasi pembayaran manual di admin (FIL-04)

- `app/Filament/Admin/Resources/PaymentVerifications/PaymentVerificationsResource.php:116-122` hanya mendaftarkan `index`+`view`, dan `Pages/ViewPaymentVerification.php` adalah subclass `ViewRecord` kosong (dokblok-nya sendiri menyatakan "no decision action either"). Tambahkan header action approve/reject yang memanggil `App\Platform\Payment\VerifyManualPayment::verify()` (`app/Platform/Payment/VerifyManualPayment.php:145-185`, sudah lengkap dengan `Audit::wrap()`, row lock, dan `PaymentVerificationDecision`) — signature butuh `reason` (wajib), `actorRef`, `actorRole`, `source`. **Template terbaik untuk bentuk aksi ini, dikonfirmasi lebih pas daripada `MarkMarketplaceOrderPaidAction`**: `app/Filament/Admin/Resources/RenewalOrders/Actions/RecordExternalRenewalPaymentAction.php` (121 baris) — satu-satunya aksi yang sudah menggabungkan ketiganya sekaligus: form `Textarea::make('reason')->required()` (baris `:49-59`), transition authorizer (`:60`) DAN `ReauthenticationGuard::assertFresh()` (`:62-90`, urutan: authorize dulu baru re-auth), plus catch block `ReauthenticationRequiredException` yang identik pola Batch 2A. Salin strukturnya persis, bukan `MarkMarketplaceOrderPaidAction` yang tidak punya transition authorizer terikat record.
- Tambahkan aksi setara untuk refund/chargeback yang memanggil `RecordRefund`/`RecordChargeback` (`app/Platform/Payment/Actions/RecordRefund.php:61`, `RecordChargeback.php:42`) — controller `RecordPaymentReversalController.php:91` saat ini tidak menangkap domain exception sama sekali karena memang tidak ada UI admin yang memanggilnya; setelah aksi Filament ditambahkan, tambahkan juga penanganan exception yang layak di controller tersebut.

**Test:** Feature test yang membuktikan admin finance bisa approve/reject verifikasi lewat panel (bukan hanya route mentah), termasuk penolakan saat sesi basi.

---

## Phase 3 — 192 temuan Medium: strategi batching (bukan daftar per-item)

Setiap Medium finding punya evidence dan rekomendasi lengkap di `report-data.json`. Alih-alih menulis 192 task terpisah di sini (melanggar keterbacaan plan dan AGENTS.md §Documentation soal duplikasi), Medium dikelompokkan menjadi batch tematik berikut, masing-masing dijadwalkan sebagai siklus SDD terpisah SETELAH Phase 1-2 landing dan diverifikasi di produksi. Urutan prioritas kasar mengikuti dampak keamanan/finansial dulu, lalu operasional, lalu dokumentasi/kualitas.

| Batch | Dimensi sumber | Jumlah | Fokus (satu kalimat) |
|---|---|---|---|
| M1 — Ledger & payment robustness | PAY, QUE | 14 | Idempotensi settlement kedua, status MANUAL_REVIEW yang hilang, audit anomali yang tertimpa transaksi pembungkus, event outbox yang tak tertulis pada settlement marketplace/renewal. |
| M2 — Otorisasi & scoping | AUTHZ, SEC | 9 | Cemetery-scope fail-open untuk actor tanpa grant, logout tak mencabut sesi, audit trail login tak tercatat, sesi tunggal tanpa timeout absolut. |
| M3 — Integritas data & skema | DB, DOM | 10 | FK hilang di VendorFulfillment/CareSubscription, tiga representasi uang tak konsisten, guard payment-session vs plot-reservation, TTL reservasi order-anchored (residual setelah Batch 2C). |
| M4 — Kontrak & dokumen event | CONTRACT, API | 14 | Rekonsiliasi dua-arah 11 dokumen kontrak vs kode (detail lengkap sudah ada dari fase 3 audit), openapi.yaml vs rute nyata. |
| M5 — Arsitektur & kualitas kode | ARCH, STAT | 17 | Domain logic bocor ke Livewire/Filament, exception hierarchy datar, status handling ganda enum/const-string, coupling Platform→Domain terbalik. |
| M6 — Design system & UX | DS, UXB, UXO, I18N | 27 | Token/warna, ukuran tombol non-standar, label status berbahasa Inggris, validasi URL segment, artikel FAQ usang. |
| M7 — Performa | PERF | 12 | N+1 query, widget dashboard tanpa cache, job tak berbatas jendela, tabel analytics tanpa pruning. |
| M8 — Notifikasi & observability | NOTIF, OBS | 14 | Retry sempit, delivery gagal tanpa alert, correlation id putus di queue hop, audit_events tanpa enforcement DB-level. |
| M9 — Vault & keamanan file | VAULT | 5 | Upload produk langsung ke disk publik tanpa scan, CSV rekonsiliasi tanpa karantina, tanpa enkripsi-at-rest. |
| M10 — CI/CD & dependency hygiene | CI, DEP, TEST | 17 | Base image tertinggal patch, `pecl install` tak terverifikasi, gate migrasi destruktif blind spot (`->change()`/rename, helper setelah `down()`), `build-image` tak menunggu browser/load test. |
| M11 — Runbook & dokumentasi operasi | RUNBOOK, DESIGNDOC, DOC | 26 | 14 runbook menyebut topologi lama (rollback, redis-hardening, rotate-db-access semua masih menyebut layanan yang tak ada lagi di compose.yml nyata), design-system.md menyatakan premis usang. |
| M12 — Belum dibangun & edge case (terverifikasi fase 3) | UNBUILT, EDGE | 7 | Anchor billing CareSubscription bergeser bulan, GenerateCycle di luar transaksi, deactivated-service edge case draft, siblings PurgeStaleBookingDrafts (tidak ditemukan lain — hanya PurgeStaleBookingDrafts sendiri). |
| M13 — Sisa terverifikasi (COORD) | COORD | 5 | Env-leak test isolation, 22 container disposable menganggur, drift versi Postgres/Redis di version-matrix.yml. |
| M14 — Kelengkapan marketplace & panel Filament | MKT, FIL | 15 | Work order care-subscription tanpa vendor/jadwal dan tanpa transisi InProgress/Completed, cart tanpa re-cek stok/aktif, catatan vendor tertimpa komplain, penulisan panel vendor tak teraudit, moderation-case tanpa gate authorize, evidence marketplace tanpa jalur tulis, AcceptAgreement actor hardcode, override plot tanpa alasan. |

**Koreksi hasil validasi ulang (jangan diulang):** tabel versi awal plan ini keliru menghitung total M1-M13 sebagai 192 padahal jumlah sebenarnya 177 — dua dimensi (`MKT` 9 item, `FIL` 6 item, total 15) tidak masuk kelompok manapun, dan baris M12 salah tulis 14 padahal seharusnya 7 (`UNBUILT` 3 + `EDGE` 4). Baris M14 di atas menutup kekurangan itu. Total terverifikasi ulang: 14+9+10+14+17+27+12+14+5+17+26+7+5+15 = **192**, cocok dengan hitungan `report-data.json`.

**Proses per batch:** saat dijadwalkan, jalankan `grill-spec` atau `superpowers:brainstorming` terhadap subset finding batch tersebut (baca detail lengkapnya dari `report-data.json` terfilter `dim IN (...)`), tulis plan turunan sendiri di `docs/superpowers/plans/<tanggal>-<slug>.md`, worktree sendiri, PR sendiri — kemungkinan satu batch besar (mis. M11 dengan 26 item dokumen) dipecah lagi menjadi 2-3 PR dokumen murni per sub-tema jika ukurannya menyulitkan review.

**M13 sub-catatan operasional (bisa jalan independen dan segera, tanpa menunggu jadwal batch):**
- `docker rm -f` 22 container disposable (`sentryscrub-*`, `financeauthfix-*`, `c2fix-*`, `idorfix-*`, `predemo-*`, `filpatch-*`, `t5review-*`, `plotmap-*`, `task1-test-*`, `csp-nonce-*`, `e2e-admin-vendor-*`) dan `docker volume prune` — ini pembersihan host murni, human-executed, tidak menyentuh kode, aman dijalankan hari ini juga bersamaan Task 0.4.
- Perbarui `ci/version-matrix.yml` `verified_on_host` untuk postgresql (18.4→18.6) dan redis (8.2.7→8.2.9); pertimbangkan generate field ini otomatis alih-alih hand-maintained.

---

## Phase 4 — 105 Low + 24 Info: housekeeping, bukan jadwal tersendiri

Strategi: **jangan buat siklus SDD khusus untuk Low/Info sebagai kelompok besar.** Sebagian besar adalah kebersihan dokumentasi, penamaan, atau observasi. Aturan praktis:
- Setiap kali sebuah PR dari Phase 1-3 sudah menyentuh sebuah file yang juga punya temuan Low/Info di file yang sama, sertakan perbaikan Low/Info tersebut dalam PR yang sama (biaya marjinal kecil, review tetap fokus).
- Untuk Low/Info yang murni dokumentasi dan tidak tersentuh Phase 1-3 manapun, jadwalkan SATU PR "housekeeping" per akhir program (setelah Phase 3 selesai), dikerjakan oleh `doc-steward` agent, mengambil sisa daftar dari `report-data.json` yang belum tercakup.
- 2 file test kosong (`AuthzCheckoutProbeTest.php`, `AuthzIdorProbeTest.php`) yang tertinggal tak sengaja dari sesi audit — hapus di awal Batch 2B (sudah dicatat di sana) atau lebih cepat jika Batch 2B belum dijadwalkan; ini bukan temuan audit, murni artefak proses yang perlu housekeeping.

---

## Sequencing & Dependencies

```
Hari ini:     Task 0.1, 0.2, 0.3 (PR paralel, expedited review)  +  Task 0.4 (manusia, paralel)
Minggu 1-2:   Task 1.1, 1.2a, 1.3  (Phase 1 code)   ‖   Task 1.2b, 1.4 (manusia)
Minggu 2-4:   Batch 2A → 2I (Phase 2), boleh paralel antar-batch karena file overlap minimal
                kecuali: 2A dan 2I sama-sama menyentuh pola ReauthenticationGuard —
                selesaikan 2A dulu agar 2I mewarisi konvensi yang sama, bukan blocking teknis.
Sesudahnya:   Phase 3 (M1..M13) dijadwalkan bertahap sesuai kapasitas tim, prioritas M1 > M2 > M3 dulu.
Terakhir:     Phase 4 housekeeping PR.
```

Dependensi keras satu-satunya: Task 1.1 harus merge (atau minimal Task 0.1 aktif) sebelum tenggat 19 September 2026 — ini satu-satunya item dengan jam pasir nyata di seluruh program.

## Verifikasi end-to-end

Untuk setiap PR dalam program ini:
1. `vendor/bin/pint --test`, `vendor/bin/phpstan analyse --no-progress`, `bash ci/verify-docs.sh` — bersih.
2. Test baru/diubah dijalankan via `vendor/bin/phpunit <path>` di image CI-parity (`docker run ... --entrypoint php <image> vendor/bin/phpunit`) terhadap PostgreSQL 18 sungguhan (bukan SQLite) untuk apa pun yang menyentuh kolom uuid, constraint, atau timing job.
3. CI GitHub Actions penuh hijau sebelum merge (baca hasil run sungguhan via `gh run view`, bukan hanya gate lokal — lihat memory `feedback_local_verify_docs_does_not_cover_all_ci`).
4. Setelah deploy ke dev/beta, verifikasi read-only tambahan terhadap `makam_beta` untuk Task 1.1 (predikat baru benar-benar mengecualikan 9 pesanan yang teridentifikasi) dan Task 1.2 (dokumen mulai berpindah dari quarantine).
5. Setiap fix keamanan (Batch 2A, Task 1.3, Batch 2I) mendapat review kedua sebelum merge — bukan hanya CI hijau — mengikuti keputusan Task 0.4 poin 2/COORD-17 soal required-review.

## Referensi

- Laporan audit lengkap (artifact): `https://claude.ai/code/artifact/d331e5e4-456b-444d-b2c4-771a3be7ff61`
- Data mentah 342 temuan: `.../scratchpad/coordinator/report-data.json`
- Cakupan pemeriksaan (955+110 checks, 28 dimensi): `.../scratchpad/coordinator/report-meta.json`

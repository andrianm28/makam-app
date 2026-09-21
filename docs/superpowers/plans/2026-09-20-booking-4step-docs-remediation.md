# Booking 4-Step Docs Remediation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Align every user-facing narrative and living doc to the canonical 4-step booking flow and fix the two stale FAQ seed articles plus their locking test.

**Architecture:** Docs-plus-seed-content remediation behind the existing public-render and catalog-drift seams; zero domain-logic changes. A new migration rewrites the two FAQ articles (never edit the old seed migration), the locking test is updated atomically in the same change, and living docs are revised minimally with an explicit historical note.

**Tech Stack:** PHP 8.5, Laravel 13, Livewire 4, PostgreSQL 18, PHPUnit feature tests

**Spec:** `.scratch/booking-4step-docs-remediation/spec.md`

**Gerbang specflow:** rencana ini BELUM siap dieksekusi sampai kedua perintah
di bawah keluar dengan status 0. Controller yang membaca header ini: kalau
salah satu belum dijalankan, jalankan dulu; kalau ada yang gagal, perbaiki
rencananya, jangan melewati gerbangnya.

    <akar specflow>/scripts/check-plan-headings.sh    <rencana ini> <task-brief>
    <akar specflow>/scripts/check-seam-constraints.sh <rencana ini> <task-brief>

## Global Constraints

- Empat langkah adalah kanonis; sembilan langkah adalah historis. Setiap narasi pengguna memakai empat tahap: cari dan pilih, data pemesan dan almarhum, pembayaran, konfirmasi.
- Ringkasan adalah kartu persisten pada tahap data, bukan tahap bernomor sendiri.
- Fallback pembayaran manual adalah bagian permanen dari tahap pembayaran, bukan opsi paralel; cabang online hanya dirender saat gate pembayaran terbuka.
- Perubahan teks seed FAQ dilakukan bersama test penguncinya dalam satu perubahan atomik.
- Dokumen MVP dan inventaris screen direvisi minimal dengan catatan historis, tanpa rewrite sejarah keputusan.
- Invoice dan timeline detail per-order tetap di luar spec ini bila belum dibangun; konfirmasi booking tidak mengarang tautan yang tidak ada.
- Scheduler pengingat per makam per jendela TIDAK dibangun di sini (akui belum dibangun; spec terpisah).
- Upload dokumen almarhum di dalam wizard TIDAK dibangun di sini (tetap pesan jujur tanpa upload; vault domain terpisah).
- Autosave berjangka sepuluh detik dan adopsi draft anonim saat login TIDAK dibangun di sini.
- Precondition makam tumpang, SLA Urgent, dan availability per layanan TIDAK dibangun di sini.
- Superseding ADR-0038 TIDAK termasuk di sini.
- PHP tests hanya berjalan di container PHP 8.5 atau CI job PHP; host ini (PHP 8.3.6, tanpa vendor di worktree) tidak bisa menjalankannya — jangan pernah mengklaim hijau dari baseline yang tidak berjalan.

---

## File Structure

- `database/migrations/2026_09_20_100000_update_faq_booking_steps_four_step.php` (new): rewrites the `summary` and `body` of the `bagaimana-cara-memesan-makam` and `kapan-pembayaran-dapat-dilakukan` articles to 4-step copy. Single responsibility: seed-content correction.
- `tests/Feature/Livewire/Public/Faq/FaqArticleDetailRouteTest.php` (modify): locking test asserts the new 4-step literals and asserts the stale text is gone. Same atomic change as the migration.
- `README.md`, `docs/product/mvp-scope.md`, `docs/product/product-brief.md`, `docs/product/screen-inventory.md` (modify): living-docs alignment to 4-step vocabulary with one shared historical note. `CHANGELOG.md` is history and is NOT touched.
- Prior art to read before touching anything: `database/migrations/2026_07_26_170400_seed_faq_categories_and_articles.php` (seed shape), `database/migrations/2026_09_02_100000_update_faq_payment_method_answer_for_online_payment_launch.php` (prior seed-update migration pattern), `tests/Feature/Domain/Faq/FaqArticleSeedTest.php` (drift assertions).

---

### Task 1: FAQ seed 4-step migration plus locking test

**Files:**
- Create: `database/migrations/2026_09_20_100000_update_faq_booking_steps_four_step.php`
- Modify: `tests/Feature/Livewire/Public/Faq/FaqArticleDetailRouteTest.php:37-47`
- Test: `tests/Feature/Livewire/Public/Faq/FaqArticleDetailRouteTest.php`

**Interfaces:**
- Consumes: `FaqArticle` rows with slugs `bagaimana-cara-memesan-makam` and `kapan-pembayaran-dapat-dilakukan` (created by the 2026_07_26 seed migration); `FaqPublicQuery` public projection used by `GET /faq/{articleSlug}`.
- Produces: updated `summary`/`body` copy containing the literal `empat langkah` and the four stage names `Cari & Pilih`, `Data Pemesan & Data Almarhum`, `Pembayaran`, `Konfirmasi`; no occurrence of the literal `sembilan langkah` in either article.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah public render `GET /faq/bagaimana-cara-memesan-makam` dan `GET /faq/kapan-pembayaran-dapat-dilakukan` serta perbandingan drift kode katalog terhadap seed yang ditanam. Cakup SETIAP perilaku dan edge case task ini MELALUI seam itu, termasuk artikel draft yang tetap 404, slug tak dikenal yang 404 identik, dan migrasi yang gagal keras bila slug tidak ditemukan. Helper internal diuji secara tidak langsung lewat seam, tidak pernah langsung, meskipun fungsi-fungsi itu diekspor. Nilai harapan dalam test harus literal yang diketahui, bukan dihitung ulang dengan cara yang sama seperti kode.

- [ ] **Step 1: Update the locking test to the 4-step expectation**

In `tests/Feature/Livewire/Public/Faq/FaqArticleDetailRouteTest.php`, replace the stale assertion inside `test_a_real_seeded_published_article_renders_title_body_updated_date_and_cs_cta`:

```php
$response->assertOk();
$response->assertSee('Bagaimana cara memesan makam?');
$response->assertSee('empat langkah');
$response->assertSee('Cari & Pilih');
$response->assertSee('Pembayaran');
$response->assertDontSee('sembilan langkah');
$response->assertSee('Diperbarui');
$response->assertSee('Hubungi Customer Service');
$response->assertSee('/bantuan');
```

- [ ] **Step 2: Run the test to verify it fails against the old seed**

Run (in the PHP 8.5 container or CI, never on the 8.3 host):

```bash
php artisan test tests/Feature/Livewire/Public/Faq/FaqArticleDetailRouteTest.php --filter=test_a_real_seeded_published_article_renders_title_body_updated_date_and_cs_cta
```

Expected: FAIL on `assertSee('empat langkah')` (old seed still says `sembilan langkah`).

- [ ] **Step 3: Write the seed-update migration**

Create `database/migrations/2026_09_20_100000_update_faq_booking_steps_four_step.php` following the prior `2026_09_02_100000_update_faq_payment_method_answer_for_online_payment_launch.php` pattern (update by slug, guard on row existence so a missing row fails loudly instead of silently inserting):

```php
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->updateArticle(
            'bagaimana-cara-memesan-makam',
            'Pemesanan dilakukan melalui alur booking online empat langkah: Cari & Pilih, Data Pemesan & Data Almarhum, Pembayaran, dan Konfirmasi.',
            'Untuk memesan makam, Anda melalui empat tahap: (1) Cari & Pilih — memilih lokasi, TPU/TPS, jenis layanan, serta layanan dan tambahan; (2) Data Pemesan & Data Almarhum — mengisi data diri dan data almarhum sambil meninjau kartu Ringkasan Pesanan; (3) Pembayaran — membayar online bila tersedia atau mengikuti koordinasi manual; (4) Konfirmasi — menerima nomor pesanan, status, dan langkah berikutnya. Setiap tahap divalidasi sebelum Anda melanjutkan, dan progres Anda tersimpan sehingga dapat dilanjutkan kapan saja.'
        );

        $this->updateArticle(
            'kapan-pembayaran-dapat-dilakukan',
            'Pembayaran dilakukan pada tahap Pembayaran, setelah ringkasan pesanan, data pemesan, dan data almarhum selesai dikonfirmasi.',
            'Pembayaran adalah tahap ketiga dari empat tahap pemesanan, dilakukan setelah Anda meninjau ringkasan pesanan serta melengkapi data pemesan dan data almarhum. Bila pembayaran online belum tersedia, tahap yang sama menyediakan jalur koordinasi manual tanpa menghilangkan tahap pembayaran dari alur.'
        );
    }

    public function down(): void
    {
        // Seed-content correction only; restoring stale copy is not supported.
    }

    private function updateArticle(string $slug, string $summary, string $body): void
    {
        $affected = DB::table('faq_articles')->where('slug', $slug)->update([
            'summary' => $summary,
            'body' => $body,
            'updated_at' => now(),
        ]);

        if ($affected !== 1) {
            throw new RuntimeException("Expected exactly one faq_articles row for slug {$slug}, got {$affected}.");
        }
    }
};
```

- [ ] **Step 4: Run the FAQ tests to verify they pass**

Run (PHP 8.5 container or CI):

```bash
php artisan test tests/Feature/Livewire/Public/Faq/FaqArticleDetailRouteTest.php tests/Feature/Domain/Faq/FaqArticleSeedTest.php tests/Feature/Domain/Faq/FaqArticleDraftExclusionTest.php
```

Expected: PASS, all green.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_09_20_100000_update_faq_booking_steps_four_step.php tests/Feature/Livewire/Public/Faq/FaqArticleDetailRouteTest.php
git commit -m "fix(faq): rewrite booking articles to canonical 4-step narrative with locking test"
```

---

### Task 2: Living-docs 4-step alignment with historical note

**Files:**
- Modify: `README.md:14,34-46`
- Modify: `docs/product/mvp-scope.md:20-30`
- Modify: `docs/product/product-brief.md:45-55,156`
- Modify: `docs/product/screen-inventory.md:58-79,126-134`
- Test: `bash ci/verify-docs.sh` plus stale-text grep (no PHP needed)

**Interfaces:**
- Consumes: the canonical 4-step vocabulary (`Cari & Pilih`, `Data Pemesan & Data Almarhum`, `Pembayaran`, `Konfirmasi`) and the historical fact (9-step from RKS K23–K35, superseded by owner decision 2 Sep 2026 per `docs/superpowers/specs/2026-09-02-wizard-step-reduction-design.md`).
- Produces: living docs that name four steps everywhere, each carrying the shared historical note; `CHANGELOG.md` untouched.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah teks yang dirender publik (halaman FAQ dan wizard pemesanan) dan gerbang `ci/verify-docs.sh`. Cakup SETIAP perilaku dan edge case task ini MELALUI seam itu, termasuk setiap lokasi dokumen yang diubah harus lolos grep sapuan teks basi dan semua gerbang dokumen tetap hijau. Helper internal diuji secara tidak langsung lewat seam, tidak pernah langsung, meskipun fungsi-fungsi itu diekspor. Nilai harapan dalam test harus literal yang diketahui, bukan dihitung ulang dengan cara yang sama seperti kode.

- [ ] **Step 1: Rewrite the four living-doc locations**

Apply the same shared note immediately after each rewritten passage:

```text
(Catatan historis: sembilan langkah berasal dari RKS K23–K35; sejak keputusan owner 2 Sep 2026 yang kanonis adalah empat tahap di atas.)
```

Edits:
- `README.md:14`: `sembilan langkah pemesanan` becomes `empat tahap pemesanan (Cari & Pilih, Data Pemesan & Data Almarhum, Pembayaran, Konfirmasi)`; `README.md:34-46`: replace the nine-item list with the four stages plus the note.
- `docs/product/mvp-scope.md:20-30`: replace the Step 1–9 table with a four-row table (Cari & Pilih, Data Pemesan & Data Almarhum, Pembayaran, Konfirmasi) keeping the Required-outcome column content mapped onto the matching stage, plus the note.
- `docs/product/product-brief.md:45-55,156`: replace the nine-step list with the four stages plus the note.
- `docs/product/screen-inventory.md:58-79`: append a dated revision note stating the 29 Aug 2026 `9-behind-4-screens` framing is superseded — the documented step count itself is now four, PUB-010…013 render on Screen 1, PUB-014…016 on Screen 2, PUB-017 on Screen 3, PUB-018 on Screen 4 — plus the note.

- [ ] **Step 2: Verify no stale narrative remains outside history**

Run (host-safe, no build):

```bash
bash ci/verify-docs.sh 2>&1 | tail -n 3
```

Expected: `RESULT: ALL DOC GATES PASS`.

Then confirm the only remaining `sembilan langkah` occurrences are the historical notes and the frozen archive:

```bash
grep -rn "sembilan langkah" README.md docs/product/ database/migrations/2026_09_20_100000_update_faq_booking_steps_four_step.php | grep -v "Catatan historis" || true
```

Expected: empty output (every match carries the historical note or lives in the frozen archive/CHANGELOG).

- [ ] **Step 3: Commit**

```bash
git add README.md docs/product/mvp-scope.md docs/product/product-brief.md docs/product/screen-inventory.md
git commit -m "docs: align living docs to canonical 4-step booking with historical note"
```

---

### Task 3: Re-verify already-built PRD behavior (no code changes)

**Files:**
- Modify: none (verification only)
- Test: existing suites listed below, read-only runs

**Interfaces:**
- Consumes: the green baselines recorded before this plan (doc gates ALL PASS in worktree).
- Produces: a written evidence trail (test outputs pasted into the task report) confirming the already-built slices still hold: wizard 4-step render, marketplace one-vendor enforcement, renewal handoff, FAQ draft exclusion, payment-guard denial, PreNeed fail-closed, webhook idempotency pins.

**Seam constraint (MENGIKAT task ini, dari spec):** Seam yang diuji untuk task ini adalah seam publik tiap suite yang dijalankan ulang (render wizard, keranjang dan checkout marketplace, layar cari dan bayar perpanjangan, permukaan FAQ, vault dokumen). Cakup SETIAP perilaku dan edge case task ini MELALUI seam itu, termasuk kegagalan yang dihentikan dan dilaporkan alih-alih diperbaiki di dalam plan ini. Helper internal diuji secara tidak langsung lewat seam, tidak pernah langsung, meskipun fungsi-fungsi itu diekspor. Nilai harapan dalam test harus literal yang diketahui, bukan dihitung ulang dengan cara yang sama seperti kode.

- [ ] **Step 1: Run the covering suites in the PHP 8.5 container or CI**

```bash
php artisan test tests/Feature/Livewire/Public/Booking/ tests/Feature/Livewire/Public/Marketplace/ tests/Feature/Livewire/Public/Renewal/ tests/Feature/Domain/Faq/ tests/Feature/DocumentVault/ 2>&1 | tail -n 8
```

Expected: PASS (0 failures). If any failure appears, stop: it is either a regression from Task 1/2 or a pre-existing red unrelated to this plan — report it, do not fix it inside this plan.

- [ ] **Step 2: Record the evidence, commit nothing**

No commit in this task. Paste the full tail output into the task report so the reviewer can re-check the claim instead of trusting it.

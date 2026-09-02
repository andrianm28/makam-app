<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Updates the `metode-pembayaran-yang-tersedia` FAQ article seeded by
 * `2026_07_26_170400_seed_faq_categories_and_articles.php`. That migration's
 * own AC7 content-accuracy doc block explains the original wording was
 * deliberately written against `G-PAY-01` (online payment) being `closed` —
 * "Manual coordination" was the documented behaviour before activation.
 *
 * `G-PAY-01` is now `open` and SumoPod online payment is live in production
 * traffic (confirmed 2 Sep 2026 UAT pass against the newly-deployed booking/
 * renewal wizard consolidation). The seeded answer had therefore drifted
 * into an actively false claim — "pembayaran dikoordinasikan secara manual
 * bersama tim kami; kanal pembayaran otomatis akan diinformasikan begitu
 * tersedia" reads as "online payment does not exist yet" to a customer (or
 * anyone else) reading the public FAQ, which is no longer true. Same AC7
 * discipline as the original migration: describe the REAL current state, no
 * invented specifics (no turnaround promise, no per-order-type detail this
 * repo doesn't commit to in one place) — the new answer says online payment
 * exists and manual remains supported, without asserting exactly when a
 * given order becomes eligible for the online option, since that varies by
 * order type and is already covered by the DIFFERENT existing article
 * `kapan-pembayaran-dapat-dilakukan`.
 *
 * ---------------------------------------------------------------------------
 * Raw `DB::table()` writes, not `UpdateFaqArticleContent`/
 * `PublishFaqArticle` — a correction, not the codebase's general rule
 * ---------------------------------------------------------------------------
 * First attempt went through those two Actions (matching the "sanctioned
 * write path, not a raw write" discipline `2026_08_22_110000_seed_e2e_
 * admin_vendor_test_users.php` established for identity grants) and broke
 * 59 unrelated tests full-suite: both Actions call `Audit::record()`
 * unconditionally, and `RefreshDatabase` runs every migration exactly ONCE
 * per PHPUnit process — so those two audit rows landed in `audit_events`
 * before any test's own transaction wrapping began, and every test in the
 * process that asserted an exact/zero audit-row count (several `Payment`
 * tests did) saw 2 extra rows it had no way to know about. This IS the
 * exact failure mode `seed_e2e_admin_vendor_test_users.php`'s own doc block
 * describes hitting "the hard way 21 Aug 2026" for `actor_role_assignments`/
 * `scope_assignments`/`audit_events` — same trap, different table. That
 * migration's fix (a config-gated no-op) does not apply here: this is a
 * real content fix that must actually run in every real environment, not a
 * throwaway CI-only fixture. The original seed migration this one corrects
 * already sets the real precedent for FAQ content specifically: raw
 * `DB::table()` writes into `faq_articles`/`faq_article_versions`, never
 * `Audit::record()`. Following that precedent instead of the identity-grant
 * one is what avoids the pollution.
 *
 * Idempotency: guarded on the article's CURRENT `summary` still being the
 * stale seeded text, so a re-run (or a run after a real content operator
 * has already edited this article some other way) is a no-op rather than
 * clobbering a deliberate later change.
 */
return new class extends Migration
{
    private const string SLUG = 'metode-pembayaran-yang-tersedia';

    private const string STALE_SUMMARY = 'Saat ini pembayaran dikoordinasikan secara manual bersama tim kami; kanal pembayaran otomatis akan diinformasikan begitu tersedia.';

    private const string STALE_BODY = 'Pada tahap ini, metode pembayaran yang kami dukung sepenuhnya adalah koordinasi pembayaran manual bersama tim kami: Anda akan menerima instruksi dan referensi pembayaran pada langkah pembayaran, kemudian dapat mengunggah bukti pembayaran untuk diverifikasi. Kanal pembayaran otomatis/online sedang dalam proses aktivasi dan akan diinformasikan melalui halaman ini begitu benar-benar tersedia, sehingga kami tidak menampilkan opsi tersebut sebelum siap digunakan. Apa pun metode yang berlaku, status pesanan Anda hanya akan berubah menjadi lunas setelah pembayaran benar-benar terverifikasi, bukan hanya karena Anda kembali dari halaman pembayaran.';

    private const string NEW_SUMMARY = 'Pembayaran dapat dilakukan secara online melalui mitra pembayaran kami, atau secara manual melalui transfer dengan konfirmasi tim kami.';

    private const string NEW_BODY = 'Anda dapat membayar secara online melalui mitra pembayaran kami begitu pesanan Anda siap dibayar. Untuk pemesanan makam baru, opsi pembayaran online muncul setelah tim kami memverifikasi pesanan dan mengirimkan penawaran harga yang Anda setujui — lihat pertanyaan "Kapan pembayaran dapat dilakukan?" untuk urutan lengkapnya. Untuk pesanan marketplace, opsi pembayaran online tersedia langsung pada halaman checkout. Pembayaran manual melalui transfer tetap didukung penuh sebagai alternatif kapan saja: Anda akan menerima instruksi dan referensi pembayaran, lalu dapat mengunggah bukti pembayaran untuk diverifikasi. Apa pun metode yang Anda pilih, status pesanan hanya berubah menjadi lunas setelah pembayaran benar-benar terverifikasi, bukan hanya karena Anda kembali dari halaman pembayaran.';

    public function up(): void
    {
        $this->republish(self::STALE_SUMMARY, self::NEW_SUMMARY, self::NEW_BODY);
    }

    public function down(): void
    {
        $this->republish(self::NEW_SUMMARY, self::STALE_SUMMARY, self::STALE_BODY);
    }

    private function republish(string $expectedCurrentSummary, string $newSummary, string $newBody): void
    {
        $article = DB::table('faq_articles')->where('slug', self::SLUG)->first();

        if ($article === null || $article->summary !== $expectedCurrentSummary) {
            return;
        }

        $now = now();
        $newVersion = $article->current_version + 1;

        DB::table('faq_articles')->where('id', $article->id)->update([
            'summary' => $newSummary,
            'body' => $newBody,
            'current_version' => $newVersion,
            'published_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('faq_article_versions')->insert([
            'faq_article_id' => $article->id,
            'version_number' => $newVersion,
            'category_id' => $article->category_id,
            'title' => $article->title,
            'slug' => $article->slug,
            'summary' => $newSummary,
            'body' => $newBody,
            'published_at' => $now,
            'published_by' => 'migration:2026_09_02_100000',
        ]);
    }
};

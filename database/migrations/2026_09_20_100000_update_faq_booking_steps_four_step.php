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
            'Untuk memesan makam, Anda melalui empat langkah: (1) Cari & Pilih — memilih lokasi, TPU/TPS, jenis layanan, serta layanan dan tambahan; (2) Data Pemesan & Data Almarhum — mengisi data diri dan data almarhum sambil meninjau kartu Ringkasan Pesanan; (3) Pembayaran — membayar online bila tersedia atau mengikuti koordinasi manual; (4) Konfirmasi — menerima nomor pesanan, status, dan langkah berikutnya. Setiap tahap divalidasi sebelum Anda melanjutkan, dan progres Anda tersimpan sehingga dapat dilanjutkan kapan saja.'
        );

        $this->updateArticle(
            'kapan-pembayaran-dapat-dilakukan',
            'Pembayaran dilakukan pada langkah Pembayaran, setelah ringkasan pesanan, data pemesan, dan data almarhum selesai dikonfirmasi.',
            'Pembayaran adalah langkah ketiga dari empat langkah pemesanan, dilakukan setelah Anda meninjau ringkasan pesanan serta melengkapi data pemesan dan data almarhum. Bila pembayaran online belum tersedia, tahap yang sama menyediakan jalur koordinasi manual tanpa menghilangkan tahap pembayaran dari alur.'
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

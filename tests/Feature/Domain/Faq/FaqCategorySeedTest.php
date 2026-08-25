<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Faq;

use App\Domain\Faq\FaqCategoryCode;
use App\Domain\Faq\Models\FaqCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `2026_07_26_170400_seed_faq_categories_and_articles.php` seeds exactly the
 * six categories `docs/product/faq-catalog.md` names, in that document's own
 * order — requirements.md AC2: "THE SYSTEM SHALL present the six FAQ
 * categories defined in `faq-catalog.md`."
 */
final class FaqCategorySeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_exactly_six_categories_are_seeded(): void
    {
        $this->assertDatabaseCount('faq_categories', 6);
        $this->assertSame(6, count(FaqCategoryCode::KNOWN_CODES));
    }

    public function test_categories_have_the_exact_catalogue_codes_and_labels_in_order(): void
    {
        $categories = FaqCategory::orderedForDisplay()->get();

        $this->assertSame([
            ['CARA_MEMESAN', 'Cara Memesan', 1],
            ['DOKUMEN', 'Dokumen', 2],
            ['PEMBAYARAN', 'Pembayaran', 3],
            ['PERPANJANGAN', 'Perpanjangan Makam', 4],
            ['PEMBAYARAN_GAGAL', 'Pembayaran Gagal', 5],
            ['CUSTOMER_SERVICE', 'Customer Service', 6],
        ], $categories->map(fn (FaqCategory $category) => [
            $category->code, $category->name, $category->sort_order,
        ])->all());
    }

    public function test_find_by_code_resolves_every_known_code(): void
    {
        foreach (FaqCategoryCode::KNOWN_CODES as $code) {
            $this->assertNotNull(FaqCategory::findByCode($code), "Expected a seeded category for [{$code}].");
        }

        $this->assertNull(FaqCategory::findByCode('NOT_A_REAL_CODE'));
    }
}

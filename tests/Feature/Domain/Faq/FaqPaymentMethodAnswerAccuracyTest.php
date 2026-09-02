<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Faq;

use App\Domain\Faq\Models\FaqArticle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `2026_09_02_100000_update_faq_payment_method_answer_for_online_payment_
 * launch.php` — the seed migration's original `metode-pembayaran-yang-
 * tersedia` answer was written while `G-PAY-01` was `closed` and said so
 * ("pembayaran dikoordinasikan secara manual... kanal pembayaran otomatis
 * akan diinformasikan begitu tersedia"). `G-PAY-01` is now open and SumoPod
 * online payment is live, so that claim is now false on a public page — a
 * customer (or anyone else) reading the FAQ would conclude online payment
 * does not exist. This proves the update migration actually ran (through
 * `UpdateFaqArticleContent`/`PublishFaqArticle`, so `summary`/`body` are
 * the real content columns, not `answer_short`/`answer_long`) and the
 * article no longer makes that claim.
 */
final class FaqPaymentMethodAnswerAccuracyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_the_payment_method_answer_no_longer_claims_online_payment_is_unavailable(): void
    {
        $article = FaqArticle::query()->where('slug', 'metode-pembayaran-yang-tersedia')->firstOrFail();

        $this->assertStringNotContainsString('kanal pembayaran otomatis akan diinformasikan begitu tersedia', $article->summary);
        $this->assertStringNotContainsString('kanal pembayaran otomatis akan diinformasikan begitu tersedia', $article->body);
    }

    public function test_the_payment_method_answer_now_mentions_online_payment_is_available(): void
    {
        $article = FaqArticle::query()->where('slug', 'metode-pembayaran-yang-tersedia')->firstOrFail();

        $this->assertStringContainsString('online', $article->summary);
        $this->assertStringContainsString('online', $article->body);
    }

    public function test_the_public_faq_page_shows_the_corrected_answer(): void
    {
        // The detail page renders `body`, not `summary` (see
        // resources/views/livewire/public/faq/article-detail.blade.php).
        $this->get('/faq/metode-pembayaran-yang-tersedia')
            ->assertOk()
            ->assertSee('Anda dapat membayar secara online melalui mitra pembayaran kami', false)
            ->assertDontSee('kanal pembayaran otomatis akan diinformasikan begitu tersedia');
    }

    public function test_the_edit_is_recorded_as_a_new_published_version(): void
    {
        $article = FaqArticle::query()->where('slug', 'metode-pembayaran-yang-tersedia')->firstOrFail();

        // Was version 1 from the original seed migration's own publish call.
        $this->assertGreaterThan(1, $article->current_version);
        $this->assertDatabaseHas('faq_article_versions', [
            'faq_article_id' => $article->id,
            'version_number' => $article->current_version,
            'summary' => $article->summary,
        ]);
    }
}

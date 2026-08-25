import AxeBuilder from '@axe-core/playwright';
import { expect, test } from '@playwright/test';

/**
 * E2E-FAQ — docs/testing/test-strategy.md §2. Categories, article slugs, and
 * the draft/published split come from
 * database/migrations/2026_07_26_170400_seed_faq_categories_and_articles.php
 * (a data migration, run by CI's plain `php artisan migrate --force`, not a
 * separate seeder) — confirmed by reading that migration directly rather
 * than assumed.
 */
const CATEGORIES = ['Cara Memesan', 'Dokumen', 'Pembayaran', 'Perpanjangan Makam', 'Pembayaran Gagal', 'Customer Service'];

// A real published article, seeded by the migration above, used as the
// "click into an article" fixture below.
const PUBLISHED_ARTICLE_SLUG = 'bagaimana-cara-memesan-makam';
const PUBLISHED_ARTICLE_TITLE = 'Bagaimana cara memesan makam?';

// Seeded `publish_state = draft` on purpose (the migration's own doc block:
// "22 published, 1 deliberately seeded as draft" — a real fixture for
// exercising the no-draft-leakage guarantee, not a fabricated test).
const DRAFT_ARTICLE_SLUG = 'bagaimana-melaporkan-masalah-vendor-atau-order';
const DRAFT_ARTICLE_TITLE = 'Bagaimana melaporkan masalah vendor atau order?';

test('all six FAQ categories are listed as filter chips', async ({ page }) => {
    await page.goto('/faq');

    const categoryFilter = page.getByRole('navigation', { name: 'Filter kategori FAQ' });
    // "Semua Kategori" plus the six real categories.
    const chips = categoryFilter.getByRole('link');
    await expect(chips).toHaveText(['Semua Kategori', ...CATEGORIES]);
});

test('search filters the article list', async ({ page }) => {
    await page.goto('/faq');

    await page.getByLabel('Cari pertanyaan').fill('draft');
    await page.getByRole('button', { name: 'Cari', exact: true }).click();

    await expect(page.getByRole('heading', { name: 'Hasil Pencarian' })).toBeVisible();
    // The card `<a>` wraps both the title heading and the summary paragraph,
    // so its accessible name is the concatenation of both — match on the
    // heading inside the link, not the link's own (compound) accessible name.
    await expect(
        page.locator('a').filter({ has: page.getByRole('heading', { name: 'Bagaimana cara melanjutkan draft?', exact: true }) }),
    ).toBeVisible();

    // Reset control appears once a term is active and clears back to the
    // unfiltered list.
    const resetButton = page.getByRole('button', { name: 'Reset pencarian' });
    await expect(resetButton).toBeVisible();
    await resetButton.click();
    await expect(page.getByRole('heading', { name: 'Pertanyaan yang Sering Diajukan' })).toBeVisible();
});

test('a category filter chip scopes the article list to that category', async ({ page }) => {
    await page.goto('/faq');

    await page.getByRole('link', { name: 'Dokumen', exact: true }).click();

    await expect(page).toHaveURL(/\/faq\/kategori\/dokumen$/);
    await expect(page.getByRole('heading', { name: 'Dokumen', exact: true })).toBeVisible();

    // Dokumen has exactly 3 seeded articles, all published.
    const articleList = page.getByRole('list', { name: 'Daftar artikel FAQ' });
    await expect(articleList.getByRole('link')).toHaveCount(3);
});

test('clicking into an article renders its detail page', async ({ page }) => {
    await page.goto('/faq');

    // Same compound-accessible-name reasoning as the search test above.
    await page.locator('a').filter({ has: page.getByRole('heading', { name: PUBLISHED_ARTICLE_TITLE, exact: true }) }).click();

    await expect(page).toHaveURL(new RegExp(`/faq/${PUBLISHED_ARTICLE_SLUG}$`));
    await expect(page.getByRole('heading', { level: 1, name: PUBLISHED_ARTICLE_TITLE })).toBeVisible();
    await expect(page.getByText(/Diperbarui \d{1,2} \w+ \d{4}/)).toBeVisible();

    // Breadcrumb back to the category this article belongs to.
    await expect(page.getByRole('navigation', { name: 'Navigasi FAQ' }).getByRole('link', { name: 'Cara Memesan' })).toBeVisible();
});

test('the related-content section is correctly absent when no related articles are linked', async ({ page }) => {
    // NOTE ON COVERAGE: `faq_article_related_article` (the pivot
    // `FaqArticle::relatedArticles()`/`publishedRelatedArticles()` reads —
    // app/Domain/Faq/Models/FaqArticle.php:160-183) is created by
    // database/migrations/2026_07_26_170300_create_faq_article_related_article_table.php
    // but is NEVER populated by the seed migration
    // (2026_07_26_170400_seed_faq_categories_and_articles.php inserts
    // faq_categories/faq_articles/faq_article_versions rows only — confirmed
    // by reading it in full, no related-article sync anywhere in it). Every
    // seeded article's `publishedRelatedArticles()` is therefore genuinely
    // empty against a plain `php artisan migrate --force`, and
    // article-detail.blade.php wraps the whole section in
    // `@if ($relatedArticles->isNotEmpty())`. The only way to reach the
    // "has related articles" branch is via Filament admin edits
    // (`UpdateFaqArticleContent`/`CreateFaqArticleDraft`'s own `sync()`
    // calls) or a factory, both out of scope for this browser-spec-only
    // batch (constrained to two new tests/browser/*.spec.ts files, no
    // app/ or database/ changes). This test instead asserts the real,
    // fixture-reachable behaviour: the section is correctly omitted, not
    // rendered empty, when there is nothing to relate.
    await page.goto(`/faq/${PUBLISHED_ARTICLE_SLUG}`);

    await expect(page.getByRole('region', { name: 'Artikel terkait' })).toHaveCount(0);
    await expect(page.getByRole('heading', { name: 'Artikel terkait' })).toHaveCount(0);
});

test('the customer-service CTA is present on the FAQ index and on an article detail page', async ({ page }) => {
    await page.goto('/faq');
    await expect(page.getByRole('link', { name: 'Bantuan', exact: true })).toHaveAttribute('href', /\/bantuan$/);

    await page.goto(`/faq/${PUBLISHED_ARTICLE_SLUG}`);
    const ctaLink = page.getByRole('link', { name: 'Hubungi Customer Service', exact: true });
    await expect(ctaLink).toHaveAttribute('href', /\/bantuan$/);
});

test('a draft article never leaks through the public FAQ surface', async ({ page }) => {
    // 1. Direct URL to the draft slug 404s — FaqArticleDetail::mount() calls
    // FaqPublicQuery::findBySlug(), which is deliberately indistinguishable
    // between "no article has this slug" and "draft/unpublished" (see that
    // class's own doc block), and aborts with a real 404 either way.
    const response = await page.goto(`/faq/${DRAFT_ARTICLE_SLUG}`);
    expect(response?.status()).toBe(404);

    // 2. The draft article's title never appears anywhere on the public FAQ
    // index — neither in the default list nor in its own category (Customer
    // Service), nor as a search hit for its own exact title text.
    await page.goto('/faq');
    await expect(page.getByText(DRAFT_ARTICLE_TITLE)).toHaveCount(0);

    await page.goto('/faq/kategori/customer-service');
    await expect(page.getByText(DRAFT_ARTICLE_TITLE)).toHaveCount(0);

    await page.goto('/faq');
    await page.getByLabel('Cari pertanyaan').fill('melaporkan masalah vendor');
    await page.getByRole('button', { name: 'Cari', exact: true }).click();
    await expect(page.getByText(DRAFT_ARTICLE_TITLE)).toHaveCount(0);
});

test('the FAQ index is accessible', async ({ page }) => {
    await page.goto('/faq');

    const results = await new AxeBuilder({ page }).analyze();
    expect(results.violations).toEqual([]);
});

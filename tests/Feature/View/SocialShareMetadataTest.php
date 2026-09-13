<?php

declare(strict_types=1);

namespace Tests\Feature\View;

use App\Support\SiteMeta;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * FN-4 — every shared link rendered as a bare URL.
 *
 * `layouts/app.blade.php` is the only public layout, and its entire `<head>`
 * was charset, viewport, `<title>`, two icons and `@vite`. No
 * `<meta name="description">`, no `<link rel="canonical">`, and no `og:*` or
 * `twitter:*` anywhere in `resources/views` — verified by fetching `/` and
 * `/pemakaman` on the live site: zero matches.
 *
 * The owner's launch channel is a WhatsApp group. Every recipient saw
 * `https://makam.co.id` with no card, no title and no image.
 *
 * The assertions below check VALUES, not the presence of tags: a
 * `content=""` og:title produces exactly the same bare link the bug
 * produced, so `assertStringContainsString('og:title', $html)` would pass
 * on a page that is still broken.
 */
final class SocialShareMetadataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    /**
     * @return array<string, string>
     */
    private function metaTags(string $html): array
    {
        preg_match_all(
            '/<(?:meta|link)\s+(?:name|property|rel)="([^"]+)"\s+(?:content|href)="([^"]*)"\s*\/?>/i',
            $html,
            $matches,
            PREG_SET_ORDER,
        );

        $tags = [];

        foreach ($matches as $match) {
            $tags[$match[1]] = $match[2];
        }

        return $tags;
    }

    public function test_the_homepage_head_carries_a_real_description_canonical_and_og_card(): void
    {
        $tags = $this->metaTags($this->get('/')->assertOk()->getContent());

        foreach (['description', 'og:title', 'og:description', 'og:url', 'og:image', 'twitter:card'] as $key) {
            $this->assertArrayHasKey($key, $tags, "The <head> is missing `{$key}`.");
            $this->assertNotSame('', $tags[$key], "`{$key}` is present but empty, which shares exactly as badly.");
        }

        $this->assertSame(SiteMeta::description(), $tags['description']);
        $this->assertSame(SiteMeta::description(), $tags['og:description']);

        // og:url and canonical must be absolute — a relative value makes the
        // card resolve against the sharing app's own host, not ours.
        $this->assertSame(url('/'), $tags['og:url']);
        $this->assertArrayHasKey('canonical', $tags);
        $this->assertSame(url('/'), $tags['canonical']);

        // og:image must be absolute AND point at a file that is really
        // committed under public/, or the card renders with no image.
        $this->assertSame(asset(SiteMeta::ogImagePath()), $tags['og:image']);
        $this->assertStringStartsWith('http', $tags['og:image']);
        $this->assertFileExists(public_path(SiteMeta::ogImagePath()));
    }

    /**
     * The card must describe the page it links to, not the site in general,
     * wherever the page already tells the layout what it is. Every public
     * screen already passes `$title` through `->layout(...)`.
     */
    public function test_a_page_shares_its_own_title_rather_than_the_site_name(): void
    {
        $tags = $this->metaTags($this->get('/pemakaman')->assertOk()->getContent());

        $this->assertArrayHasKey('og:title', $tags);
        $this->assertNotSame('Makam.co.id', $tags['og:title']);
        $this->assertStringContainsString('Direktori', $tags['og:title']);

        // The canonical drops the query string but keeps the path.
        $this->assertSame(url('/pemakaman'), $tags['canonical']);
        $this->assertSame(url('/pemakaman'), $tags['og:url']);
    }

    /**
     * `AGENTS.md` and this repo's placeholder discipline: the default
     * description may state what the four mandatory MVP services are and
     * nothing else. A price, an operating hour, an SLA or a coverage claim
     * baked into a constant would be business data this repository does not
     * have, shipped to every share card on the site.
     */
    public function test_the_default_description_claims_no_business_data(): void
    {
        $description = SiteMeta::description();

        $forbidden = [
            'a price' => '/\bRp\.?\s?\d/i',
            'an operating hour or SLA' => '/\b\d+\s*(jam|menit|hari)\b/i',
            'a guarantee or free-of-charge claim' => '/\b(garansi|gratis|termurah|bergaransi)\b/i',
            'a percentage claim' => '/\d\s*%/',
        ];

        foreach ($forbidden as $what => $pattern) {
            $this->assertSame(
                0,
                preg_match($pattern, $description),
                "The site-wide description must not assert {$what} — this repository defines no such value."
            );
        }

        $this->assertLessThanOrEqual(
            160,
            mb_strlen(SiteMeta::description()),
            'Over ~160 characters a search snippet and a WhatsApp preview both truncate mid-sentence.'
        );
    }
}

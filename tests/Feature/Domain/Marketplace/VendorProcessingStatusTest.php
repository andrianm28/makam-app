<?php

declare(strict_types=1);

namespace Tests\Feature\Domain\Marketplace;

use App\Domain\Marketplace\VendorProcessingStatus;
use Tests\TestCase;

/**
 * W-6: `VendorProcessingStatus` is wired to nothing — the sole justification
 * for keeping it ("one catalogue-traceable definition that cannot drift") is
 * UNENFORCED without this test. The catalogue could add `DIBAYAR` — the exact
 * AC12 invariant the class encodes by omission — and CI would stay green.
 * Mirrors `ProductCatalogueSeedTest`'s discipline: re-parses the canonical
 * catalogue document directly, independent of the class.
 */
final class VendorProcessingStatusTest extends TestCase
{
    /**
     * @return list<string>
     */
    private function statusesFromLiveCatalogueDocument(): array
    {
        $path = base_path('docs/product/marketplace-catalog.md');
        $this->assertFileExists($path, 'Canonical catalogue document is missing.');

        $contents = file_get_contents($path);
        $this->assertIsString($contents);

        // The "Vendor processing statuses" block is a plain fenced code
        // block, not backtick-quoted tokens — anchor on the fence opener and
        // collect the ALL_CAPS lines inside it. Anchoring prevents unrelated
        // prose from being misattributed (same reasoning as W-3/P-2).
        $blockStart = strpos($contents, "```text\nMENUNGGU_VENDOR");
        $this->assertNotFalse($blockStart, 'Catalogue "Vendor processing statuses" block not found.');
        $blockEnd = strpos($contents, "\n```", $blockStart);
        $this->assertNotFalse($blockEnd, 'Catalogue status block is unterminated.');

        $statuses = [];
        foreach (preg_split('/\R/', substr($contents, $blockStart, $blockEnd - $blockStart)) ?: [] as $line) {
            $line = trim($line);
            if (preg_match('/^[A-Z][A-Z0-9_]+$/', $line) === 1) {
                $statuses[] = $line;
            }
        }

        return $statuses;
    }

    public function test_the_live_catalogue_document_still_names_the_same_eight_processing_statuses_in_document_order(): void
    {
        // W-6: an exact, order-sensitive match — a reordered, added, or
        // removed status line in the catalogue fails here instead of letting
        // the class and the document drift silently apart.
        $this->assertSame(
            VendorProcessingStatus::KNOWN_STATUSES,
            $this->statusesFromLiveCatalogueDocument(),
            'VendorProcessingStatus::KNOWN_STATUSES has drifted from the catalogue\'s "Vendor processing statuses" block.'
        );
    }

    public function test_dibayar_is_absent_from_the_catalogue_status_block(): void
    {
        // AC12: "DIBAYAR ≠ SELESAI" — payment and fulfilment are separate
        // states. The class encodes that invariant by omission, so the
        // catalogue block itself must never grow a DIBAYAR line either.
        $this->assertNotContains('DIBAYAR', $this->statusesFromLiveCatalogueDocument());
    }
}

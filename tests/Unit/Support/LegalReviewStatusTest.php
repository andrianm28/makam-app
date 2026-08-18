<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Platform\SiteSettings\Models\SiteSetting;
use App\Support\LegalReviewStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `LegalReviewStatus` — H3 (`docs/superpowers/plans/
 * 2026-08-18-public-beta-release.md` Phase 0) as an admin-editable field.
 * See its own doc block for why `note()` is a free-text confirmation, not
 * a bare boolean.
 */
final class LegalReviewStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_is_not_reviewed_by_default(): void
    {
        $this->assertNull(LegalReviewStatus::note());
        $this->assertFalse(LegalReviewStatus::isReviewed());
    }

    public function test_it_reads_a_configured_review_note(): void
    {
        SiteSetting::query()->create([
            'key' => SiteSetting::KEY_LEGAL_REVIEW_NOTE,
            'value' => 'Ditinjau 1 Sep 2026 oleh Firma Hukum Contoh',
        ]);

        $this->assertSame('Ditinjau 1 Sep 2026 oleh Firma Hukum Contoh', LegalReviewStatus::note());
        $this->assertTrue(LegalReviewStatus::isReviewed());
    }

    public function test_a_blank_value_is_treated_as_not_reviewed(): void
    {
        SiteSetting::query()->create(['key' => SiteSetting::KEY_LEGAL_REVIEW_NOTE, 'value' => '   ']);

        $this->assertNull(LegalReviewStatus::note());
        $this->assertFalse(LegalReviewStatus::isReviewed());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Tests\TestCase;

final class MkStickyComparisonRailTest extends TestCase
{
    public function test_it_renders_tiers_cta_and_links(): void
    {
        $html = Blade::render(<<<'BLADE'
            <x-mk.sticky-comparison-rail
                heading="Contoh Perbandingan Paket"
                :tiers="[
                    [
                        'label' => 'Contoh Paket Dasar',
                        'price' => 'Rp 4.000.000/tahun',
                        'priceSource' => 'Contoh Pengelola Taman Damai',
                        'description' => 'Contoh deskripsi paket dasar.',
                    ],
                    [
                        'label' => 'Contoh Paket Premium',
                        'price' => 'Rp 9.500.000/tahun',
                        'priceSource' => 'Contoh Pengelola Taman Damai',
                        'description' => 'Contoh deskripsi paket premium.',
                    ],
                ]"
                :cta="['label' => 'Contoh Pilih Paket', 'href' => '/contoh-pilih-paket']"
                :links="[
                    ['label' => 'Contoh Lihat Detail Layanan', 'href' => '/contoh-layanan'],
                    ['label' => 'Contoh Pertanyaan Umum', 'href' => '/contoh-faq'],
                ]"
            />
            BLADE);

        $this->assertStringContainsString('Contoh Perbandingan Paket', $html);
        $this->assertStringContainsString('Contoh Paket Dasar', $html);
        $this->assertStringContainsString('Rp 4.000.000/tahun', $html);
        $this->assertStringContainsString('Sumber: Contoh Pengelola Taman Damai', $html);
        $this->assertStringContainsString('Contoh deskripsi paket dasar.', $html);
        $this->assertStringContainsString('Contoh Paket Premium', $html);
        $this->assertStringContainsString('Rp 9.500.000/tahun', $html);

        // The CTA must render as a single real primary <x-mk.button> link,
        // matching design-system.md §2.3's "exactly one primary action per
        // view" and <x-mk.hero>'s identical CTA contract.
        $this->assertMatchesRegularExpression(
            '#<a[^>]*href="/contoh-pilih-paket"[^>]*>.*Contoh Pilih Paket.*</a>#s',
            $html
        );
        $this->assertStringContainsString('bg-primary-600', $html);

        // Related links render as inline <x-mk.button variant="link">.
        $this->assertMatchesRegularExpression(
            '#<a[^>]*href="/contoh-layanan"[^>]*>.*Contoh Lihat Detail Layanan.*</a>#s',
            $html
        );
        $this->assertMatchesRegularExpression(
            '#<a[^>]*href="/contoh-faq"[^>]*>.*Contoh Pertanyaan Umum.*</a>#s',
            $html
        );
    }

    public function test_it_applies_the_documented_sticky_classes_gated_at_md(): void
    {
        // design-system.md §4.3's own wizard-aside sticky example
        // (`md:sticky md:top-24 md:self-start`) is reused verbatim -- this
        // component must not invent a different breakpoint or offset, and
        // must collapse to a plain, non-sticky block below `md` (mobile-
        // first, matching <x-mk.table>'s own md:-gated collapse per §3.5).
        $html = Blade::render(<<<'BLADE'
            <x-mk.sticky-comparison-rail
                :tiers="[['label' => 'Contoh Paket', 'price' => 'Rp 1.000.000']]"
                :cta="['label' => 'Contoh CTA', 'href' => '/contoh-cta']"
            />
            BLADE);

        $this->assertMatchesRegularExpression(
            '#<aside[^>]*class="[^"]*md:sticky[^"]*md:top-24[^"]*md:self-start[^"]*"#',
            $html
        );

        // No `sticky` (unqualified) utility outside the `md:` variant -- the
        // element must not be sticky below the md breakpoint.
        $this->assertDoesNotMatchRegularExpression(
            '#class="[^"]*(?<!md:)\bsticky\b#',
            $html
        );
    }

    public function test_a_tier_with_no_price_renders_an_honest_unavailable_state(): void
    {
        // CemeteryPresenter's own convention: "showing nothing is honest;
        // showing a number with an invented source would not be." No
        // fabricated example price is ever rendered for a null price.
        $html = Blade::render(<<<'BLADE'
            <x-mk.sticky-comparison-rail
                :tiers="[
                    ['label' => 'Contoh Paket Belum Ada Harga', 'price' => null],
                ]"
                :cta="['label' => 'Contoh CTA', 'href' => '/contoh-cta']"
            />
            BLADE);

        $this->assertStringContainsString('Contoh Paket Belum Ada Harga', $html);
        $this->assertStringContainsString('Belum tersedia', $html);
        $this->assertStringNotContainsString('Sumber:', $html);
        $this->assertStringNotContainsString('Perlu konfirmasi', $html);
    }

    public function test_an_indicative_tier_renders_the_neutral_perlu_konfirmasi_badge(): void
    {
        // §2.3: "State availability honestly ... An indicative price is
        // neutral, never success" -- matches the existing cemetery
        // directory usage of the same badge shape verbatim.
        $html = Blade::render(<<<'BLADE'
            <x-mk.sticky-comparison-rail
                :tiers="[
                    [
                        'label' => 'Contoh Paket Indikatif',
                        'price' => 'Rp 6.000.000/tahun',
                        'priceSource' => 'Contoh Pengelola',
                        'indicative' => true,
                    ],
                ]"
                :cta="['label' => 'Contoh CTA', 'href' => '/contoh-cta']"
            />
            BLADE);

        $this->assertStringContainsString('Perlu konfirmasi', $html);
        $this->assertStringContainsString('mk-intent-neutral', $html);
        $this->assertStringNotContainsString('mk-intent-success', $html);
    }

    public function test_the_trust_slot_renders_only_when_supplied(): void
    {
        $withTrust = Blade::render(<<<'BLADE'
            <x-mk.sticky-comparison-rail
                :tiers="[['label' => 'Contoh Paket', 'price' => 'Rp 1.000.000']]"
                :cta="['label' => 'Contoh CTA', 'href' => '/contoh-cta']"
            >
                <x-slot:trust>
                    <p>Contoh isi trust badge dari pemanggil.</p>
                </x-slot:trust>
            </x-mk.sticky-comparison-rail>
            BLADE);

        $this->assertStringContainsString('Contoh isi trust badge dari pemanggil.', $withTrust);

        $withoutTrust = Blade::render(<<<'BLADE'
            <x-mk.sticky-comparison-rail
                :tiers="[['label' => 'Contoh Paket', 'price' => 'Rp 1.000.000']]"
                :cta="['label' => 'Contoh CTA', 'href' => '/contoh-cta']"
            />
            BLADE);

        // No fallback trust content is ever invented when the slot is
        // omitted -- see component header comment.
        $this->assertStringNotContainsString('Contoh isi trust badge dari pemanggil.', $withoutTrust);
    }

    public function test_it_throws_without_a_cta(): void
    {
        // Same fail-loudly convention <x-mk.hero> uses for a missing
        // `heading` -- see MkHeroTest::test_it_throws_without_a_heading().
        try {
            Blade::render(
                '<x-mk.sticky-comparison-rail :tiers="[[\'label\' => \'Contoh Paket\', \'price\' => \'Rp 1.000.000\']]" />'
            );
            $this->fail('Expected an exception when <x-mk.sticky-comparison-rail> is rendered without a cta.');
        } catch (\Throwable $e) {
            $cause = $e;
            while ($cause->getPrevious() !== null) {
                $cause = $cause->getPrevious();
            }

            $this->assertInstanceOf(\InvalidArgumentException::class, $cause);
            $this->assertStringContainsString(
                '<x-mk.sticky-comparison-rail> requires a cta with a label and href.',
                $cause->getMessage()
            );
        }
    }
}

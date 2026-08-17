<?php

declare(strict_types=1);

namespace Tests\Feature\View\Components;

use Illuminate\Support\Facades\Blade;
use Illuminate\View\ViewException;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * <x-mk.logo> — official Makam.co.id brand mark (ADR-0034, OQ-02 resolved).
 * Covers the raster picture/webp/png source set, the live-text wordmark
 * (Poppins via font-display, lowercase per the brand render), the
 * `variant` closed list (throws on an unknown value, same pattern as
 * <x-mk.badge>'s intent prop), and the alt/aria-hidden accessibility
 * contract: when the wordmark is shown the mark is decorative (empty alt,
 * aria-hidden), and when the wordmark is suppressed the mark becomes the
 * accessible name (`alt="makam.co.id"`).
 */
final class MkLogoTest extends TestCase
{
    public function test_default_renders_mark_wordmark_and_alt_contract(): void
    {
        $html = Blade::render('<x-mk.logo />');

        $this->assertStringContainsString('brand/mark-96.png', $html);
        $this->assertStringContainsString('type="image/webp"', $html);
        $this->assertStringContainsString('makam.co.id', $html);        // lowercase wordmark
        $this->assertStringContainsString('alt=""', $html);             // mark decorative beside wordmark
        $this->assertStringContainsString('font-display', $html);
        $this->assertStringContainsString('text-primary-800', $html);
    }

    public function test_inverse_variant_swaps_mark_and_wordmark_colour(): void
    {
        $html = Blade::render('<x-mk.logo variant="inverse" />');

        $this->assertStringContainsString('brand/mark-inverse-96.png', $html);
        $this->assertStringContainsString('text-neutral-0', $html);
    }

    public function test_wordmark_false_makes_the_mark_the_accessible_name(): void
    {
        $html = Blade::render('<x-mk.logo :wordmark="false" />');

        $this->assertStringContainsString('alt="makam.co.id"', $html);
    }

    public function test_unknown_variant_throws(): void
    {
        // The component's @php block throws InvalidArgumentException, but
        // Blade::render() evaluates compiled views through
        // Illuminate\View\Engines\CompilerEngine::get(), whose
        // handleViewException() unconditionally wraps every throwable that
        // is not HttpException/HttpResponseException/RecordNotFoundException/
        // RecordsNotFoundException into a new Illuminate\View\ViewException
        // (itself an ErrorException) before rethrowing — see
        // CompilerEngine::handleViewException(). So the exception that
        // actually propagates out of Blade::render() is ViewException, with
        // the original InvalidArgumentException preserved as
        // ViewException::getPrevious().
        try {
            Blade::render('<x-mk.logo variant="neon" />');
            $this->fail('Expected a ViewException wrapping InvalidArgumentException to be thrown.');
        } catch (ViewException $e) {
            $previous = $e->getPrevious();

            $this->assertInstanceOf(InvalidArgumentException::class, $previous);
            $this->assertSame('x-mk.logo: unknown variant [neon]', $previous->getMessage());
        }
    }
}

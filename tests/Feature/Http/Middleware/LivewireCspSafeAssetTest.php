<?php

declare(strict_types=1);

namespace Tests\Feature\Http\Middleware;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Mechanisms\HandleRequests\EndpointResolver;
use Tests\TestCase;

/**
 * `config/livewire.php`'s own doc block explains why this file exists: real
 * CSP enforcement (SEC-08) blocks the `eval`/`new Function()` calls
 * Livewire's regular bundle makes to parse `wire:click="method(args)"`
 * directive expressions, which report-only mode never surfaced. This test
 * proves the fix at the one place that actually matters — the bytes
 * Livewire's own script endpoint really serves — rather than merely
 * asserting the config value is set, which would prove nothing about
 * whether Livewire's asset mechanism actually honours it.
 */
final class LivewireCspSafeAssetTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_javascript_endpoint_serves_the_csp_safe_bundle_byte_for_byte(): void
    {
        $this->assertTrue(config('livewire.csp_safe'), 'This test is meaningless unless csp_safe is actually on.');

        $minified = ! config('app.debug');
        $cspFile = $minified ? 'livewire.csp.min.js' : 'livewire.csp.js';

        $expected = file_get_contents(base_path("vendor/livewire/livewire/dist/{$cspFile}"));

        // `response()->file()` (`Utils::pretendResponseIsFile()`) returns a
        // `BinaryFileResponse`, which streams rather than buffers — its own
        // `getContent()` returns `false` by design, not the file bytes;
        // `streamedContent()` is Laravel's real helper for this response
        // shape.
        $response = $this->get(EndpointResolver::scriptPath(minified: $minified));

        $response->assertOk();
        $this->assertSame($expected, $response->streamedContent(), 'The script endpoint must serve the CSP-safe bundle byte-for-byte.');
    }

    /**
     * The negative case, so a regression that silently flips `csp_safe`
     * back to `false` (or a Livewire upgrade that changes the default) is
     * caught by this suite failing loudly, not by a real click breaking in
     * production again.
     */
    public function test_the_javascript_endpoint_never_serves_the_regular_eval_using_bundle(): void
    {
        $minified = ! config('app.debug');
        $regularFile = $minified ? 'livewire.min.js' : 'livewire.js';

        $regular = file_get_contents(base_path("vendor/livewire/livewire/dist/{$regularFile}"));

        $response = $this->get(EndpointResolver::scriptPath(minified: $minified));

        $this->assertNotSame($regular, $response->streamedContent());
    }
}

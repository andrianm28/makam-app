<?php

declare(strict_types=1);

namespace Tests\Feature\Correlation;

use App\Http\Middleware\AssignCorrelationId;
use App\Platform\Correlation\CorrelationContext;
use App\Platform\Correlation\CorrelationId;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Proves `AssignCorrelationId` — S3-T10, `platform-audit` design.md line
 * 36 / requirements.md AC10, `platform-outbox` requirements.md AC13.
 *
 * Registers an ad-hoc test route inline (`Route::middleware('web')->get(...)`)
 * rather than adding a permanent test-only route to `routes/web.php`, which
 * this batch does not own. `'web'` alone is enough — `AssignCorrelationId`
 * is already appended to that group in `bootstrap/app.php`, so this exact
 * setup also proves the real wiring, not a hand-assembled substitute
 * middleware stack.
 */
final class AssignCorrelationIdTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Echoes back whatever CorrelationContext holds during THIS
        // request, so tests can assert on both the response header (what
        // the client sees) and the bound context value (what a downstream
        // Audit::record() call would actually read) in one round trip.
        Route::middleware('web')->get('/__test/correlation-id', function () {
            return response()->json([
                'context_value' => app(CorrelationContext::class)->current()?->value,
            ]);
        });
    }

    public function test_no_incoming_header_gets_a_freshly_generated_correlation_id(): void
    {
        $response = $this->get('/__test/correlation-id');

        $response->assertOk();
        $header = $response->headers->get('X-Correlation-Id');

        $this->assertNotNull($header);
        $this->assertNotSame('', $header);
        $this->assertTrue(CorrelationId::isValid($header));

        // The middleware's response header and the value it bound into
        // CorrelationContext for the request must be the exact same id.
        $this->assertSame($header, $response->json('context_value'));
    }

    public function test_a_valid_incoming_header_is_echoed_back_unchanged(): void
    {
        $submitted = 'client-supplied-id-123';

        $response = $this->withHeaders(['X-Correlation-Id' => $submitted])
            ->get('/__test/correlation-id');

        $response->assertOk();
        $this->assertSame($submitted, $response->headers->get('X-Correlation-Id'));
        $this->assertSame($submitted, $response->json('context_value'));
    }

    public function test_an_invalid_incoming_header_is_rejected_and_a_fresh_id_generated_instead(): void
    {
        // Newline (log/header injection) + disallowed markup characters —
        // fails the safe-character allowlist on both counts.
        $malicious = "bad-id\n<script>alert(1)</script>";

        $response = $this->withHeaders(['X-Correlation-Id' => $malicious])
            ->get('/__test/correlation-id');

        $response->assertOk();
        $header = $response->headers->get('X-Correlation-Id');

        $this->assertNotNull($header);
        $this->assertNotSame($malicious, $header);
        $this->assertTrue(CorrelationId::isValid($header));
        $this->assertSame($header, $response->json('context_value'));
    }

    public function test_a_header_value_that_only_fails_via_a_trailing_newline_is_still_rejected(): void
    {
        // Regression guard for the specific PCRE `$`-anchor gap documented
        // on CorrelationId::SAFE_CHARACTERS_PATTERN: without the `D`
        // modifier, `^[A-Za-z0-9_-]{1,128}$` would incorrectly ACCEPT this
        // value, because PCRE's `$` matches immediately before a single
        // trailing "\n" by default.
        $trailingNewline = "valid-looking-id\n";

        $response = $this->withHeaders(['X-Correlation-Id' => $trailingNewline])
            ->get('/__test/correlation-id');

        $response->assertOk();
        $header = $response->headers->get('X-Correlation-Id');

        $this->assertNotSame($trailingNewline, $header);
        $this->assertTrue(CorrelationId::isValid($header));
    }

    public function test_correlation_context_is_request_scoped_two_sequential_requests_get_different_ids(): void
    {
        $first = $this->get('/__test/correlation-id')->headers->get('X-Correlation-Id');
        $second = $this->get('/__test/correlation-id')->headers->get('X-Correlation-Id');

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertNotSame(
            $first,
            $second,
            'CorrelationContext must not leak a value from one request into the next.'
        );
    }

    public function test_middleware_is_registered_on_the_web_group(): void
    {
        // Locks in bootstrap/app.php's wiring rather than only the
        // behavior — a future edit that accidentally removes the
        // appendToGroup() call would still pass every behavioral test
        // above IF the ad-hoc route re-added the middleware explicitly,
        // which it deliberately does not (see class-level note). This
        // test checks the actual `web` group contents directly instead.
        $group = app('router')->getMiddlewareGroups()['web'] ?? [];

        $this->assertContains(AssignCorrelationId::class, $group);
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Observability;

use App\Platform\DocumentVault\Actions\IssueSignedUrl;
use App\Platform\DocumentVault\DocumentAccessPurpose;
use App\Platform\DocumentVault\DocumentState;
use App\Platform\DocumentVault\Models\Document;
use App\Platform\DocumentVault\Policies\DocumentAccessPolicy;
use App\Platform\IdentityAccess\ActorContext;
use App\Platform\IdentityAccess\Scopes\Models\ScopeAssignment;
use App\Platform\IdentityAccess\Scopes\ScopeAssignmentResolver;
use App\Platform\IdentityAccess\Scopes\ScopeEntityType;
use App\Platform\Observability\SentryEventScrubber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Sentry\Event;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;
use Tests\TestCase;

/**
 * Regression coverage for the 5 Sep 2026 fix (see SentryEventScrubber's own
 * class doc block): before this fix, `scrub()` only ever read
 * `$event->getMessage()`, which the real report()/captureException() path
 * never populates — every real captured exception's message, stack-frame
 * vars, and request url/query_string reached Sentry unscrubbed. This file
 * did not exist before that fix; there was zero coverage for this class.
 *
 * ---------------------------------------------------------------------------
 * OBS-02 (7 Sep 2026, security) — this class now uses RefreshDatabase and
 * mints a REAL grant via `IssueSignedUrl`
 * ---------------------------------------------------------------------------
 * The test this replaced hand-typed a fixture URL
 * (`https://makam.co.id/vault/abc123?expires=...&signature=...`) shaped to
 * match the OLD (broken) regex — it could only ever prove the regex matches
 * itself, not that it matches anything the application actually produces.
 * That fixture's `/vault/...` shape does not exist anywhere in
 * `routes/web.php`; the real route is
 * `/internal/documents/{uuid}/download/{token}`
 * (`IssueSignedUrl::temporaryUrl()`), with the token as the LAST PATH
 * SEGMENT rather than a query parameter. `test_it_scrubs_a_real_issued_signed_url_out_of_an_exception_message()`
 * below constructs the URL the exact way production code does — through
 * `IssueSignedUrl::issue()` and `temporaryUrl()` — so it can only pass if
 * the scrubber's pattern matches what the app for real generates.
 */
final class SentryEventScrubberTest extends TestCase
{
    use RefreshDatabase;

    private const NIK = '3273010101990001';

    public function test_it_scrubs_the_nik_out_of_a_real_exceptions_message(): void
    {
        $event = Event::createEvent();
        $exception = new ExceptionDataBag(
            new \RuntimeException('Gagal memproses KTP NIK '.self::NIK),
        );
        $event->setExceptions([$exception]);

        SentryEventScrubber::scrub($event);

        $scrubbed = $event->getExceptions()[0]->getValue();

        $this->assertStringNotContainsString(self::NIK, $scrubbed);
        $this->assertStringContainsString('[REDACTED-NIK-KK]', $scrubbed);
    }

    /**
     * THE security-critical proof for OBS-02: a real, live, valid download
     * token minted the exact way `IssueSignedUrl` mints one in production,
     * embedded in an exception message the way a real error log line would
     * ("failed to stream <url>"), must not reach Sentry with the token
     * intact.
     */
    public function test_it_scrubs_a_real_issued_signed_url_out_of_an_exception_message(): void
    {
        $document = $this->relatedDocument();
        $grant = (new IssueSignedUrl(new DocumentAccessPolicy(new ScopeAssignmentResolver(ActorContext::guest()))))
            ->issue($this->relatedActor(), $document, DocumentAccessPurpose::Download);

        $realSignedUrl = (new IssueSignedUrl(new DocumentAccessPolicy(new ScopeAssignmentResolver(ActorContext::guest()))))
            ->temporaryUrl($grant);

        // Sanity check on the fixture itself, not the scrubber: prove this
        // really is a live, well-formed signed URL with a 64-hex-character
        // token, matching production's `bin2hex(random_bytes(32))` shape —
        // otherwise this test could pass for the wrong reason.
        $this->assertMatchesRegularExpression(
            '#^https?://[^/]+/internal/documents/'.$document->getKey().'/download/[0-9a-f]{64}$#',
            $realSignedUrl,
        );

        $event = Event::createEvent();
        $exception = new ExceptionDataBag(
            new \RuntimeException('Gagal mengunduh dokumen via '.$realSignedUrl),
        );
        $event->setExceptions([$exception]);

        SentryEventScrubber::scrub($event);

        $scrubbed = $event->getExceptions()[0]->getValue();

        $this->assertStringNotContainsString($grant->token, $scrubbed);
        $this->assertStringContainsString('[REDACTED-TOKEN]', $scrubbed);
        // The document id is a UUID, not a secret — it stays visible for
        // triage, so only the token segment is redacted.
        $this->assertStringContainsString($document->getKey(), $scrubbed);
        $this->assertStringContainsString('/internal/documents/', $scrubbed);
        $this->assertStringContainsString('/download/', $scrubbed);
    }

    /**
     * Defense-in-depth: a generic `signature=`/`expires=` query parameter
     * scrub for any FUTURE `URL::temporarySignedRoute()` use elsewhere in
     * the app — this codebase's one real signed-URL route today carries no
     * query string at all (see the test above), so this is deliberately a
     * synthetic shape, not a real route.
     */
    public function test_it_scrubs_generic_signature_and_expires_query_parameters(): void
    {
        $event = Event::createEvent();
        $event->setMessage('GET https://makam.co.id/some/future/route?foo=bar&signature=deadbeefcafebabe&expires=1893456000');

        SentryEventScrubber::scrub($event);

        $message = (string) $event->getMessage();

        $this->assertStringNotContainsString('deadbeefcafebabe', $message);
        $this->assertStringNotContainsString('1893456000', $message);
        $this->assertStringContainsString('foo=bar', $message);
        $this->assertStringContainsString('signature=[REDACTED-TOKEN]', $message);
        $this->assertStringContainsString('expires=[REDACTED-TOKEN]', $message);
    }

    private function relatedActor(): ActorContext
    {
        return new ActorContext(identityReference: 42, roles: ['operator']);
    }

    private function relatedDocument(): Document
    {
        $documentId = (string) Str::uuid();
        $ownerId = 'order-'.Str::random(8);

        DB::table('documents')->insert([
            'id' => $documentId,
            'document_kind' => 'DEATH_CERTIFICATE',
            'state' => DocumentState::Accepted->value,
            'owner_type' => ScopeEntityType::ORDER,
            'owner_id' => $ownerId,
            'original_filename' => 'akta-kematian.pdf',
            'storage_prefix' => 'accepted',
            'storage_key' => 'opaque-key-'.Str::random(8),
            'size_bytes' => 1024,
            'mime_declared' => 'application/pdf',
            'scanner_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        ScopeAssignment::query()->create([
            'actor_identifier' => '42',
            'entity_type' => ScopeEntityType::ORDER,
            'entity_id' => $ownerId,
        ]);

        return Document::query()->findOrFail($documentId);
    }

    public function test_it_scrubs_a_nik_out_of_a_stack_frames_local_variable_including_nested_arrays(): void
    {
        $frame = new Frame('handle', '/app/Jobs/ProcessKtp.php', 42, vars: [
            'nik' => self::NIK,
            'payload' => ['nested' => self::NIK, 'safe' => 'ok'],
        ]);
        $stacktrace = new Stacktrace([$frame]);
        $exception = new ExceptionDataBag(new \RuntimeException('boom'), $stacktrace);

        $event = Event::createEvent();
        $event->setExceptions([$exception]);

        SentryEventScrubber::scrub($event);

        $vars = $event->getExceptions()[0]->getStacktrace()->getFrame(0)->getVars();

        $this->assertSame('[REDACTED-NIK-KK]', $vars['nik']);
        $this->assertSame('[REDACTED-NIK-KK]', $vars['payload']['nested']);
        $this->assertSame('ok', $vars['payload']['safe']);
    }

    /**
     * `Sentry\Integration\RequestIntegration::processEvent()` sets `url`/
     * `query_string` unconditionally (not gated by `shouldSendDefaultPii()`)
     * before `before_send` runs — reproduced here by injecting the same
     * shape of request array it produces, rather than exercising the real
     * PSR-7 integration.
     */
    public function test_it_scrubs_the_request_url_and_query_string(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'url' => 'https://makam.co.id/riwayat-perawatan/'.self::NIK,
            'query_string' => 'ref='.self::NIK,
            'method' => 'GET',
        ]);

        SentryEventScrubber::scrub($event);

        $request = $event->getRequest();

        $this->assertStringNotContainsString(self::NIK, $request['url']);
        $this->assertStringNotContainsString(self::NIK, $request['query_string']);
        $this->assertSame('GET', $request['method']);
    }

    public function test_it_still_scrubs_a_directly_set_message_the_original_behavior(): void
    {
        $event = Event::createEvent();
        $event->setMessage('NIK '.self::NIK.' failed');

        SentryEventScrubber::scrub($event);

        $this->assertStringNotContainsString(self::NIK, (string) $event->getMessage());
    }
}

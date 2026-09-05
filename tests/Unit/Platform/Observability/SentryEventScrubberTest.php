<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Observability;

use App\Platform\Observability\SentryEventScrubber;
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
 */
final class SentryEventScrubberTest extends TestCase
{
    private const NIK = '3273010101990001';

    private const SIGNED_VAULT_URL = 'https://makam.co.id/vault/abc123?expires=1893456000&signature=deadbeefcafebabe';

    public function test_it_scrubs_the_nik_and_signed_url_out_of_a_real_exceptions_message(): void
    {
        $event = Event::createEvent();
        $exception = new ExceptionDataBag(
            new \RuntimeException('Gagal memproses KTP NIK '.self::NIK.' via '.self::SIGNED_VAULT_URL),
        );
        $event->setExceptions([$exception]);

        SentryEventScrubber::scrub($event);

        $scrubbed = $event->getExceptions()[0]->getValue();

        $this->assertStringNotContainsString(self::NIK, $scrubbed);
        $this->assertStringNotContainsString('signature=deadbeefcafebabe', $scrubbed);
        $this->assertStringContainsString('[REDACTED-NIK-KK]', $scrubbed);
        $this->assertStringContainsString('[REDACTED-SIGNATURE]', $scrubbed);
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

<?php

declare(strict_types=1);

namespace Tests\Feature\Observability;

use App\Platform\Observability\SentryEventScrubber;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Sentry\Event as SentryEvent;
use Sentry\ExceptionDataBag;
use Tests\TestCase;

/**
 * OBS-07 (PII leak, real urgency): `Illuminate\Database\QueryException`
 * interpolates every SQL binding into its own `->getMessage()`
 * (`formatMessage()`: `$previous->getMessage() . ' (Connection: ..., SQL: '
 * . Str::replaceArray('?', $bindings, $sql) . ')'`), and the PostgreSQL PDO
 * driver independently echoes a real value into its OWN error text on a
 * unique-constraint violation (a `DETAIL:  Key (...)=(...) already exists.`
 * line). A failed `booking_drafts`-shaped write carrying a deceased
 * person's name would put that name into the application log AND the error
 * tracker verbatim.
 *
 * This test triggers a REAL `QueryException` against the disposable
 * PostgreSQL connection (a genuine unique-constraint/primary-key
 * violation, not a mock), with a distinctive fake "deceased name" value in
 * the failed insert, and proves:
 *
 *  1. The raw, unredacted exception really does leak the value (a sanity
 *     check on the reproduction itself — this test must be capable of
 *     failing before asserting it passes after the fix).
 *  2. `SentryEventScrubber::redactQueryExceptionMessages()` — the single
 *     fix point wired into `bootstrap/app.php` as an early `reportable()`
 *     callback — removes it from the exception's OWN `getMessage()`.
 *  3. The real Laravel exception `report()` pipeline (which both the
 *     default log write and Sentry's own capture read from) never sees the
 *     value: captured here via the `MessageLogged` event, which is Laravel's
 *     own notification that a log line was about to be written, and via a
 *     `Sentry\Event` built the same way `sentry-laravel` builds one, fed
 *     through `SentryEventScrubber::scrub()`.
 */
final class QueryExceptionRedactionTest extends TestCase
{
    use RefreshDatabase;

    private const string FAKE_DECEASED_NAME = 'Almarhum Uji Kebocoran Data XZQ-77219';

    public function test_a_real_postgres_unique_violation_leaks_the_value_before_redaction(): void
    {
        $this->skipUnlessPostgres();

        $exception = $this->triggerRealDuplicateKeyViolation();

        // Sanity check on the REPRODUCTION, not the fix: prove the raw
        // exception really does carry the value, so this test is capable of
        // catching a regression rather than trivially passing either way.
        $this->assertStringContainsString(self::FAKE_DECEASED_NAME, $exception->getMessage());
    }

    public function test_redact_query_exception_messages_removes_the_value_from_the_exceptions_own_message(): void
    {
        $this->skipUnlessPostgres();

        $exception = $this->triggerRealDuplicateKeyViolation();

        SentryEventScrubber::redactQueryExceptionMessages($exception);

        $this->assertStringNotContainsString(self::FAKE_DECEASED_NAME, $exception->getMessage());
        $this->assertStringContainsString('SQLSTATE', $exception->getMessage());
        $this->assertStringContainsString('binding value(s) redacted', $exception->getMessage());
    }

    /**
     * The LOG sink: exercises the real `bootstrap/app.php`-wired
     * `Handler::report()` pipeline via `MessageLogged`, Laravel's own event
     * fired immediately before a log line is written — the exact string
     * that would have reached disk/stdout.
     */
    public function test_the_log_sink_never_receives_the_leaked_value(): void
    {
        $this->skipUnlessPostgres();

        $exception = $this->triggerRealDuplicateKeyViolation();

        $loggedMessages = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$loggedMessages): void {
            $loggedMessages[] = $event->message;
        });

        app(ExceptionHandler::class)->report($exception);

        $this->assertNotEmpty($loggedMessages, 'The report() pipeline must actually log something for this test to mean anything.');

        foreach ($loggedMessages as $message) {
            $this->assertStringNotContainsString(
                self::FAKE_DECEASED_NAME,
                $message,
                'The fake deceased name reached the log sink unredacted.',
            );
        }
    }

    /**
     * The SENTRY sink: builds a `Sentry\Event` the same way `sentry-laravel`
     * builds one from a reported exception (an `ExceptionDataBag` wrapping
     * the SAME exception instance `report()` above already redacted via the
     * `reportable()` hook wired in `bootstrap/app.php`), then runs it
     * through the real `before_send` scrubber.
     */
    public function test_the_sentry_payload_never_receives_the_leaked_value(): void
    {
        $this->skipUnlessPostgres();

        $exception = $this->triggerRealDuplicateKeyViolation();

        // Simulate the real pipeline: report() runs our reportable() hook
        // (registered in bootstrap/app.php) before Sentry ever builds its
        // own event data.
        app(ExceptionHandler::class)->report($exception);

        $sentryEvent = SentryEvent::createEvent();
        $sentryEvent->setExceptions([new ExceptionDataBag($exception)]);

        SentryEventScrubber::scrub($sentryEvent);

        $scrubbedValue = $sentryEvent->getExceptions()[0]->getValue();

        $this->assertStringNotContainsString(self::FAKE_DECEASED_NAME, $scrubbedValue);
    }

    /**
     * The counterpart every redaction test needs: the SQL SHAPE (with
     * placeholders, never the interpolated values) survives, so a human
     * reading the log/Sentry entry can still tell which statement failed.
     */
    public function test_the_redacted_message_still_names_the_failing_table(): void
    {
        $this->skipUnlessPostgres();

        $exception = $this->triggerRealDuplicateKeyViolation();

        SentryEventScrubber::redactQueryExceptionMessages($exception);

        $this->assertStringContainsString('documents', $exception->getMessage());
    }

    private function triggerRealDuplicateKeyViolation(): QueryException
    {
        $documentId = (string) Str::uuid();

        $row = [
            'id' => $documentId,
            'document_kind' => 'DEATH_CERTIFICATE',
            'state' => 'ACCEPTED',
            'owner_type' => 'booking_draft',
            'owner_id' => 'draft-obs07',
            'original_filename' => self::FAKE_DECEASED_NAME.'.pdf',
            'storage_prefix' => 'accepted',
            'storage_key' => 'opaque-key-'.Str::random(8),
            'size_bytes' => 1024,
            'mime_declared' => 'application/pdf',
            'scanner_required' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        DB::table('documents')->insert($row);

        try {
            // Same primary key twice — a genuine 23505 unique_violation,
            // with the fake deceased name as one of the bound values on the
            // FAILING statement.
            DB::table('documents')->insert($row);
        } catch (QueryException $exception) {
            return $exception;
        }

        $this->fail('Expected a duplicate-key QueryException but the second insert succeeded.');
    }

    private function skipUnlessPostgres(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped(
                'This reproduction depends on real PostgreSQL unique-violation error text; run with DB_CONNECTION=pgsql.'
            );
        }
    }
}

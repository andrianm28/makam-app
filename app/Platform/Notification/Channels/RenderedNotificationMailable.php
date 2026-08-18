<?php

declare(strict_types=1);

namespace App\Platform\Notification\Channels;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Carries `MailChannel`'s already-rendered subject/body — no Blade view,
 * because there is none to render against: `TemplateRenderer::render()`
 * already produced final plain text (D6, every seeded template version has
 * an empty `variable_allowlist`).
 *
 * A real `Mailable`, deliberately, rather than `Mail::raw()`: `MailFake::
 * raw()` is a documented no-op (vendor/laravel/framework/.../MailFake.php)
 * — it records nothing, so `Mail::fake()` + `Mail::assertSent()` cannot see
 * a `Mail::raw()` call at all. A `Mail::raw()`-based channel would be
 * silently untestable AND silently unverifiable in any environment where
 * `Mail::fake()` was left active by mistake. `Content::htmlString()` is
 * pre-rendered HTML with no view file required — the plain-text body is
 * escaped and newline-converted rather than passed through raw, since it
 * is now HTML content.
 */
final class RenderedNotificationMailable extends Mailable
{
    public function __construct(
        private readonly string $renderedSubject,
        private readonly string $renderedBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->renderedSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: nl2br(e($this->renderedBody), false));
    }
}

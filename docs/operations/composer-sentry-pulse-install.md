# Installing Sentry + Pulse (prepared, not executed)

## Status

**Prepared, not executed.** `composer.json` has been updated to require `sentry/sentry-laravel`
and `laravel/pulse`, and all consuming config (`config/sentry.php`, `config/pulse.php`) and
exception-handler wiring (`bootstrap/app.php`) are written and committed. `composer.lock` has
deliberately NOT been touched — regenerating it needs real `composer` + network access, which
this repo's `CLAUDE.md` forbids running on this host.

## What a human runs

```bash
composer update laravel/pulse sentry/sentry-laravel --with-all-dependencies
php artisan vendor:publish --tag=pulse-migrations
php artisan migrate
```

Then provision the real `SENTRY_LARAVEL_DSN` value directly on the host (in `.env.dev`/`.env.stg`,
never in chat or committed to this repo — same "real secrets live in `secrets/*.txt` on the host"
discipline this repo already follows for database credentials).

## Verify after installing

```bash
php artisan test tests/  # confirm nothing broke
php artisan pulse:check  # if this command exists in the installed version — verify
```

Visit `/pulse` as an admin user and confirm the dashboard loads (per `config/pulse.php`'s
`authorize` callback — an admin session should see it, anything else should be denied).
Trigger a deliberate test exception and confirm it reaches the configured Sentry project with the
`correlation_id` and `image_digest` tags attached, and that no NIK/KK-shaped digit sequence or
signed-URL query string appears in the captured event.

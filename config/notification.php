<?php

declare(strict_types=1);

use App\Platform\Notification\Channels\LogChannel;

/**
 * `notification.channel` — the `Contracts\Channel` implementation
 * `Providers\NotificationServiceProvider` binds. Same shape as
 * `config/document-vault.php`'s `object_storage`/`malware_scanner` keys: a
 * raw, env-overridable class-string default rather than a boolean flag, so
 * a future second real channel needs no new key.
 *
 * Defaults to `LogChannel` — `SENT` there means only "written to the
 * development log," never a claim that an email was actually sent
 * (`AGENTS.md` §Notifications: "Do not claim WhatsApp/email delivery
 * without delivery state"). Every environment that has NOT explicitly set
 * `NOTIFICATION_CHANNEL` — including CI and dev — keeps this default
 * unchanged; only an environment with a real, warmed-up sending domain
 * (the public beta) sets it to `Channels\MailChannel`.
 */
return [
    'channel' => env('NOTIFICATION_CHANNEL', LogChannel::class),
];

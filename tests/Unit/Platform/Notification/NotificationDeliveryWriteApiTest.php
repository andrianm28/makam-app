<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use App\Platform\Notification\Actions\DispatchNotification;
use App\Platform\Notification\Jobs\SendNotificationChannelJob;
use Illuminate\Support\Facades\File;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AC9, by construction: `App\Platform\Notification\Actions\
 * DispatchNotification` must be the ONLY class that writes a row to
 * `notification_deliveries`. This is a structural/grep-style proof, not a
 * behavioural one — it scans every PHP file under `app/` for the write
 * patterns `NotificationDelivery::create()`/`::insert()`/
 * `::query()->create()`/`::query()->insert()` and
 * `DB::table('notification_deliveries')->insert*()`/`->update()`/
 * `->upsert()`, and fails if any of them appear outside `Actions\
 * DispatchNotification` itself.
 */
final class NotificationDeliveryWriteApiTest extends TestCase
{
    private const string ALLOWED_WRITER = 'Platform/Notification/Actions/DispatchNotification.php';

    public function test_only_dispatch_notification_writes_notification_deliveries(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path()) as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            if ($relativePath === self::ALLOWED_WRITER) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if ($contents === false) {
                continue;
            }

            if (preg_match('/NotificationDelivery::(create\s*\(|insert\s*\(|query\(\)\s*->\s*(create|insert)\s*\()/', $contents) === 1) {
                $offenders[] = $relativePath.' (Eloquent write)';
            }

            if (preg_match(
                '/DB::table\(\s*[\'"]notification_deliveries[\'"]\s*\)\s*->[^;]*?->\s*(insert|insertGetId|insertOrIgnore|update|upsert|delete)\s*\(/s',
                $contents
            ) === 1) {
                $offenders[] = $relativePath.' (query builder write)';
            }
        }

        $this->assertSame(
            [],
            $offenders,
            'Only '.self::ALLOWED_WRITER.' may write notification_deliveries rows (AC9).'
        );
    }

    public function test_channel_send_boundary_requires_the_channel_job(): void
    {
        $method = new ReflectionMethod(DispatchNotification::class, 'claimDeliveryForChannelJob');
        $parameters = $method->getParameters();

        $this->assertSame(SendNotificationChannelJob::class, (string) $parameters[0]->getType());
    }

    /**
     * "Only `SendNotificationChannelJob` may call `Channel::send()`" — a
     * bare `->send\s*\(` regex cannot tell that call apart from a real
     * `Channel` implementation's OWN, entirely legitimate call to ITS
     * provider's `send()` method (`Channels\MailChannel`'s `Mail::to($email)
     * ->send(...)`, added alongside this exemption — the first real channel
     * this scan ever had to consider; `LogChannel`/`NullChannel` call no
     * provider at all). That is a different `->send(` from the one AC9
     * guards against, so a file implementing `Contracts\Channel` is
     * exempted from this scan the same way `SendNotificationChannelJob`
     * itself is — this test's job is catching something ELSE calling
     * `Channel::send()` directly, not policing what a channel does inside
     * its own implementation.
     *
     * @var list<string>
     */
    private const array CHANNEL_IMPLEMENTATIONS = [
        'Jobs/SendNotificationChannelJob.php',
        'Channels/LogChannel.php',
        'Channels/NullChannel.php',
        'Channels/MailChannel.php',
    ];

    public function test_channel_send_is_called_only_by_the_channel_job(): void
    {
        $offenders = [];

        foreach (File::allFiles(app_path('Platform/Notification')) as $file) {
            $relativePath = str_replace('\\', '/', $file->getRelativePathname());

            if (in_array($relativePath, self::CHANNEL_IMPLEMENTATIONS, true)) {
                continue;
            }

            $contents = file_get_contents($file->getPathname());

            if ($contents !== false && preg_match('/->send\s*\(/', $contents) === 1) {
                $offenders[] = $relativePath;
            }
        }

        $this->assertSame([], $offenders, 'Provider sends must only occur inside SendNotificationChannelJob or a Channel implementation.');
    }
}

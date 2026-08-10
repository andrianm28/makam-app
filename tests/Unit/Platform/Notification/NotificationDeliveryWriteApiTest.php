<?php

declare(strict_types=1);

namespace Tests\Unit\Platform\Notification;

use Illuminate\Support\Facades\File;
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
}

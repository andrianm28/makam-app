<?php

declare(strict_types=1);

namespace App\Platform\Notification\Exceptions;

use RuntimeException;

/**
 * Thrown by `NotificationDeliveryWriteGuard` when a SQL statement writes to
 * `notification_deliveries` from outside
 * `Actions\DispatchNotification`'s own guarded write sites — AC9's "one
 * write API" made a runtime-enforced invariant, not merely a documented
 * convention (fix round 1, IMPORTANT 1: the prior regex-based test could
 * not actually catch this).
 */
final class NotificationDeliveryWriteNotAllowedException extends RuntimeException
{
    public static function forSql(string $sql): self
    {
        return new self(
            'A write to notification_deliveries was attempted outside '.
            'App\\Platform\\Notification\\Actions\\DispatchNotification, the only '.
            'class authorised to write this table (AC9). Offending SQL: '.$sql
        );
    }

    public static function forScope(): self
    {
        return new self(
            'The notification delivery write scope may only be opened by '.
            'App\\Platform\\Notification\\Actions\\DispatchNotification (AC9).'
        );
    }
}

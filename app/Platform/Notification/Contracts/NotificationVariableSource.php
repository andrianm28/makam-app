<?php

declare(strict_types=1);

namespace App\Platform\Notification\Contracts;

/**
 * Supplies template variables for one render. Implementations live in the
 * platform (payload pass-through) or in a feature module (e.g. OrderWorkflow
 * for the `order` aggregate) and are registered on
 * `App\Platform\Notification\NotificationVariableResolver`; the platform
 * never imports a Domain model to get at a value (app/Platform/README.md).
 *
 * Values must be scalar, Stringable, or null — `TemplateRenderer` rejects
 * anything else. Never return restricted data (AC11); the renderer's
 * `restricted_fields` check is the last line of defence, not the first.
 */
interface NotificationVariableSource
{
    public function handles(string $aggregateType): bool;

    /**
     * @param  array<string, mixed>  $payload  The outbox row's payload (envelope or bare data)
     * @return array<string, scalar|\Stringable|null>
     */
    public function variablesFor(string $matrixEventName, string $aggregateType, string $aggregateId, array $payload): array;
}

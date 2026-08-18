<?php

declare(strict_types=1);

namespace App\Platform\Observability;

/**
 * `ReadinessCheck::run()`'s outcome — a plain value object so
 * `Http\Controllers\Health\HealthReadyController` and any future consumer
 * (the spine watchdog does NOT use this; readiness is "can this instance
 * serve a request," which is a different question from "is the async
 * pipeline draining") read the same two named facts rather than an
 * untyped array.
 */
final readonly class ReadinessResult
{
    public function __construct(
        public bool $database,
        public bool $redis,
    ) {}

    public function isReady(): bool
    {
        return $this->database && $this->redis;
    }

    /**
     * @return array{database: bool, redis: bool}
     */
    public function toArray(): array
    {
        return [
            'database' => $this->database,
            'redis' => $this->redis,
        ];
    }
}

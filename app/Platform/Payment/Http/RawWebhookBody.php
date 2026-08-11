<?php

declare(strict_types=1);

namespace App\Platform\Payment\Http;

/**
 * Exact request bytes kept outside the Request object until signature
 * verification and durable persistence have consumed them.
 */
final readonly class RawWebhookBody
{
    public function __construct(private string $body) {}

    public function value(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['body' => '[REDACTED]'];
    }

    public function __toString(): string
    {
        return '[REDACTED]';
    }

    /**
     * @return array<string, string>
     */
    public function __serialize(): array
    {
        return ['body' => '[REDACTED]'];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new \LogicException('RawWebhookBody must not be unserialized.');
    }
}

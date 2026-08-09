<?php

declare(strict_types=1);

namespace App\Platform\Notification;

use App\Platform\Notification\Models\NotificationTemplateVersion;
use InvalidArgumentException;
use Stringable;

/**
 * Renders a versioned notification using `{{ variable }}` placeholders.
 * Every referenced or supplied variable must be allowlisted, and restricted
 * fields win over the allowlist so a privacy classification cannot be opened
 * accidentally by a template edit.
 */
final class TemplateRenderer
{
    private const PLACEHOLDER_PATTERN = '/\{\{\s*([A-Za-z_][A-Za-z0-9_.-]*)\s*\}\}/';

    /**
     * @param  array<string, mixed>  $variables
     * @return array{subject: string|null, body: string}
     */
    public function render(NotificationTemplateVersion $version, array $variables): array
    {
        $allowlist = $version->variable_allowlist ?? [];
        $restrictedFields = $version->restricted_fields ?? [];
        $templates = array_filter([$version->subject, $version->body], static fn (?string $value): bool => $value !== null);
        $referenced = [];

        foreach ($templates as $template) {
            preg_match_all(self::PLACEHOLDER_PATTERN, $template, $matches);
            $referenced = array_merge($referenced, $matches[1]);
        }

        $names = array_values(array_unique(array_merge($referenced, array_keys($variables))));

        foreach ($names as $name) {
            if ($this->isRestricted($name, $restrictedFields)) {
                throw new InvalidArgumentException("Restricted notification variable [{$name}] cannot be rendered.");
            }

            if (! in_array($name, $allowlist, true)) {
                throw new InvalidArgumentException("Notification variable [{$name}] is not allowlisted.");
            }

            if (in_array($name, $referenced, true) && ! array_key_exists($name, $variables)) {
                throw new InvalidArgumentException("Notification variable [{$name}] was not provided.");
            }

            if (array_key_exists($name, $variables) && ! is_scalar($variables[$name]) && ! $variables[$name] instanceof Stringable && $variables[$name] !== null) {
                throw new InvalidArgumentException("Notification variable [{$name}] must be scalar or stringable.");
            }
        }

        return [
            'subject' => $this->replace($version->subject, $variables),
            'body' => $this->replace($version->body, $variables),
        ];
    }

    /**
     * @param  list<string>  $restrictedFields
     */
    private function isRestricted(string $name, array $restrictedFields): bool
    {
        $normalizedName = $this->normalize($name);

        foreach ($restrictedFields as $field) {
            if ($normalizedName === $this->normalize((string) $field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function replace(?string $template, array $variables): ?string
    {
        if ($template === null) {
            return null;
        }

        return preg_replace_callback(
            self::PLACEHOLDER_PATTERN,
            static fn (array $match): string => (string) $variables[$match[1]],
            $template,
        );
    }

    private function normalize(string $name): string
    {
        return strtolower(str_replace([' ', '-'], '_', trim($name)));
    }
}

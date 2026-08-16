<?php

declare(strict_types=1);

namespace App\Platform\SiteSettings;

use App\Platform\SiteSettings\Models\SiteSetting;
use Illuminate\Support\Str;

/**
 * Resolves a site setting through a fixed precedence, from most to least
 * authoritative:
 *
 *   1. `config("site.$key")`          — code-level override (tests, ops)
 *   2. `env(SNAKE_UPPERCASE_KEY)`     — environment override
 *   3. `site_settings` table row      — admin-managed value (SiteSettingsResource)
 *   4. `$default`                     — caller-supplied fallback
 *
 * The environment read uses the UPPERCASED snaked key: `service_hours`
 * becomes `SERVICE_HOURS` (`Str::upper(Str::snake($key))`). This mirrors the
 * `PAYMENT_MERCHANT_REF` / `MARKETPLACE_BADAN_USAHA_REF` naming the config
 * files already establish, so an operator can override a setting without a
 * config file change.
 *
 * The `env()` call below is the deliberate exception to
 * `config/payment.php`'s "only the config directory may read env" rule, and
 * larastan's `noEnvCallsOutsideOfConfig` is ignored there for the reasons
 * this precedence demands: the env layer is the dev/staging override the P2
 * plan pins in tests (`SettingsServiceTest`), and under production
 * `config:cache` `env()` returns null, which falls through to the
 * `site_settings` row — the production-managed value — exactly as intended.
 * A setting's env fallback can never leak a credential: none of the
 * `SiteSetting::KNOWN_KEYS` values are secret material (the resource's
 * payment section documents the same distinction, "Identitas non-rahasia
 * (FIN-DEC). Kredensial tetap di lingkungan (env)").
 *
 * Registered as a singleton (`SiteSettingsServiceProvider`) so the
 * `site_settings` read happens at most once per request — `$values` caches
 * the full key/value map after the first miss.
 */
final class SettingsService
{
    /** @var array<string, mixed>|null */
    private ?array $values = null;

    public function setting(string $key, mixed $default = null): mixed
    {
        $configured = config("site.{$key}");

        if ($configured !== null) {
            return $configured;
        }

        $envKey = Str::upper(Str::snake($key));
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        $envValue = env($envKey);

        if ($envValue !== null && $envValue !== '') {
            return $envValue;
        }

        $this->values ??= SiteSetting::query()->pluck('value', 'key')->all();

        return array_key_exists($key, $this->values) && $this->values[$key] !== ''
            ? $this->values[$key]
            : $default;
    }
}

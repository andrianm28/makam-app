<?php

declare(strict_types=1);

namespace App\Domain\CemeteryCapability;

use InvalidArgumentException;

/**
 * The closed list of `cemetery_capability_profiles.certificate_mode`
 * values — one of the six modes `docs/architecture/overview.md` §6 names.
 * Not part of `docs/contracts/openapi.yaml`'s `PublicCapabilityProfile`
 * schema — same reasoning as `RegistryMode`'s own doc block.
 *
 * Plain string column with application-layer validation, not a Postgres
 * enum type — same established convention as every other closed-list
 * string column in this codebase.
 */
final class CertificateMode
{
    /**
     * No certificate is issued through the platform for this cemetery.
     * The safe default.
     */
    public const string NONE = 'NONE';

    /**
     * Certificates exist but are handled manually/off-platform.
     */
    public const string MANUAL = 'MANUAL';

    /**
     * Certificates are issued and managed by the platform
     * (`AgreementCertificate` module, not owned by this spec). Never set
     * by this batch's seed data.
     */
    public const string PLATFORM_MANAGED = 'PLATFORM_MANAGED';

    public const string DEFAULT = self::NONE;

    /**
     * @var list<string>
     */
    public const array KNOWN_MODES = [
        self::NONE,
        self::MANUAL,
        self::PLATFORM_MANAGED,
    ];

    public static function isKnown(string $mode): bool
    {
        return in_array($mode, self::KNOWN_MODES, true);
    }

    /**
     * @throws InvalidArgumentException when `$mode` is not one of
     *                                  `self::KNOWN_MODES`.
     */
    public static function assertKnown(string $mode): void
    {
        if (! self::isKnown($mode)) {
            throw new InvalidArgumentException(
                "Unknown certificate mode [{$mode}]. Known modes: ".implode(', ', self::KNOWN_MODES).'.'
            );
        }
    }
}
